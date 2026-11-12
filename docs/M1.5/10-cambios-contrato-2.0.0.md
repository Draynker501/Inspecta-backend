# Cambios del contrato OpenAPI 1.4.0 → 2.0.0 (D-16)

Documento de cambios del contrato `openapi.yaml` entre la versión 1.4.0 y la
2.0.0. Derivado del diff real del archivo; la fuente de verdad de los códigos
y mensajes es `docs/M1.5/09-catalogo-codigos-error.md` y los enums
`App\Support\Tenancy\TenantErrorCode` y `App\Support\Api\ApiErrorCode`.

La versión de la especificación no cambia: se mantiene `openapi: 3.0.3`.

## Motivo

P5.4a incorporó al backend el catálogo de códigos de error (D-31b, D-31c)
sobre el sobre uniforme `{ message, code, errors? }`. El contrato 1.4.0
documentaba los errores solo con `message`, sin `code`, y con textos que ya no
corresponden a la implementación. La versión 2.0.0 (D-16) alinea el contrato
con ese catálogo.

## Cambios por sección

### `info.version` y `info.description`

- `info.version`: `1.4.0` → `2.0.0`. Se conserva `openapi: 3.0.3`.
- `info.description` añade (el histórico de v1.4.0 se conserva):
  - viñeta v2.0.0: sobre de error uniforme con códigos estables; regla
    mismo-código-mismo-estado-y-mensaje; los errores fuera del catálogo
    (403 de policies, 405, 500) pueden omitir `code`;
  - párrafo **Flujo de sesión**: `POST /auth/login` →
    `GET /auth/organizations` → selección de organización → cabecera
    `X-Organization-Id` en cada petición con contexto; `GET /auth/me` devuelve
    solo la identidad global; ante `organization_access_denied` el cliente debe
    volver a consultar la lista y pedir otra selección;
  - párrafo **Decisión por código**: los clientes deben decidir por `code` y
    no por el texto de `message`.

### `/auth/me`

- Descripción reescrita: devuelve la identidad global del usuario; no requiere
  `X-Organization-Id` ni contexto de tenant; no refleja la organización
  seleccionada (D-28b).
- `403`: antes referenciaba `AccessDeniedError` (ejemplos: usuario inactivo,
  organización inactiva, organización inexistente, usuario sin organización).
  Ahora referencia `InactiveUserError` con `code: user_inactive`. Los modos de
  fallo "de organización" ya no ocurren en esta ruta: la identidad global no
  depende de la organización seleccionada.

### `/auth/organizations`

- Añade la respuesta `403` (`InactiveUserError`, `user_inactive`). En 1.4.0
  solo documentaba `200` y `401`.

### `components.schemas`

- Nuevo schema `ErrorCode`: enum con los 11 códigos del catálogo y una
  descripción por código.
- `Error` reescrito como sobre uniforme: `message` requerido, `code` con
  `$ref` a `ErrorCode`, `errors` opcional (solo presente con
  `validation_failed`). En 1.4.0 el schema tenía `message` y `errors` sin
  `code` y sin campos requeridos.

### `components.responses` (9 → 13)

- `UnauthorizedError` (401): dos textos separados ("Token de acceso ausente."
  / "Token de acceso inválido.") → un único texto unificado
  "No autenticado. Token de acceso ausente o inválido." con
  `code: unauthenticated`.
- `InvalidCredentialsError` (401): añade `code: invalid_credentials`. El
  mensaje no cambia.
- `InactiveUserError` (403): añade `code: user_inactive`. El mensaje no cambia.
- `ValidationError` (422): antes con schema inline y ejemplo de mensaje
  "Los datos proporcionados no son válidos."; ahora reutiliza el schema
  `Error` con `code: validation_failed`, ejemplo de mensaje real
  ("El correo electrónico es obligatorio.") y `errors` por campo.
- `TooManyRequests` (429): mensaje "Too Many Attempts." (inglés) →
  "Demasiados intentos. Inténtalo de nuevo más tarde." con
  `code: too_many_attempts`; añade las cabeceras `Retry-After`,
  `X-RateLimit-Limit` y `X-RateLimit-Remaining`. En 1.4.0 la descripción
  advertía que el backend no incluía `Retry-After`; ya no es el caso.
- `NotFoundError` (404): añade `code: not_found`. El mensaje "Recurso no
  encontrado." no cambia; sigue sin distinguir recurso inexistente de recurso
  de otra organización.
