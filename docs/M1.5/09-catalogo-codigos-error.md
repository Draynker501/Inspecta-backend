# Catálogo de códigos y mensajes de error (D-31b, D-31c)

## Sobre de error uniforme

Toda respuesta de error JSON de la API tiene la forma:

```json
{ "message": string, "code": string, "errors"?: object }
```

- `message`: texto humano del error.
- `code`: código estable del catálogo.
- `errors`: solo presente en `validation_failed` (errores por campo).

## Regla mismo-código-mismo-mensaje

El mismo código produce siempre el mismo mensaje y el mismo estado HTTP en
toda la API. Los textos y estados están centralizados en
`App\Support\Api\ApiErrorResponse` (catálogo central), que es el único
constructor de respuestas de error.

Excepciones al mensaje fijo (mensaje dinámico por diseño, código fijo):

- `validation_failed`: el mensaje es el resumen del framework de
  validación; se conservan el mensaje y el campo `errors` del 422.
- `cross_tenant_operation`: el mensaje es el de la excepción concreta
  (`CrossTenantOperationException`), con dos variantes existentes
  (operación de escritura y referencia de FK).

## Catálogo

| Código | HTTP | Mensaje | Origen |
|---|---|---|---|
| `unauthenticated` | 401 | No autenticado. Token de acceso ausente o inválido. | `EnsureApiAuthenticated` (token ausente/inválido o usuario emisor inexistente), `ResolveTenantContext` (defensivo) y `Handler` (`AuthenticationException`) |
| `invalid_credentials` | 401 | Las credenciales proporcionadas son incorrectas. | `AuthController::login` (contraseña incorrecta) |
| `user_inactive` | 403 | El usuario está inactivo. Contacte al administrador. | `AuthController::login` y `EnsureApiAuthenticated` (usuario inactivo) |
| `validation_failed` | 422 | Resumen del framework de validación (dinámico). | `Handler` (`ValidationException`); conserva `errors` |
| `too_many_attempts` | 429 | Demasiados intentos. Inténtalo de nuevo más tarde. | `Handler` (`ThrottleRequestsException`); conserva `Retry-After` y `X-RateLimit-*` |
| `not_found` | 404 | Recurso no encontrado. | `Handler` (`NotFoundHttpException` y `ModelNotFoundException` convertida); no filtra modelo ni clase |
| `cross_tenant_operation` | 422 | Mensaje de la excepción (dinámico). | `CrossTenantOperationException::render` |
| `organization_required` | 400 | Cabecera X-Organization-Id requerida. | `ResolveTenantContext` |
| `organization_invalid` | 400 | El identificador de organización no es un UUID válido. | `ResolveTenantContext` |
| `organization_access_denied` | 403 | No tienes acceso a la organización indicada. | `ResolveTenantContext` (organización inexistente, eliminada, inactiva, sin membresía o membresía inactiva) |
| `organization_not_tenant` | 403 | No tienes acceso a la organización indicada. | `ResolveTenantContext` (organización que no es cliente) |

`TenantErrorCode` se conserva sin cambios (contrato público): sus
constantes siguen siendo los valores de `code` de contexto.

## Decisiones de unificación (D-31b)

- Los 403 de contexto (`organization_access_denied` y
  `organization_not_tenant`) comparten exactamente el mismo texto:
  el motivo solo se distingue por el código.
- `unauthenticated` unifica los tres textos anteriores («ausente»,
  «inválido», «ausente o inválido») en el texto existente
  «No autenticado. Token de acceso ausente o inválido.», que cubre los
  tres casos sin revelar si el token era ausente o inválido.
- En `login` la contraseña se valida antes que el estado del usuario:
  un usuario inactivo con contraseña incorrecta recibe
  `invalid_credentials`, no `user_inactive` (no se revela el estado de
  un usuario sin conocer su contraseña).
- `user_inactive` emite el mismo código y mensaje desde `login` y desde
  `EnsureApiAuthenticated`.

## Puntos de emisión

- `App\Support\Api\ApiErrorCode`: enum con los códigos fuera de tenant.
- `App\Support\Api\ApiErrorResponse`: constructor único y catálogo central
  (código → HTTP + mensaje).
- `App\Http\Middleware\EnsureApiAuthenticated`: `unauthenticated`,
  `user_inactive`.
- `App\Http\Controllers\Auth\AuthController::login`:
  `invalid_credentials`, `user_inactive`.
- `App\Http\Middleware\ResolveTenantContext`: 401 `unauthenticated` y
  los cuatro códigos de `TenantErrorCode`.
- `App\Exceptions\Handler`: `unauthenticated` (401), `not_found` (404),
  `validation_failed` (422), `too_many_attempts` (429) y
  `cross_tenant_operation` (vía la propia excepción).
- Las respuestas HTTP genéricas (`HttpExceptionInterface`) fuera del
  catálogo mantienen su formato anterior (`message` sin `code`).
