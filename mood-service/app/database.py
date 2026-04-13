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
            detected_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            deleted_at TEXT DEFAULT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_mood_entries_user_id
            ON mood_entries(user_id, detected_at DESC);

        CREATE INDEX IF NOT EXISTS idx_mood_entries_user_mood
            ON mood_entries(user_id, mood);

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