- `AccessDeniedError` (403) se elimina y se reemplaza por cuatro respuestas
  del catálogo de contexto:
  - `OrganizationRequiredError` (400, `organization_required`): falta la
    cabecera `X-Organization-Id`. En 1.4.0 no existía ninguna respuesta 400
    documentada.
  - `OrganizationInvalidError` (400, `organization_invalid`): la cabecera no
    es un UUID válido.
  - `OrganizationAccessDeniedError` (403, `organization_access_denied`) y
    `OrganizationNotTenantError` (403, `organization_not_tenant`): unifican
    los textos de contexto ("La organización está inactiva...", "La
    organización del usuario no existe.", "El usuario no tiene una
    organización asignada.") en "No tienes acceso a la organización
    indicada.".
- Nueva `CrossTenantError` (422, `cross_tenant_operation`): no existía en
  1.4.0. Documenta los dos textos posibles (escritura sobre otra
  organización y referencia de FK inexistente o de otra organización).
- `ForbiddenError` (403) y `ServiceUnavailable` (503): fuera del catálogo;
  ahora declaran explícitamente que la respuesta puede omitir `code`.

### `components.examples` (nuevo)

14 ejemplos reutilizables de sobre de error por código, referenciados desde
las respuestas. Ninguno contiene credenciales ni tokens reales.

### Rutas de dominio (45 paths, 85 operaciones)

- La cabecera `X-Organization-Id` (parámetro `organizationIdHeader`) pasa de
  estar definida pero nunca referenciada (1 ocurrencia en 1.4.0, solo su
  definición) a aplicarse a nivel de path en los 45 paths de dominio.
- Las 85 operaciones de dominio documentan ahora `400` (contexto:
  `organization_required`/`organization_invalid`), `401` y `403`
  (multiejemplo: `user_inactive`, `organization_access_denied`,
  `organization_not_tenant`, y policy fuera de catálogo). En 1.4.0, 60 de
  esas operaciones no documentaban `400`/`401`/`403` en absoluto (no había
  ningún `400` en el contrato) y el resto usaba `UnauthorizedError` /
  `ForbiddenError` sin códigos.
- El `422` de las operaciones de dominio pasa a multiejemplo:
  `validation_failed` y `cross_tenant_operation`.

## Qué NO cambia

- Ningún endpoint documentado se elimina; las rutas no implementadas se
  conservan (ver diferencias ruta/contrato abajo).
- `openapi: 3.0.3` (no se migra a 3.1).
- Mensajes de `invalid_credentials`, `user_inactive`, `not_found`,
  `ForbiddenError` ("This action is unauthorized.") y `ServiceUnavailable`.
- La regla de aislamiento multi-tenant: un recurso de otra organización se
  comporta como inexistente (404) y no revela su existencia.

## Adaptaciones de cliente

1. Decidir por `code`, no por el texto de `message` (el mismo código implica
   el mismo estado HTTP y el mismo mensaje; `cross_tenant_operation` tiene
   dos textos).
2. `unauthenticated` (401): un único texto unificado; dejar de distinguir
   "token ausente" de "token inválido" por el mensaje.
3. 429 (`too_many_attempts`): el mensaje pasa de inglés a español y la
   respuesta incluye `Retry-After`; usar esa cabecera para el backoff en
   lugar del propio.
4. Rutas de dominio: enviar siempre `X-Organization-Id`; manejar los nuevos
   400 `organization_required`/`organization_invalid`.
5. Ante `organization_access_denied` (403, texto unificado): volver a
   consultar `GET /auth/organizations` y pedir otra selección; no reintentar
   con la misma cabecera.
6. `/auth/me` y `/auth/organizations`: el único 403 esperado es
   `user_inactive`; los modos de fallo de organización ya no aplican ahí.
7. `validation_failed` (422): leer el detalle por campo de `errors`; el
   `message` es el resumen del framework.
8. Errores fuera del catálogo (403 de policies, 405, 500): pueden omitir
   `code`; el cliente no debe asumir su presencia.

## Diferencias entre rutas implementadas y contrato (paso 2b)

Implementado y documentado (prefijo `api/`):

| Método | Ruta | En contrato |
|--------|------|-------------|
| POST | `api/auth/login` | sí |
| POST | `api/auth/logout` | sí |
| GET | `api/auth/me` | sí |
| GET | `api/auth/organizations` | sí |

Implementado y NO documentado (fuera del prefijo `api/`, sin contrato):
`GET /` (welcome), `GET sanctum/csrf-cookie`, `GET|PUT storage/{path}`,
`GET up` (health de Laravel).

Documentado y NO implementado (45 paths de dominio): `plants`, `areas`,
`production-lines`, `users`, `roles`, `checklists` (+ `versions`,
`active-version`), `versions` (+ `sections`), `sections` (+ `questions`),
`questions` (+ `norm-requirements`), `norms` (+ `requirements`),
`requirements`, `inspections` (+ `status`), `incidents` (+ `status`),
`evidences`, `corrective-actions` (+ `updates`), `audit-logs`, `devices`,
`sync` (batch, changes, devices…). Estos paths se conservan como diseño del
M1.5+ y se implementarán en bloques posteriores.

## Prueba de contrato

`tests/Feature/Api/OpenApiErrorCatalogContractTest.php` verifica con
`symfony/yaml` (require-dev): el enum de `ErrorCode` coincide exactamente con
la unión de `TenantErrorCode` y `ApiErrorCode`; todos los `$ref` internos se
resuelven; y las operaciones implementadas con autenticación documentan 401.
La versión `2.0.0` se comprueba en
`tests/Feature/Documentation/OpenApiContractTest.php`.

## Marca de rutas planeadas (P5.5b)

Los 45 paths de dominio no implementados se marcan a nivel de path con la
extensión de vendedor `x-status: planned`. Los 4 paths implementados
(`/auth/login`, `/auth/logout`, `/auth/me`, `/auth/organizations`) no llevan
la marca. Mientras un path esté marcado como `planned`, el servidor devuelve
`not_found` (404, código `not_found`); no debe considerarse disponible para
consumo. La marca se calcula comparando `php artisan route:list` con los paths
del contrato (normalizando parámetros `{x}` a `{}` y el prefijo `/api`).

No hay cambio de versión del contrato (sigue siendo 2.0.0).

## Ajuste de la URL de producción (P5 cierre)

La URL de producción en `servers` se cambió de `https://api.inspecta.com/v1`
a `https://api.inspecta.com/api` para que coincida con el prefijo real de las
rutas de Laravel (`/api`). No hubo cambio de versión del contrato (sigue 2.0.0)
ni de ningún path ni operación.
