"""
Database module for mood history.
Uses SQLite for simplicity (shared with the mood service).
Stores mood entries with soft-delete support.
"""

import os
import sqlite3
import logging
from datetime import datetime, timezone

logger = logging.getLogger("mood-service")

DB_PATH = os.path.join(os.path.dirname(os.path.dirname(__file__)), "data", "mood_history.db")


def get_db() -> sqlite3.Connection:
    """Get database connection."""
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    return conn


def init_db():
    """Initialize database schema."""
    conn = get_db()
    conn.executescript("""
        CREATE TABLE IF NOT EXISTS mood_entries (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            mood TEXT NOT NULL CHECK(mood IN ('Happy', 'Neutral', 'Low', 'Angry')),
            confidence REAL NOT NULL DEFAULT 0.0,
            source TEXT NOT NULL DEFAULT 'camera' CHECK(source IN ('camera', 'manual')),
            all_scores TEXT,
            original_mood TEXT DEFAULT NULL,
            is_corrected INTEGER DEFAULT 0,
            detected_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            deleted_at TEXT DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE INDEX IF NOT EXISTS idx_audit_user
            ON audit_log(user_id, created_at DESC);

        CREATE INDEX IF NOT EXISTS idx_mood_entries_user_id
            ON mood_entries(user_id, detected_at DESC);

        CREATE INDEX IF NOT EXISTS idx_mood_entries_user_mood
            ON mood_entries(user_id, mood);

        CREATE INDEX IF NOT EXISTS idx_mood_entries_detected_date
            ON mood_entries(date(detected_at));

        CREATE UNIQUE INDEX IF NOT EXISTS idx_mood_entries_idempotent
            ON mood_entries(user_id, detected_at) WHERE deleted_at IS NULL;
    """)
    conn.commit()
    conn.close()
    logger.info("Mood history database initialized.")


