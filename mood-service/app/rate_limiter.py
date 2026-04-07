"""
Sliding window rate limiter using Redis.
Limits: 10 requests per user per minute.
"""

import time
import logging
import redis
from app.config import REDIS_HOST, REDIS_PORT, RATE_LIMIT_MAX, RATE_LIMIT_WINDOW

logger = logging.getLogger("mood-service")

_redis_client = None


def get_redis() -> redis.Redis:
    """Get Redis client (lazy initialization)."""
    global _redis_client
    if _redis_client is None:
        try:
            _redis_client = redis.Redis(
                host=REDIS_HOST,
                port=REDIS_PORT,
                db=2,  # Use separate DB for mood rate limiting
                decode_responses=True,
            )
            _redis_client.ping()
            logger.info("Redis connected for rate limiting.")
        except redis.ConnectionError:
            logger.warning("Redis not available. Rate limiting disabled.")
            _redis_client = None
    return _redis_client


class RateLimitExceeded(Exception):
    def __init__(self, retry_after: int):
        self.retry_after = retry_after
        super().__init__(f"Rate limit exceeded. Retry after {retry_after} seconds.")


def check_rate_limit(user_id: str) -> dict:
    """
    Check if user has exceeded rate limit.
    Uses Redis sorted set with sliding window.

    Returns:
        {"remaining": int, "limit": int, "reset": int}

    Raises:
        RateLimitExceeded if limit exceeded.
    """
    r = get_redis()
    if r is None:
        # Redis not available, skip rate limiting
        return {"remaining": RATE_LIMIT_MAX, "limit": RATE_LIMIT_MAX, "reset": 0}

    key = f"mood_ratelimit:{user_id}"
    now = time.time()
    window_start = now - RATE_LIMIT_WINDOW

    pipe = r.pipeline()

    # Remove expired entries
    pipe.zremrangebyscore(key, 0, window_start)

    # Count current requests in window
    pipe.zcard(key)

    # Add current request
    pipe.zadd(key, {f"{now}": now})

    # Set expiry on the key
    pipe.expire(key, RATE_LIMIT_WINDOW)

    results = pipe.execute()
    request_count = results[1]  # zcard result

    remaining = max(0, RATE_LIMIT_MAX - request_count - 1)

    if request_count >= RATE_LIMIT_MAX:
        # Get oldest entry to calculate retry-after
        oldest = r.zrange(key, 0, 0, withscores=True)
        if oldest:
            retry_after = int(RATE_LIMIT_WINDOW - (now - oldest[0][1])) + 1
        else:
            retry_after = RATE_LIMIT_WINDOW

        raise RateLimitExceeded(retry_after=max(1, retry_after))

    return {
        "remaining": remaining,
        "limit": RATE_LIMIT_MAX,
        "reset": int(now + RATE_LIMIT_WINDOW),
    }
