# Estado del avance — M1.5 de INSPECTA (actualizado)

> **Estado vigente al cierre documental M2 (2026-11-12):** M2.1–M2.7 integradas en `master`. M2.6: PR #33, `7f0465702aba452dc6d0363ef00be567099f0cf4`. M2.7: PR #34, `902ef0ba96f7121a8b0d4132488335ad14ae805f`, aprobada con salvedades para infraestructura de contexto y pruebas T20–T22; no habilita jobs de negocio ni demuestra worker real. Evidencias M2.7 previas, no repetidas en este cierre: SQLite 469 pruebas (460 aprobadas, 9 omitidas), 1443 aserciones; MariaDB 11.8 (469, 461 aprobadas, 8 omitidas), 1472 aserciones; regresión SQLite posterior, 55 pruebas y 256 aserciones. Checks de CI del PR #34 satisfactorios. Ver `docs/M2/09-acta-cierre-m2.md`.

Última actualización documental: 2026-11-12. Los metadatos y mediciones de la fotografía histórica inmediatamente abajo están fechados 2026-11-11 y no representan el estado Git ni la suite de M2 al cierre formal.

**Rama de la fotografía 2026-11-11:** `feat/m2-4-get-plants` (referencia histórica, no rama actual).
**Pruebas de esa fotografía (VERIFICADO entonces):** SQLite 409 pruebas, 400 aprobadas, 9 omitidas, 1257 aserciones, 0 fallos; MariaDB 11.8 409 pruebas, 401 aprobadas, 8 omitidas, 1286 aserciones, 0 fallos (`vendor/bin/phpunit`, APP_ENV=testing, 2026-11-11). Las evidencias M2 posteriores están en el acta de cierre y no se suman a estas cifras.
**Migraciones:** 45 (añadida `2026_11_11_000001_create_role_permissions_table`).
**Composer:** `composer validate --no-check-publish` válido. `composer audit`: 0 advisories. `require.php = ^8.4`.
**Pint:** `vendor/bin/pint --test` en verde (0 diferencias) con `database/migrations` excluido permanentemente.
**OpenAPI de la fotografía 2026-11-11:** 2.1.0 (49 paths: 4 implementados, 45 `x-status: planned`); actualizado después a 2.3.0 en M2.5/M2.6. Los paths planeados siguen sin ser rutas implementadas.

---

## 1. Estado por fase

