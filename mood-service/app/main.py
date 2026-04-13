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
from app.mood_detector import detect_mood, load_model, MoodDetectionError
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
    load_model()
    db.init_db()
    logger.info("Service ready.")
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


# ─── Health Check ─────────────────────────────────────
@app.get("/api/v1/mood/health")
async def health_check():
    return {
        "status": "ok",
        "service": "mood-detection",
        "version": "1.0.0",
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }


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


# ─── Run ──────────────────────────────────────────────
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app.main:app", host=HOST, port=PORT, reload=False, workers=1)
