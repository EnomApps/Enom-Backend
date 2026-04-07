"""
Unit tests for image preprocessing and model inference.
Run: python -m pytest tests/ -v
"""

import base64
import io
import pytest
import numpy as np
from PIL import Image

import sys
import os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app.preprocessing import (
    decode_base64_image,
    validate_image,
    preprocess_for_model,
    process_image,
    ImagePreprocessingError,
)


def create_test_image(width=100, height=100, fmt="JPEG") -> str:
    """Create a base64-encoded test image."""
    img = Image.new("RGB", (width, height), color=(128, 128, 128))
    buffer = io.BytesIO()
    img.save(buffer, format=fmt)
    return base64.b64encode(buffer.getvalue()).decode("utf-8")


def create_test_image_with_prefix(fmt="JPEG") -> str:
    """Create a base64 image with data URI prefix."""
    b64 = create_test_image(fmt=fmt)
    mime = "image/jpeg" if fmt == "JPEG" else "image/png"
    return f"data:{mime};base64,{b64}"


class TestDecodeBase64:
    def test_valid_base64(self):
        b64 = create_test_image()
        result = decode_base64_image(b64)
        assert isinstance(result, bytes)
        assert len(result) > 0

    def test_valid_base64_with_prefix(self):
        b64 = create_test_image_with_prefix()
        result = decode_base64_image(b64)
        assert isinstance(result, bytes)

    def test_invalid_base64(self):
        with pytest.raises(ImagePreprocessingError) as exc:
            decode_base64_image("not-valid-base64!!!")
        assert exc.value.code == "INVALID_BASE64"


class TestValidateImage:
    def test_valid_jpeg(self):
        b64 = create_test_image(fmt="JPEG")
        image_bytes = base64.b64decode(b64)
        img = validate_image(image_bytes)
        assert img.format == "JPEG"

    def test_valid_png(self):
        b64 = create_test_image(fmt="PNG")
        image_bytes = base64.b64decode(b64)
        img = validate_image(image_bytes)
        assert img.format == "PNG"

    def test_too_large(self):
        # Create image larger than 1MB
        large_img = Image.new("RGB", (2000, 2000), color=(255, 0, 0))
        buffer = io.BytesIO()
        large_img.save(buffer, format="PNG")
        large_bytes = buffer.getvalue()

        if len(large_bytes) <= 1024 * 1024:
            pytest.skip("Test image not large enough")

        with pytest.raises(ImagePreprocessingError) as exc:
            validate_image(large_bytes)
        assert exc.value.code == "IMAGE_TOO_LARGE"

    def test_invalid_data(self):
        with pytest.raises(ImagePreprocessingError) as exc:
            validate_image(b"not an image")
        assert exc.value.code == "INVALID_IMAGE"


class TestPreprocess:
    def test_output_shape(self):
        img = Image.new("RGB", (200, 200))
        result = preprocess_for_model(img)
        assert result.shape == (48, 48, 3)

    def test_rgba_conversion(self):
        img = Image.new("RGBA", (200, 200))
        result = preprocess_for_model(img)
        assert result.shape == (48, 48, 3)

    def test_grayscale_conversion(self):
        img = Image.new("L", (200, 200))
        result = preprocess_for_model(img)
        assert result.shape == (48, 48, 3)


class TestFullPipeline:
    def test_full_pipeline(self):
        b64 = create_test_image()
        processed, img = process_image(b64)
        assert processed.shape == (48, 48, 3)
        assert isinstance(img, Image.Image)

    def test_pipeline_with_prefix(self):
        b64 = create_test_image_with_prefix()
        processed, img = process_image(b64)
        assert processed.shape == (48, 48, 3)
