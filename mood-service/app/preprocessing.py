"""
Image preprocessing pipeline for mood detection.
- Decode base64
- Validate format and size
- Resize to model input size
- Convert to grayscale
- Normalize pixel values
"""

import base64
import io
import numpy as np
from PIL import Image
from app.config import MAX_IMAGE_SIZE, MODEL_INPUT_SIZE


class ImagePreprocessingError(Exception):
    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(message)


def decode_base64_image(base64_string: str) -> bytes:
    """Decode base64 string to raw image bytes."""
    # Remove data URI prefix if present (e.g., "data:image/jpeg;base64,")
    if "," in base64_string:
        base64_string = base64_string.split(",", 1)[1]

    try:
        image_bytes = base64.b64decode(base64_string)
    except Exception:
        raise ImagePreprocessingError(
            code="INVALID_BASE64",
            message="Invalid base64 encoding. Please provide a valid base64 string."
        )

    return image_bytes


def validate_image(image_bytes: bytes) -> Image.Image:
    """Validate image format and size."""
    # Check size
    if len(image_bytes) > MAX_IMAGE_SIZE:
        size_mb = len(image_bytes) / (1024 * 1024)
        raise ImagePreprocessingError(
            code="IMAGE_TOO_LARGE",
            message=f"Image size ({size_mb:.1f}MB) exceeds maximum allowed size (1MB)."
        )

    # Try opening the image
    try:
        img = Image.open(io.BytesIO(image_bytes))
        img.verify()
        # Re-open after verify (verify() closes the image)
        img = Image.open(io.BytesIO(image_bytes))
    except Exception:
        raise ImagePreprocessingError(
            code="INVALID_IMAGE",
            message="Cannot process image. Please provide a valid JPEG or PNG image."
        )

    # Check format
    if img.format not in ("JPEG", "PNG"):
        raise ImagePreprocessingError(
            code="UNSUPPORTED_FORMAT",
            message=f"Image format '{img.format}' is not supported. Use JPEG or PNG."
        )

    return img


def preprocess_for_model(img: Image.Image) -> np.ndarray:
    """
    Preprocess image for FER model:
    1. Convert to RGB (in case of RGBA/palette)
    2. Resize to model input size
    3. Return as numpy array (FER handles grayscale internally)
    """
    # Convert to RGB
    if img.mode != "RGB":
        img = img.convert("RGB")

    # Resize
    img = img.resize(MODEL_INPUT_SIZE, Image.Resampling.LANCZOS)

    # Convert to numpy array
    img_array = np.array(img)

    return img_array


def process_image(base64_string: str) -> np.ndarray:
    """Full preprocessing pipeline: decode -> validate -> preprocess."""
    image_bytes = decode_base64_image(base64_string)
    img = validate_image(image_bytes)
    processed = preprocess_for_model(img)
    return processed, img
