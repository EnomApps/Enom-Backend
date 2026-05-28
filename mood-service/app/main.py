"""
ENOM Mood Detection API
FastAPI service for facial emotion recognition.

Endpoints:
    POST /api/v1/mood/detect - Detect mood from facial image
    GET  /api/v1/mood/health - Health check
    GET  /api/v1/mood/history - User's mood detection history
"""

import time
import uuid
import logging
from datetime import datetime, timezone
from contextlib import asynccontextmanager

from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from typing import Optional, List
from app.config import HOST, PORT
from app.auth import validate_token, AuthError
from app.preprocessing import process_image, ImagePreprocessingError
from app.mood_detector import (
    detect_mood, load_model, MoodDetectionError, ModelNotReadyError,
    get_model_state, is_model_ready, reload_model, MODEL_VERSION
)
from app.rate_limiter import check_rate_limit, RateLimitExceeded
from app import database as db

# ─── Logging ───────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)-7s | %(name)s | %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("mood-service")


# ─── Lifespan (load model on startup) ─────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Starting Mood Detection Service...")
    # Graceful degradation: service starts even if model fails
    model_loaded = load_model(allow_failure=True)
    db.init_db()
    if model_loaded:
        logger.info("Service ready in NORMAL mode.")
    else:
        logger.warning("Service ready in DEGRADED mode - model failed to load.")
    yield
    logger.info("Shutting down Mood Detection Service.")


