"""FastAPI route for exposing OpenWebUI groups information.

This module defines the ``/api/groups`` endpoint, secured with ``JWTBearer``
authentication. It queries the SQLite database used by OpenWebUI and falls
back to a JSON file when the database is unavailable. The endpoint returns a
list of groups with an aggregated member count, matching the requirements of
external integrations such as the WordPress plugin described in the task
instructions.
"""

from __future__ import annotations

import json
import logging
import os
import sqlite3
from pathlib import Path
from typing import Dict, List, Optional

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse

# ``JWTBearer`` lives in ``app.backend.auth`` inside OpenWebUI.  To keep the
# module compatible with different packaging layouts we try a couple of import
# paths that appear in upstream versions.  Falling back to a stub makes unit
# testing possible even when the authentication utilities are absent.
try:  # pragma: no cover - import path depends on the packaging layout
    from ..auth.jwt import JWTBearer  # type: ignore
except ImportError:  # pragma: no cover
    try:
        from ..auth.auth_bearer import JWTBearer  # type: ignore
    except ImportError:  # pragma: no cover
        try:
            from backend.auth.jwt import JWTBearer  # type: ignore
        except ImportError:  # pragma: no cover
            try:
                from backend.auth.auth_bearer import JWTBearer  # type: ignore
            except ImportError as exc:  # pragma: no cover
                raise ImportError(
                    "JWTBearer authentication dependency is missing."
                ) from exc


LOGGER = logging.getLogger(__name__)
router = APIRouter(prefix="/api", tags=["groups"])

DB_PATH = Path("/data/db.sqlite3")
JSON_FALLBACK_PATH = Path("/data/groups.json")

GroupRecord = Dict[str, int | str]

ENABLE_GROUPS_API = os.getenv("ENABLE_GROUPS_API", "false").lower() in {"1", "true", "yes"}


class GroupDataError(Exception):
    """Custom exception used when database access fails."""


def _safe_int(value: object, default: int | None = None) -> int | None:
    """Attempt to coerce a value to ``int`` without raising errors."""
    if value is None:
        return default
    try:
        return int(value)
    except (TypeError, ValueError):
        try:
            return int(float(value))
        except (TypeError, ValueError):
            return default


def _normalize_group_key(value: object) -> int | str:
    """Normalize database keys so lookups work with mixed types."""
    coerced = _safe_int(value)
    if coerced is not None:
        return coerced
    if isinstance(value, (bytes, bytearray)):
        return value.decode("utf-8")
    return value if isinstance(value, str) else str(value)


def _resolve_membership_table(cursor: sqlite3.Cursor) -> Optional[Dict[str, str]]:
    """Detect an auxiliary membership table and the relevant columns.

    OpenWebUI installations may create different table names for the group
    membership relationship (``group_members``, ``group_users``, ``user_groups``
    …).  This helper inspects the schema dynamically to discover a suitable
    table and returns a dictionary that maps the ``table`` name and the column
    used to reference the group identifier.
    """

    candidate_tables = (
        "group_members",
        "group_users",
        "groups_users",
        "user_groups",
        "group_memberships",
    )

    for table in candidate_tables:
        cursor.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?", (table,)
        )
        if cursor.fetchone() is None:
            continue

        cursor.execute(f"PRAGMA table_info({table})")
        columns = {column[1] for column in cursor.fetchall()}
        for group_column in ("group_id", "groupId", "group", "group_uuid"):
            if group_column in columns:
                return {"table": table, "column": group_column}

    return None


