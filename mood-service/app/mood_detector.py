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


def _crop_largest_face(img_bgr: np.ndarray):
    """Detect and crop the largest face from the image using OpenCV Haar Cascade.
    Returns cropped face image, or None if no face detected.
    """
    try:
        gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
        cascade = cv2.CascadeClassifier(
            cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
        )
        faces = cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(50, 50))

        if len(faces) == 0:
            return None

        # Get the largest face
        x, y, w, h = max(faces, key=lambda f: f[2] * f[3])

        # Add padding (20% on each side) for better emotion detection
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


def detect_mood(img: Image.Image) -> dict:
    """
    Detect mood from a PIL Image using DeepFace.
    Pre-crops face region for more accurate emotion detection.
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
    face_cropped = False

    # Step 1: Try to crop the face first for better accuracy
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
            face_cropped = True
            logger.info("Mood detected from cropped face region")
        except Exception as e:
            logger.info(f"Cropped face analysis failed: {e}")

    # Step 2: If face cropping didn't work, try DeepFace's built-in detectors
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
                logger.info(f"Face detected with {backend} backend")
                break
            except Exception as e:
                logger.info(f"{backend} backend failed: {e}")

    # Step 3: No face detected - return error instead of guessing (was causing wrong moods)
    if results is None:
        raise MoodDetectionError(
            code="NO_FACE_DETECTED",
            message="No face detected. Please look directly at the camera with good lighting.",
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

    # Find DeepFace's dominant emotion BEFORE mapping
    deepface_dominant = max(emotions, key=emotions.get).lower()
    deepface_dominant_score = emotions[deepface_dominant]

    logger.info(
        f"DeepFace raw emotion: {deepface_dominant} ({deepface_dominant_score:.1f}%), "
        f"face_cropped={face_cropped}"
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

    # Prefer DeepFace's dominant mapping if it has strong confidence (>40%)
    # This prevents weak signals from flipping the mood
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

    return {
        "mood": dominant_mood,
        "confidence": confidence,
        "all_scores": mood_scores,
    }
