import os

# Server
HOST = os.getenv("MOOD_HOST", "127.0.0.1")
PORT = int(os.getenv("MOOD_PORT", "8001"))

# Redis
REDIS_HOST = os.getenv("REDIS_HOST", "127.0.0.1")
REDIS_PORT = int(os.getenv("REDIS_PORT", "6379"))

# Rate Limiting
RATE_LIMIT_MAX = int(os.getenv("RATE_LIMIT_MAX", "10"))       # requests
RATE_LIMIT_WINDOW = int(os.getenv("RATE_LIMIT_WINDOW", "60"))  # seconds

# Image
MAX_IMAGE_SIZE = 1 * 1024 * 1024  # 1MB
ACCEPTED_FORMATS = ["image/jpeg", "image/png"]
MODEL_INPUT_SIZE = (48, 48)  # FER model expects 48x48

# JWT - shared secret with Laravel Sanctum
# For Sanctum, we'll validate tokens via Laravel's API
LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://127.0.0.1")

# Mood mapping: FER outputs -> Our labels
MOOD_MAP = {
    "happy": "Happy",
    "neutral": "Neutral",
    "sad": "Low",
    "angry": "Angry",
    "surprise": "Happy",    # map surprise to Happy
    "fear": "Low",          # map fear to Low
    "disgust": "Angry",     # map disgust to Angry
}
