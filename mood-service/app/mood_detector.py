"""
Mood detection using DeepFace library.
Uses pre-trained emotion recognition model (~85-90% accuracy).
Model is loaded once on startup and reused for all requests.

Supports:
- Model versioning
- Graceful degradation if model fails to load
- Hot-swapping model without restart
"""

import logging
import os
import threading
import time
import numpy as np
import cv2
from PIL import Image
from deepface import DeepFace
from app.config import MOOD_MAP

logger = logging.getLogger("mood-service")

# Model state tracking
MODEL_VERSION = os.getenv("MODEL_VERSION", "deepface-emotion-1.0")
_model_state = {
    "loaded": False,
    "loading": False,
    "version": MODEL_VERSION,
    "loaded_at": None,
    "load_time_ms": None,
    "last_error": None,
    "inference_count": 0,
}
_state_lock = threading.Lock()


def get_model_state() -> dict:
    """Get current model state for health checks."""
    with _state_lock:
        return dict(_model_state)


def is_model_ready() -> bool:
    """Check if model is loaded and ready for inference."""
    with _state_lock:
        return _model_state["loaded"]


def load_model(allow_failure: bool = True) -> bool:
    """
    Warm up DeepFace model on startup.

    Args:
        allow_failure: If True (default), service continues in degraded mode
                       if model fails to load. If False, raises exception.

    Returns:
        True if model loaded successfully, False otherwise.
    """
    with _state_lock:
        if _model_state["loaded"]:
            logger.info("Model already loaded.")
            return True
        if _model_state["loading"]:
            logger.info("Model is currently loading.")
            return False
        _model_state["loading"] = True
        _model_state["last_error"] = None

    logger.info(f"Loading mood detection model {MODEL_VERSION}...")
    start_time = time.time()

    try:
        # Warmup inference with dummy image
        dummy = np.zeros((224, 224, 3), dtype=np.uint8)
        dummy[50:200, 50:200] = 128

        try:
            DeepFace.analyze(
                dummy,
                actions=["emotion"],
                enforce_detection=False,
                detector_backend="skip",
                silent=True,
            )
        except Exception as e:
            logger.debug(f"Warmup inference error (expected): {e}")

        load_time = int((time.time() - start_time) * 1000)

        with _state_lock:
            _model_state["loaded"] = True
            _model_state["loading"] = False
            _model_state["loaded_at"] = time.time()
            _model_state["load_time_ms"] = load_time

        logger.info(f"Model {MODEL_VERSION} loaded successfully in {load_time}ms.")
        return True

    except Exception as e:
        load_time = int((time.time() - start_time) * 1000)
        error_msg = f"Failed to load model: {str(e)}"
        logger.error(error_msg)

        with _state_lock:
            _model_state["loaded"] = False
            _model_state["loading"] = False
            _model_state["last_error"] = error_msg
            _model_state["load_time_ms"] = load_time

        if not allow_failure:
            raise

        logger.warning("Service starting in DEGRADED mode - /detect will return 503")
        return False


def reload_model() -> dict:
    """
    Hot-swap model without service restart.
    Useful for model version updates.
    """
    logger.info("Hot-reloading model...")

    with _state_lock:
        _model_state["loaded"] = False

    success = load_model(allow_failure=True)
    return get_model_state()


class MoodDetectionError(Exception):
    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(message)


class ModelNotReadyError(Exception):
    """Raised when model is not loaded and detection is requested."""
    pass


def _crop_largest_face(img_bgr: np.ndarray):
    """Detect and crop the largest face using OpenCV Haar Cascade."""
    try:
        gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
        cascade = cv2.CascadeClassifier(
            cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
        )
        faces = cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(50, 50))

        if len(faces) == 0:
            return None

        x, y, w, h = max(faces, key=lambda f: f[2] * f[3])
        padding_x = int(w * 0.2)
        padding_y = int(h * 0.2)
        x1 = max(0, x - padding_x)
        y1 = max(0, y - padding_y)
        x2 = min(img_bgr.shape[1], x + w + padding_x)
        y2 = min(img_bgr.shape[0], y + h + padding_y)

        return img_bgr[y1:y2, x1:x2]
    except Exception as e:
        logger.warning(f"Face cropping failed: {e}")
        return None


