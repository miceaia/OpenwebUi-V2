"""Minimal FastAPI application entrypoint for OpenWebUI backend tests."""

from __future__ import annotations

import sys
from pathlib import Path

from fastapi import FastAPI

CURRENT_DIR = Path(__file__).resolve().parent
if str(CURRENT_DIR) not in sys.path:
    sys.path.append(str(CURRENT_DIR))

from routes import groups  # noqa: E402  # pylint: disable=wrong-import-position

app = FastAPI(title="OpenWebUI")
app.include_router(groups.router)