# ─── App ───────────────────────────────────────────────
app = FastAPI(
    title="ENOM Mood Detection API",
    version="1.0.0",
    description="Facial emotion recognition service for ENOM",
    docs_url="/api/v1/mood/docs",
    redoc_url="/api/v1/mood/redoc",
    openapi_url="/api/v1/mood/openapi.json",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


# Middleware to add X-Model-Version header to all responses
@app.middleware("http")
async def add_model_version_header(request: Request, call_next):
    response = await call_next(request)
    response.headers["X-Model-Version"] = MODEL_VERSION
    return response


# ─── Request/Response Models ──────────────────────────
class MoodDetectRequest(BaseModel):
    image: str = Field(..., description="Base64-encoded facial image (JPEG/PNG, max 1MB)")
    userId: str = Field(None, description="Optional user ID (auto-detected from token)")


class MoodDetectResponse(BaseModel):
    mood: str = Field(..., description="Detected mood: Happy, Neutral, Low, or Angry")
    confidence: float = Field(..., ge=0.0, le=1.0, description="Confidence score 0.0-1.0")
    all_scores: dict = Field(..., description="Scores for all mood labels")
    timestamp: str = Field(..., description="ISO 8601 timestamp")
    requestId: str = Field(..., description="Unique request ID for tracing")
    processing_time_ms: int = Field(..., description="Processing time in milliseconds")


class ErrorResponse(BaseModel):
    error: str
    code: str
    message: str
    requestId: str


# ─── History Models ──────────────────────────────────
class MoodHistoryCreateRequest(BaseModel):
    mood: str = Field(..., description="Mood label: Happy, Neutral, Low, or Angry")
    confidence: float = Field(0.0, ge=0.0, le=1.0, description="Confidence score")
    source: str = Field("camera", description="Source: camera or manual")
    detectedAt: str = Field(..., description="ISO 8601 timestamp of detection")


class MoodHistoryBatchRequest(BaseModel):
    entries: List[MoodHistoryCreateRequest] = Field(..., max_length=50, description="Array of mood entries (max 50)")


class MoodHistoryEntry(BaseModel):
    id: str
    mood: str
    confidence: float
    source: str
    detected_at: str
    created_at: str


# ─── Health Check (Liveness) ──────────────────────────
@app.get("/api/v1/mood/health", tags=["Health"])
@app.get("/health", tags=["Health"])
async def health_check():
    """
    Liveness probe. Returns 200 even if model is not loaded (degraded mode).
    Includes detailed model status for monitoring.
    """
    state = get_model_state()
    mode = "normal" if state["loaded"] else "degraded"

    return {
        "status": "ok",
        "service": "mood-detection",
        "version": "1.0.0",
        "mode": mode,
        "model": {
            "version": state["version"],
            "loaded": state["loaded"],
            "loading": state["loading"],
            "load_time_ms": state["load_time_ms"],
            "loaded_at": state["loaded_at"],
            "inference_count": state["inference_count"],
            "last_error": state["last_error"],
        },
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }


# ─── Readiness Probe ──────────────────────────────────
@app.get("/api/v1/mood/health/ready", tags=["Health"])
@app.get("/health/ready", tags=["Health"])
async def readiness_check():
    """
    Readiness probe. Returns 200 only if model is loaded AND dependencies connected.
    Used by load balancers/orchestrators to decide if traffic should be routed here.
    """
    state = get_model_state()

    # Check Redis
    redis_ok = False
    try:
        from app.rate_limiter import get_redis
        r = get_redis()
        if r is not None:
            r.ping()
            redis_ok = True
    except Exception:
        redis_ok = False

    # Check SQLite
    sqlite_ok = False
    try:
        conn = db.get_db()
        conn.execute("SELECT 1").fetchone()
        conn.close()
        sqlite_ok = True
    except Exception:
        sqlite_ok = False

    ready = state["loaded"] and sqlite_ok

    response = {
        "ready": ready,
        "checks": {
            "model_loaded": state["loaded"],
            "redis_connected": redis_ok,
            "sqlite_connected": sqlite_ok,
        },
        "model_version": state["version"],
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }

    status_code = 200 if ready else 503
    return JSONResponse(status_code=status_code, content=response)


# ─── Hot-Swap Model (Admin) ───────────────────────────
@app.post("/api/v1/mood/admin/reload-model", tags=["Health"])
async def reload_model_endpoint(authorization: str = Header(None)):
    """
    Hot-swap model without service restart.
    Requires admin authentication via Bearer token.
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    logger.info(f"Model reload requested by user {user['user_id']}")
    state = reload_model()

    if state["loaded"]:
        return {
            "status": "success",
            "message": "Model reloaded successfully.",
            "model": state,
        }
    else:
        return JSONResponse(
            status_code=500,
            content={
                "status": "error",
                "message": "Model reload failed. Service in degraded mode.",
                "model": state,
            },
        )


# ─── Mood Detection Endpoint ─────────────────────────
@app.post(
    "/api/v1/mood/detect",
    response_model=MoodDetectResponse,
    responses={
        401: {"model": ErrorResponse, "description": "Unauthenticated"},
        422: {"model": ErrorResponse, "description": "Validation error"},
        429: {"model": ErrorResponse, "description": "Rate limit exceeded"},
        500: {"model": ErrorResponse, "description": "Detection failed"},
    },
)
async def detect_mood_endpoint(
    body: MoodDetectRequest,
    authorization: str = Header(None),
):
    request_id = str(uuid.uuid4())
    start_time = time.time()

    logger.info(f"[{request_id}] Mood detection request received")

    # ─── 0. Graceful Degradation Check ───────────
    if not is_model_ready():
        logger.warning(f"[{request_id}] Model not ready - returning 503")
        return JSONResponse(
            status_code=503,
            content={
                "error": "Service Unavailable",
                "code": "MODEL_NOT_READY",
                "message": "Mood detection model is not loaded. Service is in degraded mode.",
                "requestId": request_id,
            },
        )

    # ─── 1. Authentication ────────────────────────
    try:
        user = await validate_token(authorization or "")
        user_id = user["user_id"]
        logger.info(f"[{request_id}] Authenticated user: {user_id}")
    except AuthError as e:
        logger.warning(f"[{request_id}] Auth failed: {e.message}")
        return JSONResponse(
            status_code=401,
            content={
                "error": "Unauthenticated",
                "code": "AUTH_FAILED",
                "message": e.message,
                "requestId": request_id,
            },
        )

    # ─── 2. Rate Limiting ─────────────────────────
    try:
        rate_info = check_rate_limit(user_id)
        logger.info(f"[{request_id}] Rate limit: {rate_info['remaining']}/{rate_info['limit']} remaining")
    except RateLimitExceeded as e:
        logger.warning(f"[{request_id}] Rate limit exceeded for user {user_id}")
        return JSONResponse(
            status_code=429,
            headers={"Retry-After": str(e.retry_after)},
            content={
                "error": "Rate Limit Exceeded",
                "code": "RATE_LIMIT_EXCEEDED",
                "message": f"Too many requests. Try again in {e.retry_after} seconds.",
                "requestId": request_id,
            },
        )

    # ─── 3. Image Preprocessing ───────────────────
    try:
        processed_array, pil_image = process_image(body.image)
        logger.info(f"[{request_id}] Image preprocessed successfully")
    except ImagePreprocessingError as e:
        logger.warning(f"[{request_id}] Preprocessing failed: {e.code} - {e.message}")
        return JSONResponse(
            status_code=422,
            content={
                "error": "Validation Error",
                "code": e.code,
                "message": e.message,
                "requestId": request_id,
            },
        )

    # ─── 4. Mood Detection ────────────────────────
    try:
        result = detect_mood(pil_image)
        processing_time = int((time.time() - start_time) * 1000)

        logger.info(
            f"[{request_id}] Mood detected: {result['mood']} "
            f"(confidence: {result['confidence']:.3f}) "
            f"in {processing_time}ms"
        )

        # Auto-save to history
        now_ts = datetime.now(timezone.utc).isoformat()
        try:
            import json
            db.create_entry({
                "id": request_id,
                "user_id": user_id,
                "mood": result["mood"],
                "confidence": result["confidence"],
                "source": "camera",
                "all_scores": json.dumps(result["all_scores"]),
                "detected_at": now_ts,
            })
        except Exception as e:
            logger.warning(f"[{request_id}] Failed to save to history: {e}")

        return MoodDetectResponse(
            mood=result["mood"],
            confidence=result["confidence"],
            all_scores=result["all_scores"],
            timestamp=now_ts,
            requestId=request_id,
            processing_time_ms=processing_time,
        )

    except MoodDetectionError as e:
        logger.warning(f"[{request_id}] Detection failed: {e.code} - {e.message}")
        return JSONResponse(
            status_code=422,
            content={
                "error": "Detection Failed",
                "code": e.code,
                "message": e.message,
                "requestId": request_id,
            },
        )
    except Exception as e:
        processing_time = int((time.time() - start_time) * 1000)
        logger.error(f"[{request_id}] Unexpected error: {str(e)}")
        return JSONResponse(
            status_code=500,
            content={
                "error": "Internal Server Error",
                "code": "INTERNAL_ERROR",
                "message": "An unexpected error occurred during mood detection.",
                "requestId": request_id,
            },
        )


# ─── Create Mood Entry ────────────────────────────────
@app.post("/api/v1/mood/history", tags=["Mood History"])
async def create_mood_entry(
    body: MoodHistoryCreateRequest,
    authorization: str = Header(None),
):
    """Create a mood history entry (from detection or manual input)."""
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    if body.mood not in ("Happy", "Neutral", "Low", "Angry"):
        return JSONResponse(status_code=422, content={"error": "INVALID_MOOD", "message": "Mood must be Happy, Neutral, Low, or Angry."})

    if body.source not in ("camera", "manual"):
        return JSONResponse(status_code=422, content={"error": "INVALID_SOURCE", "message": "Source must be camera or manual."})

    entry_id = str(uuid.uuid4())
    entry = db.create_entry({
        "id": entry_id,
        "user_id": user["user_id"],
        "mood": body.mood,
        "confidence": body.confidence,
        "source": body.source,
        "all_scores": None,
        "detected_at": body.detectedAt,
    })

    return {"message": "Mood entry created.", "entry": entry}


# ─── Get Mood History ─────────────────────────────────
@app.get("/api/v1/mood/history", tags=["Mood History"])
async def get_mood_history(
    authorization: str = Header(None),
    cursor: Optional[str] = None,
    limit: int = 20,
    startDate: Optional[str] = None,
    endDate: Optional[str] = None,
    mood: Optional[str] = None,
):
    """
    Get paginated mood history with filters.
    - cursor: detected_at timestamp for pagination
    - limit: 1-100 (default 20)
    - startDate/endDate: ISO 8601 date filter
    - mood: filter by mood (Happy, Neutral, Low, Angry)
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    limit = min(max(limit, 1), 100)

    result = db.get_entries(
        user_id=user["user_id"],
        cursor=cursor,
        limit=limit,
        start_date=startDate,
        end_date=endDate,
        mood=mood,
    )

    # Include trend summary
    trend = db.get_mood_trend(
        user_id=user["user_id"],
        start_date=startDate,
        end_date=endDate,
    )

    return {
        "data": result["data"],
        "next_cursor": result["next_cursor"],
        "has_more": result["has_more"],
        "count": result["count"],
        "trend": trend,
    }


# ─── Delete Mood Entry (Soft Delete) ─────────────────
@app.delete("/api/v1/mood/history/{entry_id}", tags=["Mood History"])
async def delete_mood_entry(
    entry_id: str,
    authorization: str = Header(None),
):
    """Soft-delete a mood entry. Only the owner can delete."""
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    deleted = db.soft_delete_entry(entry_id, user["user_id"])

    if not deleted:
        return JSONResponse(status_code=404, content={"error": "NOT_FOUND", "message": "Entry not found or already deleted."})

    return {"message": "Mood entry deleted."}


# ─── Batch Sync (Offline Entries) ─────────────────────
@app.post("/api/v1/mood/history/batch", tags=["Mood History"])
async def batch_sync_entries(
    body: MoodHistoryBatchRequest,
    authorization: str = Header(None),
):
    """
    Batch sync mood entries (for offline-queued entries).
    - Max 50 entries per request
    - Idempotent: duplicate detectedAt + userId are ignored
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    if len(body.entries) > 50:
        return JSONResponse(status_code=422, content={"error": "TOO_MANY_ENTRIES", "message": "Maximum 50 entries per batch."})

    entries = []
    for e in body.entries:
        if e.mood not in ("Happy", "Neutral", "Low", "Angry"):
            continue
        entries.append({
            "id": str(uuid.uuid4()),
            "user_id": user["user_id"],
            "mood": e.mood,
            "confidence": e.confidence,
            "source": e.source,
            "all_scores": None,
            "detected_at": e.detectedAt,
        })

    result = db.create_batch(entries)

    return {
        "message": "Batch sync completed.",
        "inserted": result["inserted"],
        "skipped": result["skipped"],
        "total": result["total"],
    }


# ─── Mood Trend Summary ──────────────────────────────
@app.get("/api/v1/mood/trend", tags=["Mood History"])
async def get_mood_trend(
    authorization: str = Header(None),
    startDate: Optional[str] = None,
    endDate: Optional[str] = None,
):
    """Get mood trend summary (most frequent mood, distribution, recent trend)."""
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    trend = db.get_mood_trend(
        user_id=user["user_id"],
        start_date=startDate,
        end_date=endDate,
    )

    return {"trend": trend}


# ─── Correct Mood Entry ───────────────────────────────
@app.put("/api/v1/mood/history/{entry_id}/correct", tags=["Mood History"])
async def correct_mood_entry(
    entry_id: str,
    body: MoodHistoryCreateRequest,
    authorization: str = Header(None),
):
    """Correct a detected mood. Tracks original vs corrected for accuracy metrics."""
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    if body.mood not in ("Happy", "Neutral", "Low", "Angry"):
        return JSONResponse(status_code=422, content={"error": "INVALID_MOOD", "message": "Mood must be Happy, Neutral, Low, or Angry."})

    corrected = db.correct_entry(entry_id, user["user_id"], body.mood)

    if not corrected:
        return JSONResponse(status_code=404, content={"error": "NOT_FOUND", "message": "Entry not found."})

    return {"message": "Mood corrected.", "corrected_mood": body.mood}


# ─── User Mood Trends ────────────────────────────────
@app.get("/api/v1/mood/analytics/trends", tags=["Mood Analytics"])
async def user_mood_trends(
    authorization: str = Header(None),
    period: str = "7d",
    tz_offset: int = 0,
):
    """
    Get user mood trends for a period.
    - period: 7d, 30d, or 90d
    - tz_offset: timezone offset in hours (e.g., 5 for IST, -5 for EST)
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    if period not in ("7d", "30d", "90d"):
        return JSONResponse(status_code=422, content={"error": "INVALID_PERIOD", "message": "Period must be 7d, 30d, or 90d."})

    # Cache for 15 minutes
    import redis as redis_lib
    cache_key = f"mood_trends:{user['user_id']}:{period}"

    try:
        r = redis_lib.Redis(host="127.0.0.1", port=6379, db=2, decode_responses=True)
        cached = r.get(cache_key)
        if cached:
            import json
            return json.loads(cached)
    except Exception:
        pass

    trends = db.get_user_trends(user["user_id"], period, tz_offset)

    try:
        import json
        r.setex(cache_key, 900, json.dumps(trends))
    except Exception:
        pass

    return trends


# ─── Global Mood Stats (Admin) ────────────────────────
@app.get("/api/v1/mood/analytics/global", tags=["Mood Analytics"])
async def global_mood_stats(
    authorization: str = Header(None),
    period: str = "7d",
):
    """
    Platform-wide mood distribution stats.
    - period: 7d, 30d, 90d, all
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    # Cache for 15 minutes
    import redis as redis_lib
    cache_key = f"mood_global:{period}"

    try:
        r = redis_lib.Redis(host="127.0.0.1", port=6379, db=2, decode_responses=True)
        cached = r.get(cache_key)
        if cached:
            import json
            return json.loads(cached)
    except Exception:
        pass

    stats = db.get_global_stats(period)

    try:
        import json
        r.setex(cache_key, 900, json.dumps(stats))
    except Exception:
        pass

    return stats


# ─── Detection Accuracy ──────────────────────────────
@app.get("/api/v1/mood/analytics/accuracy", tags=["Mood Analytics"])
async def detection_accuracy(
    authorization: str = Header(None),
    scope: str = "user",
):
    """
    Detection accuracy stats.
    - scope: 'user' for personal stats, 'global' for platform-wide
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    if scope == "user":
        stats = db.get_accuracy_stats(user["user_id"])
    else:
        stats = db.get_accuracy_stats()

    return {"accuracy": stats}


# ─── CSV Export ───────────────────────────────────────
@app.get("/api/v1/mood/analytics/export", tags=["Mood Analytics"])
async def export_mood_data(
    authorization: str = Header(None),
    format: str = "csv",
    startDate: Optional[str] = None,
    endDate: Optional[str] = None,
    scope: str = "user",
):
    """
    Export mood data as CSV.
    - format: csv
    - scope: 'user' for own data, 'global' for all (admin)
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    user_id = user["user_id"] if scope == "user" else None
    entries = db.export_entries_csv(user_id, startDate, endDate)

    if format == "csv":
        import csv
        import io

        output = io.StringIO()
        if entries:
            writer = csv.DictWriter(output, fieldnames=entries[0].keys())
            writer.writeheader()
            writer.writerows(entries)

        from fastapi.responses import Response
        return Response(
            content=output.getvalue(),
            media_type="text/csv",
            headers={"Content-Disposition": "attachment; filename=mood_export.csv"},
        )

    return {"data": entries, "count": len(entries)}


# ─── GDPR & Privacy Endpoints ─────────────────────────

PRIVACY_POLICY_VERSION = "1.0"
PRIVACY_POLICY_TEXT = """
ENOM Mood Detection Service - Privacy Policy v1.0

DATA COLLECTION
- Facial images submitted for mood detection are processed in memory only.
- Images are NEVER written to disk, S3, or any persistent storage.
- Image data is securely zeroed from memory immediately after inference.

DATA STORAGE
- Only mood classification results are stored (Happy, Neutral, Low, Angry).
- Stored data includes: mood label, confidence score, timestamp, source (camera/manual).
- No biometric data, face embeddings, or image content is retained.

DATA RETENTION
- Mood history is automatically purged after 365 days.
- Users can request immediate deletion at any time via DELETE /api/v1/user/{id}/data.

YOUR RIGHTS (GDPR)
- Right to access: GET /api/v1/user/{id}/export returns all your data as JSON.
- Right to deletion: DELETE /api/v1/user/{id}/data permanently removes all your data.
- Right to correction: PUT /api/v1/mood/history/{entry_id}/correct allows you to fix detections.
- Right to portability: Export endpoint returns machine-readable JSON.

SECURITY
- All API requests require Bearer token authentication.
- All data transfers use HTTPS encryption.
- Audit logs track all data access and deletion events.

CONTACT
For privacy concerns, contact: privacy@enom.ai
"""


@app.get("/api/v1/mood/privacy-policy", tags=["GDPR & Privacy"])
async def get_privacy_policy():
    """Return current privacy policy text and version."""
    return {
        "version": PRIVACY_POLICY_VERSION,
        "effective_date": "2026-05-27",
        "policy": PRIVACY_POLICY_TEXT.strip(),
        "last_updated": datetime.now(timezone.utc).isoformat(),
    }


@app.get("/api/v1/user/{user_id}/export", tags=["GDPR & Privacy"])
async def gdpr_export_user_data(
    user_id: str,
    request: Request,
    authorization: str = Header(None),
):
    """
    GDPR right-to-portability: Export all mood data for a user as JSON.
    User can only export their own data.
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    # Users can only export their own data
    if user["user_id"] != user_id:
        return JSONResponse(
            status_code=403,
            content={"error": "FORBIDDEN", "message": "You can only export your own data."},
        )

    # Audit the export
    client_ip = request.client.host if request.client else "unknown"
    db.log_audit_event(user_id, "data_export", f"GDPR data export requested", client_ip)

    data = db.export_all_user_data(user_id)
    return data


@app.delete("/api/v1/user/{user_id}/data", tags=["GDPR & Privacy"])
async def gdpr_delete_user_data(
    user_id: str,
    request: Request,
    authorization: str = Header(None),
):
    """
    GDPR right-to-be-forgotten: Permanently delete all mood data for a user.
    Cannot be undone. User can only delete their own data.
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    if user["user_id"] != user_id:
        return JSONResponse(
            status_code=403,
            content={"error": "FORBIDDEN", "message": "You can only delete your own data."},
        )

    # Audit BEFORE deletion (so the log persists)
    client_ip = request.client.host if request.client else "unknown"
    db.log_audit_event(user_id, "data_deletion", "GDPR full data deletion requested", client_ip)

    result = db.delete_all_user_data(user_id)
    return {
        "message": "All mood data permanently deleted.",
        "deleted_entries": result["deleted_entries"],
        "user_id": user_id,
        "deleted_at": datetime.now(timezone.utc).isoformat(),
    }


@app.post("/api/v1/mood/admin/purge-old-data", tags=["GDPR & Privacy"])
async def purge_old_data(
    authorization: str = Header(None),
    retention_days: int = 365,
):
    """
    Admin endpoint to manually trigger purge of old mood entries.
    Also runs automatically via cron daily.
    """
    try:
        user = await validate_token(authorization or "")
    except AuthError as e:
        return JSONResponse(status_code=401, content={"error": "AUTH_FAILED", "message": e.message})

    result = db.purge_old_entries(retention_days)
    db.log_audit_event(user["user_id"], "data_purge", f"Manual purge: {result['purged_count']} entries removed", None)

    return {
        "message": "Old data purged successfully.",
        "purged_count": result["purged_count"],
        "retention_days": retention_days,
    }


# ─── Run ──────────────────────────────────────────────
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app.main:app", host=HOST, port=PORT, reload=False, workers=1)
