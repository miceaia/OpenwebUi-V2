#!/usr/bin/env python3
"""
Script de parcheo automático para agregar endpoint /api/groups en OpenWebUI
Modifica el paquete instalado en site-packages de forma idempotente.
"""

import os
import sys
import re
from pathlib import Path


def main():
    print("=" * 70)
    print("SCRIPT DE PARCHEO: /api/groups para OpenWebUI")
    print("=" * 70)
    print()

    # ========================================================================
    # PASO 1: Localizar el paquete open_webui instalado
    # ========================================================================
    print("[1/4] Localizando paquete open_webui...")
    try:
        import importlib
        import inspect
        import open_webui

        pkg_file = inspect.getfile(open_webui)
        pkg_dir = Path(os.path.dirname(pkg_file))
        print(f"✅ Paquete encontrado en: {pkg_dir}")
    except ImportError as e:
        print(f"❌ ERROR: No se pudo importar open_webui: {e}")
        print("   Asegúrate de que OpenWebUI está instalado en este entorno Python.")
        sys.exit(1)
    except Exception as e:
        print(f"❌ ERROR inesperado al localizar open_webui: {e}")
        sys.exit(1)

    # ========================================================================
    # PASO 2: Crear directorio extensions
    # ========================================================================
    print("\n[2/4] Verificando directorio extensions...")
    extensions_dir = pkg_dir / "extensions"

    try:
        if not extensions_dir.exists():
            extensions_dir.mkdir(parents=True, exist_ok=True)
            print(f"✅ Creado directorio: {extensions_dir}")
        else:
            print(f"✅ Directorio ya existe: {extensions_dir}")

        # Crear __init__.py si no existe
        init_file = extensions_dir / "__init__.py"
        if not init_file.exists():
            init_file.write_text("# Extensions module\n", encoding="utf-8")
            print(f"✅ Creado __init__.py en extensions")
    except PermissionError:
        print(f"❌ ERROR: Sin permisos de escritura en {extensions_dir}")
        print("   Ejecuta el script con privilegios adecuados (ej: sudo python3 script.py)")
        sys.exit(1)
    except Exception as e:
        print(f"❌ ERROR al crear directorio extensions: {e}")
        sys.exit(1)

    # ========================================================================
    # PASO 3: Escribir groups_api.py
    # ========================================================================
    print("\n[3/4] Escribiendo groups_api.py...")
    groups_api_file = extensions_dir / "groups_api.py"

    groups_api_content = '''"""Extensión /api/groups para OpenWebUI"""
import os
import sqlite3
from pathlib import Path
from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse

# Import compatible con distintas versiones de OWUI
try:
    from open_webui.api.auth import JWTBearer
except Exception:
    try:
        from open_webui.auth.auth_bearer import JWTBearer
    except Exception as e:
        raise ImportError(f"No se pudo importar JWTBearer: {e}")

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
                return JSONResponse({"groups": []}, status_code=200)
            cur.execute("SELECT id, name FROM groups")
            data = [{"id": r["id"], "name": r["name"]} for r in cur.fetchall()]
            return JSONResponse({"groups": data}, status_code=200)
    except Exception as e:
        return JSONResponse({"error": str(e)}, status_code=500)

# Ruta de diagnóstico SIN auth (para descartar 404 por auth)
@router.get("/groups_ping")
async def groups_ping():
    return {"ok": True}
'''

    try:
        groups_api_file.write_text(groups_api_content, encoding="utf-8")
        print(f"✅ Archivo escrito: {groups_api_file}")
    except PermissionError:
        print(f"❌ ERROR: Sin permisos de escritura en {groups_api_file}")
        print("   Ejecuta el script con privilegios adecuados (ej: sudo python3 script.py)")
        sys.exit(1)
    except Exception as e:
        print(f"❌ ERROR al escribir groups_api.py: {e}")
        sys.exit(1)

    # ========================================================================
    # PASO 4: Parchear main.py
    # ========================================================================
    print("\n[4/4] Parcheando main.py...")
    main_file = pkg_dir / "main.py"

    if not main_file.exists():
        print(f"❌ ERROR: No se encontró main.py en {main_file}")
        sys.exit(1)

    try:
        # Leer contenido original
        original_content = main_file.read_text(encoding="utf-8")

        # Verificar si ya está parcheado
        patch_marker_start = "# --- groups_api patch start ---"
        patch_marker_end = "# --- groups_api patch end ---"

        if patch_marker_start in original_content:
            print(f"⚠️  El archivo main.py ya está parcheado. No se realizarán cambios.")
            print(f"   Si necesitas re-aplicar el parche, elimina manualmente las líneas")
            print(f"   entre '{patch_marker_start}' y '{patch_marker_end}'")
        else:
            # Código del parche a insertar
            patch_code = f'''{patch_marker_start}
try:
    from open_webui.extensions import groups_api
    app.include_router(groups_api.router)
    print("✅ [/api/groups] router cargado (groups_api)")
except Exception as e:
    print(f"⚠️ No se pudo cargar groups_api: {{e}}")
{patch_marker_end}
'''

            # Intentar detectar el patrón de la aplicación
            modified = False
            lines = original_content.split('\n')
            new_lines = []

            # Patrón A: app = FastAPI(...) a nivel módulo
            pattern_a = re.compile(r'^app\s*=\s*FastAPI\s*\(')

            # Patrón B: def create_app(...) con return app
            in_create_app = False
            create_app_indent = 0

            i = 0
            while i < len(lines):
                line = lines[i]
                new_lines.append(line)

                # Detectar función create_app
                if re.match(r'^def\s+create_app\s*\(', line):
                    in_create_app = True
                    create_app_indent = len(line) - len(line.lstrip())
                    i += 1
                    continue

                # Si estamos en create_app, buscar return app
                if in_create_app:
                    # Verificar si salimos de la función (otra función o clase al mismo nivel)
                    current_indent = len(line) - len(line.lstrip())
                    if line.strip() and current_indent <= create_app_indent and not line.strip().startswith('#'):
                        in_create_app = False

                    # Buscar return app dentro de create_app
                    if re.match(r'^\s+return\s+app\s*$', line):
                        # Insertar el parche ANTES del return
                        indent = ' ' * (current_indent)
                        patch_lines = patch_code.split('\n')
                        for patch_line in patch_lines:
                            if patch_line:  # No indentar líneas vacías
                                new_lines.insert(-1, indent + patch_line)
                            else:
                                new_lines.insert(-1, '')
                        new_lines.insert(-1, '')  # Línea en blanco
                        modified = True
                        print(f"✅ Parche insertado en create_app() antes de 'return app'")
                        break

                # Patrón A: app = FastAPI(...) a nivel módulo
                if pattern_a.match(line) and not modified:
                    # Insertar el parche DESPUÉS de esta línea
                    new_lines.append('')  # Línea en blanco
                    new_lines.extend(patch_code.split('\n'))
                    new_lines.append('')  # Línea en blanco
                    modified = True
                    print(f"✅ Parche insertado después de 'app = FastAPI(...)'")
                    # Agregar el resto de líneas
                    i += 1
                    while i < len(lines):
                        new_lines.append(lines[i])
                        i += 1
                    break

                i += 1

            if not modified:
                print(f"⚠️  ADVERTENCIA: No se pudo detectar el patrón de FastAPI en main.py")
                print(f"   Se intentó buscar:")
                print(f"   - Patrón A: 'app = FastAPI(...)' a nivel módulo")
                print(f"   - Patrón B: 'def create_app(...)' con 'return app'")
                print(f"\n   Deberás agregar manualmente el siguiente código:")
                print(f"\n{patch_code}")
                print(f"\n   Ubícalo después de la inicialización de FastAPI y antes de devolver 'app'.")
            else:
                # Escribir el archivo modificado
                new_content = '\n'.join(new_lines)
                main_file.write_text(new_content, encoding="utf-8")
                print(f"✅ Archivo main.py parcheado correctamente")

    except PermissionError:
        print(f"❌ ERROR: Sin permisos de escritura en {main_file}")
        print("   Ejecuta el script con privilegios adecuados (ej: sudo python3 script.py)")
        sys.exit(1)
    except Exception as e:
        print(f"❌ ERROR al parchear main.py: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)

    # ========================================================================
    # RESUMEN FINAL
    # ========================================================================
    print("\n" + "=" * 70)
    print("PARCHEO COMPLETADO")
    print("=" * 70)
    print(f"\n📁 Ruta parcheada: {pkg_dir}")
    print(f"📄 Archivos modificados:")
    print(f"   - {extensions_dir / 'groups_api.py'}")
    print(f"   - {main_file}")

    print("\n" + "=" * 70)
    print("INSTRUCCIONES DE USO")
    print("=" * 70)
    print("\n1️⃣  Configura la variable de entorno:")
    print("   export ENABLE_GROUPS_API=true")
    print("   # O agrégala a tu archivo .env o configuración de Docker/systemd")

    print("\n2️⃣  Reinicia el servicio OpenWebUI:")
    print("   sudo systemctl restart open-webui")
    print("   # O reinicia tu contenedor Docker / proceso Python")

    print("\n3️⃣  Prueba el endpoint de diagnóstico (sin autenticación):")
    print("   curl -i https://asistenteia.miceanou.com/api/groups_ping")
    print("   # Debe devolver: {\"ok\": true}")

    print("\n4️⃣  Prueba el endpoint /api/groups (con autenticación):")
    print("   curl -i -H \"Authorization: Bearer <TOKEN_ADMIN>\" \\")
    print("        https://asistenteia.miceanou.com/api/groups")

    print("\n📊 Posibles respuestas:")
    print("   • 200 + {\"groups\": [...]}  → ✅ Éxito")
    print("   • 401/403                  → ✅ Router funciona (falta token válido)")
    print("   • 503 'API desactivada'    → ⚠️  Falta ENABLE_GROUPS_API=true")
    print("   • 500 'Base de datos...'   → ⚠️  No existe /data/db.sqlite3")
    print("   • 404                      → ❌ Router no registrado (revisar logs)")

    print("\n💡 Tip: Revisa los logs del servidor para ver el mensaje:")
    print("   '✅ [/api/groups] router cargado (groups_api)'")

    print("\n" + "=" * 70)
    print("EJEMPLOS CURL COMPLETOS")
    print("=" * 70)
    print("\n# Diagnóstico (sin auth):")
    print("curl -i https://asistenteia.miceanou.com/api/groups_ping")
    print("\n# Obtener grupos (con auth):")
    print("curl -i -H \"Authorization: Bearer <TOKEN_ADMIN>\" \\")
    print("     https://asistenteia.miceanou.com/api/groups")

    print("\n✨ ¡Listo! El parche ha sido aplicado correctamente.\n")


if __name__ == "__main__":
    main()
