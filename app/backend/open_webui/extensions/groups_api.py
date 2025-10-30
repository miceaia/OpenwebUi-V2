"""
Extensión que agrega el endpoint /api/groups a OpenWebUI.
"""

import os
import sqlite3
from pathlib import Path
from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse

# Autenticación basada en JWT del core
try:
    from open_webui.api.auth import JWTBearer
except ImportError:
    from open_webui.auth.auth_bearer import JWTBearer

router = APIRouter(prefix="/api", tags=["groups"])

DB_PATH = Path("/data/db.sqlite3")
ENABLE_GROUPS_API = os.getenv("ENABLE_GROUPS_API", "false").lower() in {"1", "true", "yes"}


@router.get("/groups", dependencies=[Depends(JWTBearer())])
async def get_groups():
    if not ENABLE_GROUPS_API:
        return JSONResponse({"error": "API de grupos desactivada"}, status_code=503)

    if not DB_PATH.exists():
        return JSONResponse({"error": "No se encontró la base de datos"}, status_code=500)

    try:
        with sqlite3.connect(DB_PATH) as conn:
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            cur.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='groups'")
            if not cur.fetchone():
                return JSONResponse({"groups": []})

            cur.execute("SELECT id, name FROM groups")
            data = [{"id": r["id"], "name": r["name"]} for r in cur.fetchall()]
            return JSONResponse({"groups": data})
    except Exception as e:
        return JSONResponse({"error": str(e)}, status_code=500)