def create_entry(entry: dict) -> dict:
    """Create a single mood entry."""
    conn = get_db()
    try:
        conn.execute(
            """INSERT OR IGNORE INTO mood_entries
               (id, user_id, mood, confidence, source, all_scores, detected_at, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
            (
                entry["id"],
                entry["user_id"],
                entry["mood"],
                entry["confidence"],
                entry.get("source", "camera"),
                entry.get("all_scores"),
                entry["detected_at"],
                datetime.now(timezone.utc).isoformat(),
            ),
        )
        conn.commit()

        row = conn.execute(
            "SELECT * FROM mood_entries WHERE id = ?", (entry["id"],)
        ).fetchone()

        return dict(row) if row else entry
    finally:
        conn.close()


def create_batch(entries: list) -> dict:
    """Create multiple mood entries (idempotent)."""
    conn = get_db()
    inserted = 0
    skipped = 0

    try:
        for entry in entries:
            try:
                conn.execute(
                    """INSERT OR IGNORE INTO mood_entries
                       (id, user_id, mood, confidence, source, all_scores, detected_at, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
                    (
                        entry["id"],
                        entry["user_id"],
                        entry["mood"],
                        entry["confidence"],
                        entry.get("source", "camera"),
                        entry.get("all_scores"),
                        entry["detected_at"],
                        datetime.now(timezone.utc).isoformat(),
                    ),
                )
                if conn.total_changes:
                    inserted += 1
                else:
                    skipped += 1
            except sqlite3.IntegrityError:
                skipped += 1

        conn.commit()
        return {"inserted": inserted, "skipped": skipped, "total": len(entries)}
    finally:
        conn.close()


def get_entries(
    user_id: str,
    cursor: str = None,
    limit: int = 20,
    start_date: str = None,
    end_date: str = None,
    mood: str = None,
) -> dict:
    """Get paginated mood entries with filters."""
    conn = get_db()
    try:
        conditions = ["user_id = ?", "deleted_at IS NULL"]
        params = [user_id]

        if cursor:
            conditions.append("detected_at < ?")
            params.append(cursor)

        if start_date:
            conditions.append("detected_at >= ?")
            params.append(start_date)

        if end_date:
            conditions.append("detected_at <= ?")
            params.append(end_date)

        if mood:
            conditions.append("mood = ?")
            params.append(mood)

        where = " AND ".join(conditions)
        params.append(limit + 1)  # Fetch one extra to check if there are more

        rows = conn.execute(
            f"""SELECT * FROM mood_entries
                WHERE {where}
                ORDER BY detected_at DESC
                LIMIT ?""",
            params,
        ).fetchall()

        entries = [dict(r) for r in rows[:limit]]
        has_more = len(rows) > limit
        next_cursor = entries[-1]["detected_at"] if entries and has_more else None

        return {
            "data": entries,
            "next_cursor": next_cursor,
            "has_more": has_more,
            "count": len(entries),
        }
    finally:
        conn.close()


def soft_delete_entry(entry_id: str, user_id: str) -> bool:
    """Soft-delete a mood entry. Returns True if deleted."""
    conn = get_db()
    try:
        result = conn.execute(
            """UPDATE mood_entries
               SET deleted_at = ?
               WHERE id = ? AND user_id = ? AND deleted_at IS NULL""",
            (datetime.now(timezone.utc).isoformat(), entry_id, user_id),
        )
        conn.commit()
        return result.rowcount > 0
    finally:
        conn.close()


def get_mood_trend(user_id: str, start_date: str = None, end_date: str = None) -> dict:
    """Get mood trend summary for a user."""
    conn = get_db()
    try:
        conditions = ["user_id = ?", "deleted_at IS NULL"]
        params = [user_id]

        if start_date:
            conditions.append("detected_at >= ?")
            params.append(start_date)

        if end_date:
            conditions.append("detected_at <= ?")
            params.append(end_date)

        where = " AND ".join(conditions)

        # Most frequent mood
        rows = conn.execute(
            f"""SELECT mood, COUNT(*) as count,
                       ROUND(AVG(confidence), 3) as avg_confidence
                FROM mood_entries
                WHERE {where}
                GROUP BY mood
                ORDER BY count DESC""",
            params,
        ).fetchall()

        mood_counts = {row["mood"]: row["count"] for row in rows}
        total = sum(mood_counts.values())

        # Distribution
        distribution = {}
        for row in rows:
            distribution[row["mood"]] = {
                "count": row["count"],
                "percentage": round(row["count"] / total * 100, 1) if total > 0 else 0,
                "avg_confidence": row["avg_confidence"],
            }

        # Most frequent
        most_frequent = rows[0]["mood"] if rows else None

        # Recent trend (last 7 entries)
        recent = conn.execute(
            f"""SELECT mood FROM mood_entries
                WHERE {where}
                ORDER BY detected_at DESC LIMIT 7""",
            params,
        ).fetchall()

        recent_moods = [r["mood"] for r in recent]

        return {
            "total_entries": total,
            "most_frequent_mood": most_frequent,
            "distribution": distribution,
            "recent_trend": recent_moods,
        }
    finally:
        conn.close()


def correct_entry(entry_id: str, user_id: str, corrected_mood: str) -> bool:
    """User corrects a detected mood. Tracks accuracy."""
    conn = get_db()
    try:
        row = conn.execute(
            "SELECT mood FROM mood_entries WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
            (entry_id, user_id),
        ).fetchone()

        if not row:
            return False

        conn.execute(
            """UPDATE mood_entries
               SET original_mood = CASE WHEN original_mood IS NULL THEN mood ELSE original_mood END,
                   mood = ?,
                   is_corrected = 1
               WHERE id = ? AND user_id = ?""",
            (corrected_mood, entry_id, user_id),
        )
        conn.commit()
        return True
    finally:
        conn.close()


def get_user_trends(user_id: str, period: str = "7d", timezone_offset: int = 0) -> dict:
    """Get user mood trends for a period (7d, 30d, 90d)."""
    days = {"7d": 7, "30d": 30, "90d": 90}.get(period, 7)

    conn = get_db()
    try:
        # Daily breakdown
        daily = conn.execute(
            """SELECT date(detected_at, ? || ' hours') as day,
                      mood, COUNT(*) as count
               FROM mood_entries
               WHERE user_id = ? AND deleted_at IS NULL
                 AND detected_at >= datetime('now', ? || ' days')
               GROUP BY day, mood
               ORDER BY day DESC""",
            (str(timezone_offset), user_id, str(-days)),
        ).fetchall()

        # Overall distribution for the period
        dist = conn.execute(
            """SELECT mood, COUNT(*) as count,
                      ROUND(AVG(confidence), 3) as avg_confidence
               FROM mood_entries
               WHERE user_id = ? AND deleted_at IS NULL
                 AND detected_at >= datetime('now', ? || ' days')
               GROUP BY mood
               ORDER BY count DESC""",
            (user_id, str(-days)),
        ).fetchall()

        total = sum(r["count"] for r in dist)
        distribution = {}
        for r in dist:
            distribution[r["mood"]] = {
                "count": r["count"],
                "percentage": round(r["count"] / total * 100, 1) if total > 0 else 0,
                "avg_confidence": r["avg_confidence"],
            }

        dominant = dist[0]["mood"] if dist else None

        # Build daily timeline
        timeline = {}
        for r in daily:
            day = r["day"]
            if day not in timeline:
                timeline[day] = {"date": day, "Happy": 0, "Neutral": 0, "Low": 0, "Angry": 0, "total": 0}
            timeline[day][r["mood"]] = r["count"]
            timeline[day]["total"] += r["count"]

        return {
            "period": period,
            "days": days,
            "total_entries": total,
            "dominant_mood": dominant,
            "mood_distribution": distribution,
            "daily_timeline": list(timeline.values()),
        }
    finally:
        conn.close()


def get_global_stats(period: str = "7d") -> dict:
    """Get platform-wide mood distribution (admin)."""
    days = {"7d": 7, "30d": 30, "90d": 90, "all": 36500}.get(period, 7)

    conn = get_db()
    try:
        # Overall distribution
        dist = conn.execute(
            """SELECT mood, COUNT(*) as count,
                      ROUND(AVG(confidence), 3) as avg_confidence
               FROM mood_entries
               WHERE deleted_at IS NULL
                 AND detected_at >= datetime('now', ? || ' days')
               GROUP BY mood
               ORDER BY count DESC""",
            (str(-days),),
        ).fetchall()

        total = sum(r["count"] for r in dist)
        distribution = {}
        for r in dist:
            distribution[r["mood"]] = {
                "count": r["count"],
                "percentage": round(r["count"] / total * 100, 1) if total > 0 else 0,
                "avg_confidence": r["avg_confidence"],
            }

        # Unique users
        users = conn.execute(
            """SELECT COUNT(DISTINCT user_id) as count
               FROM mood_entries
               WHERE deleted_at IS NULL
                 AND detected_at >= datetime('now', ? || ' days')""",
            (str(-days),),
        ).fetchone()

        # Source breakdown
        sources = conn.execute(
            """SELECT source, COUNT(*) as count
               FROM mood_entries
               WHERE deleted_at IS NULL
                 AND detected_at >= datetime('now', ? || ' days')
               GROUP BY source""",
            (str(-days),),
        ).fetchall()

        # Daily volume
        daily_volume = conn.execute(
            """SELECT date(detected_at) as day, COUNT(*) as count
               FROM mood_entries
               WHERE deleted_at IS NULL
                 AND detected_at >= datetime('now', ? || ' days')
               GROUP BY day
               ORDER BY day DESC LIMIT 30""",
            (str(-days),),
        ).fetchall()

        return {
            "period": period,
            "total_detections": total,
            "unique_users": users["count"] if users else 0,
            "mood_distribution": distribution,
            "source_breakdown": {r["source"]: r["count"] for r in sources},
            "daily_volume": [{"date": r["day"], "count": r["count"]} for r in daily_volume],
        }
    finally:
        conn.close()


def get_accuracy_stats(user_id: str = None) -> dict:
    """Get detection accuracy stats (confirmed vs corrected)."""
    conn = get_db()
    try:
        conditions = ["deleted_at IS NULL", "source = 'camera'"]
        params = []

        if user_id:
            conditions.append("user_id = ?")
            params.append(user_id)

        where = " AND ".join(conditions)

        total = conn.execute(
            f"SELECT COUNT(*) as count FROM mood_entries WHERE {where}", params
        ).fetchone()["count"]

        corrected = conn.execute(
            f"SELECT COUNT(*) as count FROM mood_entries WHERE {where} AND is_corrected = 1", params
        ).fetchone()["count"]

        confirmed = total - corrected

        # Correction breakdown
        corrections = conn.execute(
            f"""SELECT original_mood, mood as corrected_to, COUNT(*) as count
                FROM mood_entries
                WHERE {where} AND is_corrected = 1
                GROUP BY original_mood, corrected_to
                ORDER BY count DESC""",
            params,
        ).fetchall()

        return {
            "total_detections": total,
            "confirmed": confirmed,
            "corrected": corrected,
            "accuracy_rate": round(confirmed / total * 100, 1) if total > 0 else 0,
            "corrections": [dict(r) for r in corrections],
        }
    finally:
        conn.close()


def export_entries_csv(user_id: str = None, start_date: str = None, end_date: str = None) -> list:
    """Export mood entries as list of dicts for CSV."""
    conn = get_db()
    try:
        conditions = ["deleted_at IS NULL"]
        params = []

        if user_id:
            conditions.append("user_id = ?")
            params.append(user_id)

        if start_date:
            conditions.append("detected_at >= ?")
            params.append(start_date)

        if end_date:
            conditions.append("detected_at <= ?")
            params.append(end_date)

        where = " AND ".join(conditions)

        rows = conn.execute(
            f"""SELECT id, user_id, mood, confidence, source,
                       original_mood, is_corrected, detected_at, created_at
                FROM mood_entries
                WHERE {where}
                ORDER BY detected_at DESC
                LIMIT 10000""",
            params,
        ).fetchall()

        return [dict(r) for r in rows]
    finally:
        conn.close()


# ─── GDPR & Privacy Functions ─────────────────────────

def log_audit_event(user_id: str, action: str, details: str = None, ip_address: str = None):
    """Record an audit event for compliance tracking."""
    conn = get_db()
    try:
        conn.execute(
            "INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)",
            (user_id, action, details, ip_address),
        )
        conn.commit()
    finally:
        conn.close()


def delete_all_user_data(user_id: str) -> dict:
    """
    GDPR right-to-be-forgotten: permanently delete all mood data for a user.
    Returns counts of deleted records.
    """
    conn = get_db()
    try:
        # Count before deletion for audit
        count_before = conn.execute(
            "SELECT COUNT(*) as c FROM mood_entries WHERE user_id = ?",
            (user_id,),
        ).fetchone()["c"]

        # Hard delete all mood entries (not soft delete - for GDPR compliance)
        conn.execute("DELETE FROM mood_entries WHERE user_id = ?", (user_id,))
        conn.commit()

        return {
            "deleted_entries": count_before,
            "user_id": user_id,
        }
    finally:
        conn.close()


def export_all_user_data(user_id: str) -> dict:
    """
    GDPR right-to-portability: export all user mood data in JSON format.
    Returns complete user data including history, corrections, and metadata.
    """
    conn = get_db()
    try:
        # Get all non-deleted entries
        entries = conn.execute(
            """SELECT id, mood, confidence, source, all_scores,
                      original_mood, is_corrected, detected_at, created_at
               FROM mood_entries
               WHERE user_id = ? AND deleted_at IS NULL
               ORDER BY detected_at DESC""",
            (user_id,),
        ).fetchall()

        # Get audit log for this user
        audit = conn.execute(
            """SELECT action, details, created_at
               FROM audit_log
               WHERE user_id = ?
               ORDER BY created_at DESC
               LIMIT 1000""",
            (user_id,),
        ).fetchall()

        return {
            "user_id": user_id,
            "export_timestamp": datetime.now(timezone.utc).isoformat(),
            "data_category": "mood_history",
            "entry_count": len(entries),
            "entries": [dict(r) for r in entries],
            "audit_log": [dict(r) for r in audit],
        }
    finally:
        conn.close()


def purge_old_entries(retention_days: int = 365) -> dict:
    """
    Auto-purge mood entries older than retention period.
    Default: 365 days.
    """
    conn = get_db()
    try:
        # Get count before
        count = conn.execute(
            "SELECT COUNT(*) as c FROM mood_entries WHERE detected_at < datetime('now', ? || ' days')",
            (f"-{retention_days}",),
        ).fetchone()["c"]

        # Permanently delete
        conn.execute(
            "DELETE FROM mood_entries WHERE detected_at < datetime('now', ? || ' days')",
            (f"-{retention_days}",),
        )
        conn.commit()

        return {
            "purged_count": count,
            "retention_days": retention_days,
            "purged_at": datetime.now(timezone.utc).isoformat(),
        }
    finally:
        conn.close()