def _fetch_groups_from_database() -> Optional[List[GroupRecord]]:
    """Return groups from the SQLite database or ``None`` when unavailable."""

    if not DB_PATH.exists():
        LOGGER.info("Groups database not found at %s", DB_PATH)
        return None

    try:
        with sqlite3.connect(DB_PATH) as connection:
            connection.row_factory = sqlite3.Row
            cursor = connection.cursor()

            cursor.execute(
                """
                SELECT name FROM sqlite_master
                WHERE type='table' AND name IN ('groups', 'group')
                """
            )
            table_row = cursor.fetchone()
            if table_row is None:
                LOGGER.warning("No groups table present in SQLite database")
                return []

            groups_table = table_row["name"]
            cursor.execute(f"PRAGMA table_info({groups_table})")
            table_columns = {column[1] for column in cursor.fetchall()}

            id_column = "id" if "id" in table_columns else "uuid"
            name_column = "name" if "name" in table_columns else None
            members_column = None
            for candidate in ("members", "member_count", "members_count"):
                if candidate in table_columns:
                    members_column = candidate
                    break

            if name_column is None:
                raise GroupDataError(
                    "La tabla de grupos no contiene una columna de nombre válida"
                )

            cursor.execute(
                f"SELECT {id_column} as id, {name_column} as name FROM {groups_table}"
            )
            rows = cursor.fetchall()

            groups: List[GroupRecord] = []

            if members_column:
                cursor.execute(
                    f"SELECT {id_column} as id, {members_column} as members FROM {groups_table}"
                )
                member_values = {
                    _normalize_group_key(row["id"]): _safe_int(row["members"], 0) or 0
                    for row in cursor.fetchall()
                }
            else:
                member_values: Dict[int | str, int] = {}
                membership_info = _resolve_membership_table(cursor)
                if membership_info:
                    table = membership_info["table"]
                    column = membership_info["column"]
                    cursor.execute(
                        f"SELECT {column} as group_id, COUNT(*) as member_count "
                        f"FROM {table} GROUP BY {column}"
                    )
                    member_values = {
                        _normalize_group_key(row["group_id"]): _safe_int(row["member_count"], 0) or 0
                        for row in cursor.fetchall()
                    }

            for row in rows:
                normalized_id = _normalize_group_key(row["id"])
                groups.append(
                    {
                        "id": normalized_id,
                        "name": row["name"],
                        "members": member_values.get(normalized_id, 0),
                    }
                )

            return groups
    except (sqlite3.Error, GroupDataError) as exc:
        LOGGER.exception("Error al obtener grupos desde la base de datos: %s", exc)
        raise GroupDataError("No se pudo obtener la lista de grupos desde SQLite") from exc


def _load_groups_from_json() -> Optional[List[GroupRecord]]:
    """Return groups from the JSON fallback file when present."""

    if not JSON_FALLBACK_PATH.exists():
        return None

    try:
        with JSON_FALLBACK_PATH.open("r", encoding="utf-8") as file_pointer:
            payload = json.load(file_pointer)
    except (OSError, json.JSONDecodeError) as exc:
        LOGGER.exception("Error al leer el archivo JSON de grupos: %s", exc)
        raise GroupDataError("No se pudo leer el archivo groups.json") from exc

    groups_data = payload.get("groups")
    if not isinstance(groups_data, list):
        LOGGER.warning("El archivo JSON de grupos no contiene la clave 'groups'")
        return []

    groups: List[GroupRecord] = []
    for entry in groups_data:
        if not isinstance(entry, dict):
            continue
        group_id = entry.get("id")
        name = entry.get("name")
        members = entry.get("members", 0)
        if group_id is None or name is None:
            continue
        normalized_id = _normalize_group_key(group_id)
        member_count = _safe_int(members, 0) or 0
        groups.append(
            {
                "id": normalized_id,
                "name": str(name),
                "members": member_count,
            }
        )

    return groups


@router.get(
    "/groups",
    dependencies=[Depends(JWTBearer())],
    response_class=JSONResponse,
)
async def list_groups() -> JSONResponse:
    """Return the list of OpenWebUI groups for authenticated clients."""

    if not ENABLE_GROUPS_API:
        return JSONResponse(
            {"error": "La API de grupos no está habilitada"},
            status_code=503,
        )

    db_error = False
    groups: Optional[List[GroupRecord]] = None

    try:
        groups = _fetch_groups_from_database()
    except GroupDataError:
        db_error = True

    if groups is None or len(groups) == 0:
        try:
            groups = _load_groups_from_json()
        except GroupDataError:
            groups = None

    if groups is None:
        status_code = 500 if db_error else 503
        return JSONResponse(
            {"error": "No se pudo conectar con la base de datos o leer los grupos"},
            status_code=status_code,
        )

    if len(groups) == 0:
        return JSONResponse(
            {"groups": [], "message": "No se encontraron grupos en la base de datos"},
            status_code=200,
        )

    # ``groups`` contains dictionaries with ``id``, ``name`` and ``members``.
    return JSONResponse({"groups": groups}, status_code=200)


__all__ = ["router"]
