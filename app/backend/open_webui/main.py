"""Backend principal de OpenWebUI para pruebas de integración."""
from fastapi import FastAPI

app = FastAPI(title="OpenWebUI")

# ---- Cargar endpoint personalizado de grupos ----
try:
    from open_webui.extensions import groups_api

    app.include_router(groups_api.router)
    print("✅ Endpoint /api/groups cargado correctamente")
except Exception as e:  # pragma: no cover - logging for troubleshooting
    print(f"⚠️ No se pudo cargar la extensión de grupos: {e}")
# -------------------------------------------------


@app.get("/")
async def root():
    """Endpoint raíz de verificación."""
    return {"status": "ok"}
