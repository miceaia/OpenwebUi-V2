# Parche Automático: API de Grupos para OpenWebUI

## 📋 Descripción

Este script parchea automáticamente tu instalación de OpenWebUI (v0.6.33) para agregar el endpoint `/api/groups` que permite consultar grupos de usuarios desde la base de datos SQLite.

**Características:**
- ✅ Modifica directamente el paquete instalado en site-packages
- ✅ Idempotente (se puede ejecutar múltiples veces sin duplicar código)
- ✅ Compatible con diferentes estructuras de `main.py`
- ✅ Incluye endpoint de diagnóstico `/api/groups_ping` (sin autenticación)
- ✅ Manejo robusto de errores

---

## 🚀 Uso Rápido

### 1. Ejecutar el script de parcheo

```bash
# Si usas el entorno virtual de OpenWebUI, actívalo primero
source /path/to/venv/bin/activate

# Ejecutar el script (puede requerir sudo si el paquete está en system)
python3 patch_openwebui_groups.py

# O con sudo si es necesario:
sudo python3 patch_openwebui_groups.py
```

### 2. Configurar variable de entorno

**Opción A: Variable de entorno temporal**
```bash
export ENABLE_GROUPS_API=true
```

**Opción B: Archivo .env (recomendado)**
```bash
# Agregar a tu archivo .env de OpenWebUI
echo "ENABLE_GROUPS_API=true" >> /path/to/openwebui/.env
```

**Opción C: Configuración systemd**
```ini
# /etc/systemd/system/open-webui.service
[Service]
Environment="ENABLE_GROUPS_API=true"
```

**Opción D: Docker Compose**
```yaml
services:
  open-webui:
    environment:
      - ENABLE_GROUPS_API=true
```

### 3. Reiniciar OpenWebUI

```bash
# Systemd
sudo systemctl restart open-webui

# Docker
docker restart open-webui

# Proceso manual
pkill -f "open_webui" && open_webui serve
```

---

## 🧪 Pruebas

### Test 1: Endpoint de diagnóstico (sin autenticación)

```bash
curl -i https://asistenteia.miceanou.com/api/groups_ping
```

**Respuesta esperada:**
```http
HTTP/1.1 200 OK
Content-Type: application/json

{"ok": true}
```

✅ Si obtienes `200 + {"ok": true}` → El router está registrado correctamente

❌ Si obtienes `404` → El router no se cargó (revisa logs del servidor)

---

### Test 2: Endpoint de grupos (con autenticación)

**Paso 1: Obtener un token de administrador**
- Inicia sesión en OpenWebUI como admin
- Abre DevTools (F12) → Pestaña Network
- Busca el header `Authorization: Bearer <tu_token>`
- Copia el token

**Paso 2: Hacer la petición**
```bash
curl -i -H "Authorization: Bearer <TU_TOKEN_AQUI>" \
     https://asistenteia.miceanou.com/api/groups
```

**Posibles respuestas:**

| Código | Mensaje | Significado |
|--------|---------|-------------|
| `200` | `{"groups": [...]}` | ✅ Éxito - API funcionando |
| `401` | `Unauthorized` | ✅ Router OK - Token inválido |
| `403` | `Forbidden` | ✅ Router OK - Sin permisos |
| `503` | `API de grupos desactivada` | ⚠️ Falta `ENABLE_GROUPS_API=true` |
| `500` | `No se encontró la base de datos` | ⚠️ No existe `/data/db.sqlite3` |
| `404` | `Not Found` | ❌ Router no registrado |

---

## 🔍 Verificación en Logs

Cuando el servidor arranca correctamente, deberías ver:

```
✅ [/api/groups] router cargado (groups_api)
```

Si ves:
```
⚠️ No se pudo cargar groups_api: <error>
```
Revisa el error específico (problemas de import, permisos, etc.)

---

## 📂 Archivos Modificados

El script crea/modifica estos archivos en tu instalación:

```
<site-packages>/open_webui/
├── extensions/
│   ├── __init__.py          [CREADO]
│   └── groups_api.py        [CREADO]
└── main.py                  [MODIFICADO]
```

**Nota:** No modifica archivos del proyecto local, solo el paquete instalado.

---

## 🔄 Re-aplicar o Revertir

### Re-aplicar el parche
El script es idempotente. Si ya está aplicado, mostrará:
```
⚠️ El archivo main.py ya está parcheado. No se realizarán cambios.
```

Para forzar re-aplicación:
1. Busca en `main.py` las líneas entre `# --- groups_api patch start ---` y `# --- groups_api patch end ---`
2. Elimínalas manualmente
3. Vuelve a ejecutar el script

### Revertir el parche
```bash
# Reinstalar OpenWebUI limpio
pip install --force-reinstall open-webui==0.6.33
```

---

## ⚙️ Configuración de Base de Datos

El endpoint espera la base de datos en:
```
/data/db.sqlite3
```

Si tu base de datos está en otra ubicación, modifica la línea en `groups_api.py`:
```python
DB_PATH = Path("/ruta/a/tu/db.sqlite3")
```

**Esquema esperado:**
```sql
CREATE TABLE groups (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
    -- ... otros campos opcionales
);
```

---

## 🐛 Solución de Problemas

### Problema: "Sin permisos de escritura"
**Solución:** Ejecuta con sudo o cambia permisos:
```bash
sudo python3 patch_openwebui_groups.py
```

### Problema: "No se pudo importar open_webui"
**Solución:** Activa el entorno virtual correcto:
```bash
source /path/to/venv/bin/activate
python3 patch_openwebui_groups.py
```

### Problema: "No se pudo detectar el patrón de FastAPI"
**Solución:** Aplica el parche manualmente. El script te mostrará el código exacto a insertar.

### Problema: 404 después de aplicar el parche
**Checklist:**
1. ✅ ¿Reiniciaste el servidor?
2. ✅ ¿Los logs muestran "router cargado"?
3. ✅ ¿El archivo `groups_api.py` existe en `extensions/`?
4. ✅ ¿El código del parche está en `main.py`?

---

## 📞 Soporte

Si encuentras problemas:
1. Revisa los logs del servidor OpenWebUI
2. Verifica que los archivos se crearon correctamente
3. Comprueba permisos de lectura/escritura
4. Asegúrate de usar Python del mismo entorno que ejecuta OpenWebUI

---

## 📄 Licencia

Este parche es código de utilidad para OpenWebUI. Úsalo bajo tu propia responsabilidad.

**Versión:** 1.0
**Compatible con:** OpenWebUI v0.6.33
**Última actualización:** 2025-10-29