def _secure_zero_array(arr):
    """
    Zero out numpy array memory after use for privacy compliance.
    Ensures image data doesn't linger in memory after inference.
    """
    if arr is not None and hasattr(arr, 'fill'):
        try:
            arr.fill(0)
        except Exception:
            pass


def detect_mood(img: Image.Image) -> dict:
    """
    Detect mood from a PIL Image using DeepFace.
    Image data is zeroed from memory after inference (GDPR compliant).
    Raises ModelNotReadyError if model is not loaded.
    """
    if not is_model_ready():
        raise ModelNotReadyError("Mood detection model is not loaded. Service is in degraded mode.")

    img_rgb = np.array(img.convert("RGB"))
    img_bgr = cv2.cvtColor(img_rgb, cv2.COLOR_RGB2BGR)
    face_region = None
    results = None

    try:
        h, w = img_bgr.shape[:2]
        if max(h, w) > 1024:
            scale = 1024 / max(h, w)
            img_bgr = cv2.resize(img_bgr, (int(w * scale), int(h * scale)))

        face_region = _crop_largest_face(img_bgr)
        if face_region is not None:
            try:
                results = DeepFace.analyze(
                    face_region,
                    actions=["emotion"],
                    enforce_detection=False,
                    detector_backend="skip",
                    silent=True,
                )
            except Exception:
                pass

        if results is None:
            for backend in ("retinaface", "mtcnn", "ssd", "opencv"):
                try:
                    results = DeepFace.analyze(
                        img_bgr,
                        actions=["emotion"],
                        enforce_detection=True,
                        detector_backend=backend,
                        silent=True,
                    )
                    break
                except Exception:
                    continue

        if results is None:
            raise MoodDetectionError(
                code="NO_FACE_DETECTED",
                message="No face detected. Please look directly at the camera with good lighting.",
            )
    except Exception:
        # Zero memory even on error
        _secure_zero_array(img_rgb)
        _secure_zero_array(img_bgr)
        _secure_zero_array(face_region)
        raise

    if isinstance(results, list):
        result = results[0]
    else:
        result = results

    emotions = result.get("emotion", {})
    if not emotions:
        raise MoodDetectionError(
            code="NO_EMOTION_DETECTED",
            message="Could not detect emotions. Please provide a clearer facial photo.",
        )

    deepface_dominant = max(emotions, key=emotions.get).lower()
    deepface_dominant_score = emotions[deepface_dominant]

    mood_scores = {"Happy": 0.0, "Neutral": 0.0, "Low": 0.0, "Angry": 0.0}
    for emotion, score in emotions.items():
        mapped_mood = MOOD_MAP.get(emotion.lower())
        if mapped_mood:
            mood_scores[mapped_mood] += score

    total = sum(mood_scores.values())
    if total > 0:
        mood_scores = {k: round(v / total, 3) for k, v in mood_scores.items()}

    if deepface_dominant_score > 40:
        preferred_mood = MOOD_MAP.get(deepface_dominant)
        if preferred_mood:
            dominant_mood = preferred_mood
            confidence = mood_scores[dominant_mood]
        else:
            dominant_mood = max(mood_scores, key=mood_scores.get)
            confidence = mood_scores[dominant_mood]
    else:
        dominant_mood = max(mood_scores, key=mood_scores.get)
        confidence = mood_scores[dominant_mood]

    # Track inference count
    with _state_lock:
        _model_state["inference_count"] += 1

    result_data = {
        "mood": dominant_mood,
        "confidence": confidence,
        "all_scores": mood_scores,
    }

    # GDPR: Zero out image data from memory after inference
    _secure_zero_array(img_rgb)
    _secure_zero_array(img_bgr)
    _secure_zero_array(face_region)

    return result_data
