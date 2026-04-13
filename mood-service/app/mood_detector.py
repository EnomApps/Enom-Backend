"""
Mood detection using DeepFace library.
Uses pre-trained emotion recognition model (~85-90% accuracy).
Model is loaded once on startup and reused for all requests.
"""

import logging
import numpy as np
import cv2
from PIL import Image
from deepface import DeepFace
from app.config import MOOD_MAP

logger = logging.getLogger("mood-service")

_model_ready = False


def load_model():
    """Warm up DeepFace model on startup."""
    global _model_ready
    logger.info("Loading DeepFace emotion model (first load may take 30-60 seconds)...")

    try:
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
        except Exception:
            pass

        _model_ready = True
        logger.info("DeepFace emotion model loaded successfully.")
    except Exception as e:
        logger.error(f"Failed to load DeepFace model: {e}")
        _model_ready = True


class MoodDetectionError(Exception):
    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(message)


def detect_mood(img: Image.Image) -> dict:
    """
    Detect mood from a PIL Image using DeepFace.
    Tries multiple approaches for reliable face detection.
    """
    # Convert PIL Image to numpy array (BGR for OpenCV/DeepFace)
    img_rgb = np.array(img.convert("RGB"))
    img_bgr = cv2.cvtColor(img_rgb, cv2.COLOR_RGB2BGR)

    # Resize if too large (speeds up detection)
    h, w = img_bgr.shape[:2]
    if max(h, w) > 1024:
        scale = 1024 / max(h, w)
        img_bgr = cv2.resize(img_bgr, (int(w * scale), int(h * scale)))
        logger.info(f"Resized image from {w}x{h} to {img_bgr.shape[1]}x{img_bgr.shape[0]}")

    results = None

    # Try 1: With face detection (enforce_detection=True, opencv backend)
    try:
        results = DeepFace.analyze(
            img_bgr,
            actions=["emotion"],
            enforce_detection=True,
            detector_backend="opencv",
            silent=True,
        )
        logger.info("Face detected with opencv backend")
    except (ValueError, Exception) as e:
        logger.info(f"opencv backend failed: {e}")

    # Try 2: With ssd backend (more robust)
    if results is None:
        try:
            results = DeepFace.analyze(
                img_bgr,
                actions=["emotion"],
                enforce_detection=True,
                detector_backend="ssd",
                silent=True,
            )
            logger.info("Face detected with ssd backend")
        except (ValueError, Exception) as e:
            logger.info(f"ssd backend failed: {e}")

    # Try 3: Skip face detection entirely (process whole image as face)
    if results is None:
        try:
            results = DeepFace.analyze(
                img_bgr,
                actions=["emotion"],
                enforce_detection=False,
                detector_backend="skip",
                silent=True,
            )
            logger.info("Processed with skip backend (no face detection)")
        except Exception as e:
            logger.error(f"All detection methods failed: {e}")
            raise MoodDetectionError(
                code="NO_FACE_DETECTED",
                message="No face detected in the image. Please provide a clear, well-lit facial photo.",
            )

    # Get the first face result
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

    # Map DeepFace emotions to our 4 mood labels
    mood_scores = {
        "Happy": 0.0,
        "Neutral": 0.0,
        "Low": 0.0,
        "Angry": 0.0,
    }

    for emotion, score in emotions.items():
        mapped_mood = MOOD_MAP.get(emotion.lower())
        if mapped_mood:
            mood_scores[mapped_mood] += score

    # Normalize to 0-1 range (DeepFace returns percentages 0-100)
    total = sum(mood_scores.values())
    if total > 0:
        mood_scores = {k: round(v / total, 3) for k, v in mood_scores.items()}

    dominant_mood = max(mood_scores, key=mood_scores.get)
    confidence = mood_scores[dominant_mood]

    return {
        "mood": dominant_mood,
        "confidence": confidence,
        "all_scores": mood_scores,
    }
