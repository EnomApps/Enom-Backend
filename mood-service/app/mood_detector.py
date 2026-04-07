"""
Mood detection using FER (Facial Emotion Recognition) model.
Model is loaded once on startup and reused for all requests.
"""

import logging
import numpy as np
from PIL import Image
from fer import FER
from app.config import MOOD_MAP

logger = logging.getLogger("mood-service")

# Global model instance (loaded once on startup)
_detector = None


def load_model():
    """Load FER model on startup. Called once."""
    global _detector
    logger.info("Loading FER model...")
    _detector = FER(mtcnn=True)
    logger.info("FER model loaded successfully.")


def get_detector() -> FER:
    """Get the loaded detector instance."""
    if _detector is None:
        load_model()
    return _detector


class MoodDetectionError(Exception):
    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(message)


def detect_mood(img: Image.Image) -> dict:
    """
    Detect mood from a PIL Image.

    Returns:
        {
            "mood": "Happy",
            "confidence": 0.92,
            "all_scores": {"Happy": 0.92, "Neutral": 0.05, "Low": 0.02, "Angry": 0.01}
        }
    """
    detector = get_detector()

    # Convert PIL Image to numpy array for FER
    img_array = np.array(img.convert("RGB"))

    # Detect emotions
    results = detector.detect_emotions(img_array)

    if not results:
        raise MoodDetectionError(
            code="NO_FACE_DETECTED",
            message="No face detected in the image. Please provide a clear facial photo."
        )

    # Get the first (most prominent) face
    face = results[0]
    emotions = face["emotions"]

    # Map FER emotions to our 4 mood labels
    mood_scores = {
        "Happy": 0.0,
        "Neutral": 0.0,
        "Low": 0.0,
        "Angry": 0.0,
    }

    for fer_emotion, score in emotions.items():
        mapped_mood = MOOD_MAP.get(fer_emotion)
        if mapped_mood:
            mood_scores[mapped_mood] += score

    # Normalize scores to sum to 1.0
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