| Fase | Estado | Alcance |
|------|--------|---------|
| M0 — Auth | Completada e integrada | Sanctum, login/logout/me, EnsureApiAuthenticated. |
| M1 — Aislamiento organizacional | Completada e integrada | TenantContext, OrganizationScope, BelongsToOrganization, middleware `api.tenant`. |
| M1.5 P0 — Decisiones y preflight | Completada (documental) | D-01 a D-36 + D-37/D-38. |
| M1.5 P1 — Membresías, backfill, doble escritura | Cerrada e integrada | Tabla `organization_memberships`, `organizations.type`, backfill idempotente, hook en `User`. |
| M1.5 P2 — Índices de unicidad y FK compuestas | Cerrada e integrada | Únicos por organización en roles/plants; 6 FK compuestas en pivotes. |
| M1.5 P3.1 — Contexto por `X-Organization-Id` | Implementada e integrada (PR #7) | `ResolveTenantContext` fail-closed, sin fallback a `users.organization_id`. |
| M1.5 P3.2 — Identidad global y membresías | Implementada e integrada (PR #7) | `User` sin `BelongsToOrganization`, `organization_id` fuera de Fillable. |
| M1.5 P3.3.1 — Listado de organizaciones | Implementada e integrada | `GET /api/auth/organizations` (solo clientes activos, sin tenant). |
| M1.5 P3.3.2 — Selección de organización | Implementada e integrada | Pruebas de selección. |
| M1.5 P3.3.3 — PlatformOperatorPolicy | Implementada e integrada | Capacidad de plataforma fuera de TenantContext. |
| M1.5 P3.3.5 — Membresía activa centralizada | Implementada e integrada | D-20, login sin membresía activa (D-30). |
| M1.5 P3.3.6 — FK a usuario por membresía | Implementada e integrada | D-07, dirty-only, AuditLog exento (D-11). |
| M1.5 P3.4 — AuditLogger | Implementada e integrada | AuditLog append-only, AuditLogger con redacción, auditoría de asignaciones. |
| M1.5 P3.5 — Modo estricto de scope | Implementada e integrada (PR #9) | `strict_mode` por defecto `true`, allowlist consola, `withoutTenant` bypass. |
| M1.5 P3.3.4 — TenantController base | Implementada en M2 (P33-D07) | `TenantController` con `HasMiddleware` + middleware `EnsureTenantContext`; 21 controladores de dominio la extienden; `AuthController` y `OrganizationController` como excepciones. |
| M1.5 P4 — Seeders, operadora, OpenAPI, CORS | Implementada e integrada (PR #8) | 2 clientes + operadora `INSPECTA-OP`, OpenAPI 1.4.0 aditiva, CORS con `X-Organization-Id`. |
| M1.5 P3 (error-codes, D-31) | Integrada (PR #10) | `TenantErrorCode` + `code` en rechazos de `ResolveTenantContext`. |
| M1.5 P5.1 — CI MariaDB | Integrada (PR #12) | Job MariaDB 11.8 en `.github/workflows/tests-mariadb.yml`. |
| M1.5 P5.2 — Flag HTTP y motivos de bypass (D-36, D-08) | Integrada (PR #13) | `MarkHttpRequest` middleware, `TENANCY_STRICT_MODE`. |
| M1.5 P5.3 — Seeders por entorno (D-24) | Integrada (PR #14) | Contraseñas fijas solo en local/testing. |
| M1.5 P5.4a — Códigos de error uniformes (D-31b, D-31c) | Integrada (PR #15) | `ApiErrorCode` + `ApiErrorResponse` (7 códigos). |
| M1.5 P5.4b — OpenAPI 2.0.0 (D-16) | Integrada (PR #16) | Contrato 2.0.0, catálogo de errores, `symfony/yaml`. |
| M1.5 P5.5a — Seguridad de superficie y audit | Integrada (PR #17) | Rutas desactivadas, `security.yml` bloqueante, `RouteSurfaceTest`. |
| M1.5 P5.5b — Saneamiento de ejemplos y rutas planeadas | Integrada (PR #18) | Datos de ejemplo saneados, `x-status: planned`, 2 pruebas nuevas. |
| M1.5 P5.5c — Pint y convención de migraciones | Integrada (PR #19) | `pint.json`, 34 archivos formateados, `style.yml` bloqueante. |
| M1.5 P5 — Regresión y cierre | Completada (documental, pendiente de aprobación formal) | Allowlist verificada, modo estricto en CI, documentación de excepciones, registro de decisiones actualizado, informe de cierre y acta. |
| M2 — Autorización, superficie HTTP y contexto organizacional | Completada e integrada (M2.1–M2.7); cierre documental con salvedades | PR #26–#34 según subfase; M2.6 en PR #33 y M2.7 en PR #34. M2.7: infraestructura y T20–T22; no equivale a habilitar productores de jobs de negocio. Ver acta `docs/M2/09-acta-cierre-m2.md`. |

---

## 2. Qué existe hoy (VERIFICADO)

- **Contexto de tenant:** `ResolveTenantContext` resuelve por `X-Organization-Id` + membresía activa; fail-closed (8 rechazos 400/403). Sin fallback a `users.organization_id`.
- **Identidad global:** `User` sin `BelongsToOrganization`, `organization_id` fuera de Fillable. Pertenencia solo por `organization_memberships`.
- **Organizaciones:** `GET /api/auth/organizations` lista clientes activos del usuario (sin tenant).
- **Platform operator:** `PlatformOperatorPolicy` + `User::isPlatformOperator()`/`hasPlatformRole()` (membresía activa en operadora única).
- **Comando de consola:** `inspecta:create-platform-user` crea el primer usuario de plataforma en producción (D-38), con membresía activa, rol y auditoría redactada. Uso recomendado en producción (evita dejar la contraseña en el historial de la shell): `read -rs PASS && printf '%s' "$PASS" | php artisan inspecta:create-platform-user --email=... --name=... --password-stdin; unset PASS`.
- **Código `forbidden` para 403 de autorización (D-39):** los 403 de políticas/Gate se renderizan en formato del catálogo con code `forbidden` y mensaje fijo; los 403 de contexto conservan su código y mensaje. OpenAPI 2.1.0.
- **Clase base `TenantController` (P33-D07):** `TenantController` con `HasMiddleware` + middleware `EnsureTenantContext`; 21 controladores de dominio la extienden; `AuthController` y `OrganizationController` como excepciones.
- **Auditoría:** `AuditLog` append-only (excepción dedicada), `AuditLogger` con contexto obligatorio y redacción; auditoría de asignaciones de rol/planta/área.
- **Modo estricto:** `OrganizationScope` lanza `MissingTenantContextException` en HTTP sin contexto cuando `strict_mode=true` (default); consola y `withoutTenant()` exentos; workers de cola no exentos; flag HTTP `TENANCY_STRICT_MODE`.
- **Seeders:** 2 clientes (INSP-01, ORG-02) + operadora INSPECTA-OP; catálogo por organización; consultor de plataforma con membresía en operadora y clientes. Contraseñas fijas solo en local/testing.
- **OpenAPI vigente:** 2.3.0; cabecera `X-Organization-Id`, `/auth/organizations`, campo `type` y catálogo de errores. Las operaciones con `x-status: planned` son planes, no rutas implementadas; la correspondencia M2.6 se documenta en su informe.
- **CORS:** `config/cors.php` permite `X-Organization-Id`, `supports_credentials=false`, `max_age=600`.
- **Migraciones:** 45 (incluye `role_permissions` y unique de norms por organización).
- **Pint:** `vendor/bin/pint --test` en verde; `database/migrations` excluido permanentemente.
- **CI:** workflows `tests` (SQLite), `tests-mariadb` (MariaDB 11.8), `security` (audit bloqueante), `style` (Pint bloqueante).
- **Rutas implementadas según M2.6:** `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`, `GET /api/auth/organizations` y `GET /api/plants`; además `/` y `up` de la aplicación. M2.6 confirmó la superficie de cinco operaciones API.
- **Corte vertical de dominio:** `GET /api/plants` implementado en M2.4 y conservado por M2.6. No se implementó CRUD de plantas, inspecciones ni endpoints adicionales de dominio en M2.

## 3. Qué NO existe todavía

- Factories EHS completas y CRUD de dominio.
- Servicio HTTP para revocar/reconceder membresías; M2.5 implementa revocación de dominio en servicio, no endpoints HTTP, y reactivación sigue diferida.
- Las 45 operaciones marcadas planeadas en OpenAPI no se consideran implementadas por figurar en el contrato.
- Servicio de aprovisionamiento de roles y normas por cliente.
- Errores fuera del catálogo (403 de policies, 405, 500) sin `code`.

## 4. Decisiones relevantes

Resumen histórico de decisiones de M1.5; para el estado vigente de M2 prevalecen el registro oficial y el acta de cierre M2. El conteo siguiente es una fotografía previa del registro, no el estado vigente. Al cierre M2, D-32 y D-35 están implementadas e integradas en M2.6/M2.7 (PR #33/#34).

- **Implementadas (29, filas D-xx):** D-01, D-02, D-06, D-07, D-08, D-09, D-10, D-11, D-15, D-16, D-17, D-20, D-21, D-22, D-23, D-24, D-25, D-26, D-27, D-28, D-30, D-31, D-31b, D-31c, D-36, D-37, D-38, D-39, D-40.
- **Implementada fuera de la numeración:** P33-D07 (diferir `TenantController` a M2, ahora implementada en este bloque).
- **Propuestas (0):** ninguna.
- **Aprobadas en la fotografía histórica de M1.5 (15 filas D-xx):** D-03, D-04, D-05, D-12, D-13, D-14, D-19, D-32, D-33, D-34, D-35, D-41, D-42, D-43, D-44. D-32 y D-35 cambiaron después a implementadas e integradas; consultar el registro vigente.
- **Parciales (1):** D-18.
- **Superadas (1):** D-29.
- **Rechazadas (1):** D-28b.

### Bloques M2 (histórico; completados e integrados)

M2.1–M2.7, descritos en `docs/M2/01-modelo-de-autorizacion.md`, están implementados/documentados e integrados. El estado y alcance vigente se consolida en `docs/M2/09-acta-cierre-m2.md`.

### 4.1 Concordancia de numeración

D-20 y D-21 del checklist del 2-nov (unicidad organizacional / identidad global) difieren de D-20 y D-21 del registro vigente; `PLAN_TRABAJO_PENDIENTE.md` marca "D-21 pendiente". El registro vigente (`docs/M1.5/decisiones/REGISTRO_DECISIONES.md`) prevalece. No se editan esos otros archivos.

## 5. Validaciones de la fotografía 2026-11-11 (históricas)

- Suite en esa revisión: SQLite 409 pruebas, 400 aprobadas, 9 omitidas, 1257 aserciones, 0 fallos; MariaDB 11.8 409 pruebas, 401 aprobadas, 8 omitidas, 1286 aserciones, 0 fallos. No sustituye resultados posteriores de M2.
- `composer validate --no-check-publish`: válido.
- `composer audit`: 0 advisories.
- `vendor/bin/pint --test`: en verde (0 diferencias).
- `git diff --check`: limpio.
- OpenAPI: 2.1.0 en la fotografía de M2.2 (sin cambios en ese hito).

## Trazabilidad verificada

- `0b85193` y `69c5d54` corresponden a P3.3.6 / PR #6 y están en `master` con el mismo hash.

## 6. Alertas y riesgos vigentes

| Riesgo | Estado |
|--------|--------|
| Deuda de estilo global | RESUELTA (P5.5c: Pint en verde, migraciones excluidas) |
| D-10 con enmienda sin registrar (doble escritura retirada en P3.2) | RESUELTA (enmienda registrada en P5 cierre) |
| D-12 con orden de fases desactualizado | RESUELTA (enmienda registrada en P5 cierre) |
| `TenantController` base ausente | DIFERIDO a M2 (implementada como P33-D07) |
| Factories de dominio ausentes | DIFERIDO a M2 |
| Servicio de revocación de membresías ausente | DIFERIDO a M2 (M2.5, D-44) |
| OpenAPI 2.0.0 → 2.1.0 | RESUELTA (P5.4b PR #16; D-39 eleva a 2.1.0) |
| 45 paths del contrato planeados | DIFERIDOS; M2 implementó un corte vertical (`GET /api/plants`), no habilitó el resto de las operaciones planeadas |
| M2 bloqueada hasta cierre formal de P5 | RESUELTA (acta firmada 10-11-2026; M2 autorizada) |
| Tokens sin expiración (`sanctum.expiration`) | ABIERTA (riesgo de despliegue; depende de la PWA sin conexión, D-44) |
| `Organization` sin scope de aislamiento | ABIERTA (aislamiento vía TenantContext y membresías, no a nivel de tabla) |
| Auditoría sin escritor automático | ABIERTA (`AuditLogger` requiere contexto explícito) |
| `devices` y `sync_operations` sin `organization_id` | ABIERTA (sin `organization_id` no hay aislamiento de fila; riesgo en sync/dispositivos) |
| 401 vs 403 de usuario desactivado | RESUELTA en M2.5 (D-44): token de usuario desactivado → 401; login inactivo conserva 403 `user_inactive`; véase informe M2.5 |

## 7. Restricciones permanentes

- Nunca push a `master`.
- Push de ramas de funcionalidad solo con autorización explícita y sin `--force`.
- No modificar migraciones existentes ni pruebas previas (salvo formato autorizado en P5.5c).
- No implementar M2 sin autorización.
- No tocar `.env`.
- MariaDB solo en contenedor aislado con variables en línea.

## 8. Cómo retomar

1. Rama `docs/cierre-m1-5`, HEAD basado en `3edf5cb` (origin/master, incluye P5.5c).
2. Suite medida: 343 pruebas, 0 fallos en SQLite y MariaDB.
3. Leer este `ESTADO.md` y el [informe de cierre](../12-informe-cierre-m1-5.md) antes de empezar.
4. Próximo bloque: aprobación formal del cierre de M1.5 (acta en `docs/M1.5/13-acta-aprobacion-m2.md`) y, tras ella, M2.

---

*Fin del documento ESTADO.md.*
