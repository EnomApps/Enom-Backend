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

# Flag to track if model is warmed up
_model_ready = False


def load_model():
    """
    Warm up DeepFace model on startup.
    First call downloads and caches the model (~100MB).
    Subsequent calls use the cached model.
    """
    global _model_ready
    logger.info("Loading DeepFace emotion model (first load may take 30-60 seconds)...")

    try:
        # Create a dummy image to warm up the model
        dummy = np.zeros((48, 48, 3), dtype=np.uint8)
        dummy[10:38, 10:38] = 128  # Add some gray area

        # This triggers model download and loading
        try:
            DeepFace.analyze(
                dummy,
                actions=["emotion"],
                enforce_detection=False,
                silent=True,
            )
        except Exception:
            pass  # Dummy image won't have a face, that's ok

        _model_ready = True
        logger.info("DeepFace emotion model loaded successfully.")
    except Exception as e:
        logger.error(f"Failed to load DeepFace model: {e}")
        _model_ready = True  # Still mark ready, will try on actual requests


class MoodDetectionError(Exception):
    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(message)


def detect_mood(img: Image.Image) -> dict:
    """
    Detect mood from a PIL Image using DeepFace.

    Returns:
        {
            "mood": "Happy",
            "confidence": 0.92,
            "all_scores": {"Happy": 0.92, "Neutral": 0.05, "Low": 0.02, "Angry": 0.01}
        }
    """
    # Convert PIL Image to numpy array (BGR for OpenCV/DeepFace)
    img_rgb = np.array(img.convert("RGB"))
    img_bgr = cv2.cvtColor(img_rgb, cv2.COLOR_RGB2BGR)

    # Run DeepFace emotion analysis
    try:
        results = DeepFace.analyze(
            img_bgr,
            actions=["emotion"],
            enforce_detection=True,
            detector_backend="opencv",  # Fastest detector
            silent=True,
        )
    except ValueError:
        raise MoodDetectionError(
            code="NO_FACE_DETECTED",
            message="No face detected in the image. Please provide a clear facial photo.",
        )
    except Exception as e:
        logger.error(f"DeepFace analysis error: {e}")
        raise MoodDetectionError(
            code="DETECTION_FAILED",
            message="Failed to analyze the image. Please try again with a clearer photo.",
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
    # DeepFace returns: angry, disgust, fear, happy, sad, surprise, neutral
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

    # Get dominant mood
    dominant_mood = max(mood_scores, key=mood_scores.get)
    confidence = mood_scores[dominant_mood]

    return {
        "mood": dominant_mood,
        "confidence": confidence,
        "all_scores": mood_scores,
    }
