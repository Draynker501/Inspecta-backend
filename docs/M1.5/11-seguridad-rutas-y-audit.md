# Seguridad de superficie de rutas y audit (P5.5a)

## Rutas desactivadas

### `GET /sanctum/csrf-cookie`
- **Qué era:** ruta de Sanctum para emitir la cookie CSRF (`XSRF-TOKEN`) usada por SPAs con autenticación por cookie.
- **Por qué se desactiva:** la API de INSPECTA autentica por token Bearer (`EnsureApiAuthenticated`) y `config/cors.php` tiene `supports_credentials = false`. La ruta no se usaba y exponía una superficie innecesaria.
- **Cómo se desactiva:** `'routes' => false` en `config/sanctum.php` (el `SanctumServiceProvider` no registra la ruta cuando ese valor es `false`).
- **Para reactivar:** cambiar a `true` (o eliminar la clave) y actualizar `tests/Feature/Api/RouteSurfaceTest.php` para permitir `sanctum/csrf-cookie`.

### `GET|PUT /storage/{path}`
- **Qué era:** servido de archivos del disco `local` por URL firmada (Laravel `FilesystemServiceProvider`, con `serve => true`).
- **Por qué se desactiva:** `EvidenceController` es solo stub y no hay uso de `Storage::temporaryUrl()`/URLs firmadas. El servido quedaba expuesto y devolvía 403 sin firma.
- **Cómo se desactiva:** `'serve' => false` en el disco `local` de `config/filesystems.php`.
- **Para reactivar:** poner `'serve' => true` y actualizar `tests/Feature/Api/RouteSurfaceTest.php` para permitir `storage/{path}`.

## Prueba de superficie de rutas
- Archivo: `tests/Feature/Api/RouteSurfaceTest.php`.
- Recorre todas las rutas registradas y exige que cada URI sea `api/*`, `up` o `/` (excepción documentada en la propia prueba).
- Verifica explícitamente que `sanctum/csrf-cookie` y `storage/{path}` no están registradas.
- Si se reactiva cualquiera de las rutas desactivadas, la prueba debe actualizarse de forma explícita (decisión consciente).

## Audit bloqueante
- Nuevo workflow `.github/workflows/security.yml` ("Security", job "Composer audit") ejecuta `composer audit --locked --no-interaction` **sin** `continue-on-error` (bloqueante).
- Disparadores: `pull_request`, `push` a `master`, `schedule` diario y `workflow_dispatch`.
- Se eliminó el paso de audit (con `continue-on-error: true`) de `.github/workflows/tests-mariadb.yml`.

## Advertencia
Reactivar el servido de archivos o la autenticación por cookie debe ser una **decisión explícita**, acompañada de la actualización de la prueba de superficie de rutas y, en su caso, de la configuración de CORS (`supports_credentials`).
