"""
Authentication module.
Validates Bearer tokens by calling Laravel's /api/user/profile endpoint.
"""

import logging
import httpx
from app.config import LARAVEL_API_URL

logger = logging.getLogger("mood-service")


class AuthError(Exception):
    def __init__(self, message: str = "Unauthenticated"):
        self.message = message
        super().__init__(message)


async def validate_token(token: str) -> dict:
    """
    Validate a Bearer token by calling Laravel Sanctum.
    Returns user data if valid.
    """
    if not token:
        raise AuthError("No token provided.")

    # Clean up token (remove "Bearer " prefix if present)
    if token.startswith("Bearer "):
        token = token[7:]

    try:
        async with httpx.AsyncClient(timeout=5.0) as client:
            response = await client.get(
                f"{LARAVEL_API_URL}/api/user/profile",
                headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
            )

        if response.status_code == 200:
            data = response.json()
            user = data.get("user", {})
            return {
                "user_id": str(user.get("id", "")),
                "name": user.get("name", ""),
            }
        else:
            raise AuthError("Invalid or expired token.")

    except httpx.RequestError as e:
        logger.error(f"Auth request failed: {e}")
        raise AuthError("Authentication service unavailable.")
