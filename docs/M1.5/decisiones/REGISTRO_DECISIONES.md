# Registro oficial de decisiones — M1.5 de INSPECTA

Fuente de verdad para las decisiones D-01 a D-44 y la sección de discrepancias. Transcrito sin reinterpretar salvo las enmiendas autorizadas en M2.1 (cambio de estados D-32 a D-35 y reescritura de D-33).

**Estados:** *Aprobada* (decidida y sin código verificable) · *Implementada* (aprobada y con código) · *Parcial* · *Propuesta* (nadie la ha aprobado) · *Rechazada* (decidida en contra, no se implementa).

## D-01 a D-18 (registro de P0)

| ID | Decisión | Estado | Evidencia o nota |
|---|---|---|---|
| D-01 | Organización de la consultora con `organizations.type = 'operator'` | Implementada | Columna `type` en P1; `INSPECTA-OP` creada en los seeders de P4 |
| D-02 | Membresías sin soft delete: `status` y `revoked_at` | Implementada | P1; `status` es string `active`/`revoked` (D-22) |
| D-03 | No se permite el borrado físico de usuarios | Aprobada | FK `RESTRICT` desde membresías; sin prueba (T45 sin cubrir) |
| D-04 | Suspender y archivar organizaciones sin eliminar datos | Aprobada | El middleware rechaza organizaciones inactivas o eliminadas; no hay operación para suspender o archivar |
| D-05 | Retención indefinida, sin purga automática | Aprobada | Sin código asociado |
| D-06 | Sin revocación masiva de tokens en M1.5 | Implementada | El token no lleva organización; la membresía se evalúa en cada petición |
| D-07 | FK a usuario validada por membresía activa, solo si el campo cambia | Implementada | P3.3.6 (`0b85193`, PR #6); `AuditLog.user_id` exento |
| D-08 | Falla cerrada en las fronteras (P3) y scope estricto (P5) | Implementada | Frontera HTTP y modo estricto activo por defecto (PR #9); lista de motivos de `withoutTenant` (PR #13, D-08). Aprobada 08-nov-2026 |
| D-09 | Únicos por organización en `roles` y `plants` | Implementada | P2; verificada en MariaDB |
| D-10 | Expand/contract con doble escritura temporal | Implementada | La doble escritura se retiró en P3.2 sin registrar la enmienda. Enmienda (P5 cierre): la doble escritura temporal se retiró en P3.2 (PR #7); un usuario creado fuera de las fixtures y de los seeders no recibe membresía automática y esta debe crearse explícitamente |
| D-11 | Auditoría append-only con `AuditLogger` | Implementada | P3.4: `AuditLogger` y asignaciones auditadas |
| D-12 | Separación A/B/C y orden de fases P0 a P5 | Aprobada | Las fases se ejecutaron en otro orden y con otros alcances. Enmienda (P5 cierre): el orden real de fases difiere del planeado; la fuente vigente es `docs/M1.5/avance/ESTADO.md` y el informe de cierre `docs/M1.5/12-informe-cierre-m1-5.md` |
| D-13 | MariaDB 11.8 LTS como versión objetivo | Aprobada | Para desarrollo (11.8.9 verificada); la ventana de mantenimiento queda para producción |
| D-14 | Preflight de solo lectura y backup verificable | Aprobada | Se aplica antes de producción; el backup no se ha verificado |
| D-15 | Membresía en asignaciones de roles, plantas y áreas (opción A: FK compuestas) | Implementada | P2; rechazo verificado en MariaDB y por pruebas |
| D-16 | Contrato OpenAPI | Implementada | **2.0.0** aplicada (PR #16, P5.4b); catálogo de errores D-31b/D-31c. Aprobada 08-nov-2026 |
| D-17 | Único de `norms (code, version)` por organización | Implementada | P4; el `down()` rechaza revertir con duplicados entre organizaciones |
| D-18 | CORS permite `X-Organization-Id` | Parcial | `config/cors.php` lo permite; el origen por defecto es `*` |

## D-19 a D-39 (surgidas en el proyecto)

| ID | Decisión | Estado | Evidencia o nota |
|---|---|---|---|
| D-19 | Métricas entre clientes por vía separada, con consentimiento por organización | Aprobada | Diferida a un hito posterior |
| D-20 | Roles, plantas y áreas se evalúan contra la organización activa, con membresía activa, en un único punto | Implementada | P3.5a: `findActive` y scopes en `OrganizationMembership` |
| D-21 | `User` fuera del scope de organización | Implementada | `#[Fillable]` sin `organization_id` y sin `BelongsToOrganization` (P3.2) |
| D-22 | `organization_memberships.status` es `string(20)` | Implementada | P1 |
| D-23 | Sin `joined_at`; `created_at` es la fecha de alta | Implementada | P1 |
| D-24 | `UserSeeder` usa contraseñas fijas solo en entorno local | Implementada | PR #14 (2014a06): contraseñas fijas solo en local/testing. Aprobada 08-nov-2026 |
| D-25 | Job de CI con MariaDB 11.8 que ejecute `migrate:fresh --seed` | Implementada | PR #12: job MariaDB 11.8 en CI. Aprobada 08-nov-2026 |
| D-26 | PHP 8.4 como versión mínima | Implementada | PR #5; la CI corre en 8.4 y 8.5 |
| D-27 | Fijar `config.platform.php` en 8.4.1 | Implementada | `config.platform.php` fijado a `8.4.1` en `composer.json` (sección `config.platform`); `composer.lock` refleja `platform-overrides.php = 8.4.1` sin cambiar versiones de paquete (solo `content-hash`). `composer check-platform-reqs --lock` verifica contra la plataforma fijada (control: `8.9.0` falla). Prueba nueva `tests/Unit/Config/PlatformPhpTest.php` valida que `config.platform.php` exista y que su `major.minor` coincida con el mínimo de `require.php` (`^8.4` → `8.4`). Mutaciones (`8.5.0`, `8.3.0`, sin la clave) hacen fallar la prueba. Suite: 344 pruebas, 0 fallos (SQLite y MariaDB 11.8); `composer validate --strict`, `composer audit --locked` y Pint en verde. Motivo del 8.4.1: el suelo de las dependencias del lock es `symfony/http-foundation >=8.4.1`, mientras `require.php` sigue en `^8.4` (D-26), por lo que PHP 8.4.0 exacto no puede instalar el lock; `8.4.1` es el mínimo parche que satisface a todas las dependencias. |
| D-28 | Cabecera `X-Organization-Id` obligatoria | Implementada | 400 sin cabecera o con UUID inválido; sin fallback a la columna legada |
| D-28b | Sin endpoint de "seleccionar organización"; `login` y `me` añaden la lista de membresías | Rechazada | Rechazada en P5.4b: login y me no listan membresías; la identidad de la sesión depende de `X-Organization-Id` y el frontend obtiene el listado con `GET /api/auth/organizations`. Motivo en `docs/M1.5/10-cambios-contrato-2.0.0.md` |
| D-29 | Retirar `organization_id` del fillable de `User` | Superada | Ya se retiró en P3.2, antes de lo planeado |
| D-30 | Login permitido sin membresía activa | Implementada | PR #6 (b87ab5e): login sin membresía activa, 3 pruebas. Aprobada 08-nov-2026 |
| D-31 | Campo `code` en los rechazos 400 y 403 del contexto | Implementada | PR #10 (aa0482c) fusionado; D-31b/c en PR #15. Aprobada 08-nov-2026 |
| D-31b | Unificar los mensajes del 403 | Implementada | PR #15 (8f28239): texto 403 unificado. Aprobada 08-nov-2026 |
| D-31c | Códigos para los demás errores (`unauthenticated`, `invalid_credentials`, `user_inactive`, `validation_failed`) | Implementada | PR #15 (8f28239): ApiErrorCode + ApiErrorResponse. Aprobada 08-nov-2026 |
| D-32 | `viewMemberships` exige también el rol de plataforma | Implementada y validada localmente (M2.6) | `PlatformOperatorPolicy::viewMemberships()` exige `isPlatformOperator() && hasPlatformRole()`; el helper exige membresía activa y rol activo en la operadora. M2.6 sigue sin integrar. |
| D-33 | FK compuestas a membresías en las tablas de dominio | Aprobada | Convención para tablas de dominio futuras: la FK compuesta garantiza que la membresía exista en esa organización, no que esté activa (la actividad la cubren D-07 y D-34). Sin migración en M2 si se comprueba que aún no hay tablas de dominio con FK a usuario. Cambio respecto al original: se aclara el alcance de la FK (existencia, no actividad), se condiciona la migración de M2 a la existencia de tablas de dominio con FK a usuario y se pasa de Propuesta a Aprobada como convención. |
| D-34 | El responsable asignado debe tener `users.status` activo | Aprobada | Se valida en la asignación de responsable en M2; no hay migración asociada. |
| D-35 | Diferir a M2 las pruebas de jobs y comandos con organización (T20 a T22) | Aprobada | Jobs y comandos fijan el contexto de forma explícita (patrón D-38 con `TenantContext::runAs`); se prueba en M2.7. |
| D-36 | Marcar "dentro de una petición HTTP" con un flag del middleware global en vez de `runningInConsole()` | Implementada | PR #13 (e321739): flag HTTP para modo estricto. Aprobada 08-nov-2026 |
| D-37 | Separar seeders de arranque y de demostración | Implementada | Implementada en P5.3b (PR #14, commit 2014a06): `UserSeeder` con contraseñas fijas solo en local/testing. Aprobada 08-nov-2026 |
| D-38 | Comando de consola para crear el primer usuario de plataforma en producción | Implementada | Implementado en bloque D-38 (rama feat/d38-primer-usuario-plataforma): comando `inspecta:create-platform-user` con `--email`, `--name`, `--password-stdin`, transacción, auditoría redactada, unicidad del usuario de plataforma (bloqueo de fila), longitud mínima de contraseña 12, y pruebas SQLite/MariaDB. |
| D-39 | Código `forbidden` para los 403 de autorización | Implementada | Implementado en bloque D-39 (rama feat/d39-codigo-forbidden): `Handler` renderiza 403 de políticas/Gate con code `forbidden` y mensaje fijo (`ApiErrorCode::FORBIDDEN_MESSAGE`) solo para peticiones API; los 403 de contexto conservan su código y mensaje; OpenAPI actualizada a 2.1.0 con `forbidden` en el enum de códigos. |

## D-40 a D-44 (M2.1 — decisiones de autorización, aprobadas 11-nov-2026)

| ID | Decisión | Estado | Evidencia o nota |
|---|---|---|---|
| D-40 | Modelo de permisos. Catálogo de permisos en código, con nombres `recurso.accion`. La base de datos guarda solo `role_permissions` (rol, organización, permiso) con FK compuesta al rol (D-09, D-15); no hay tabla `permissions`. Una prueba verifica que todo permiso guardado existe en el catálogo. `hasPermission()` es el único punto de evaluación (D-20), opera a nivel de la organización activa y deniega por defecto. No se sobrescribe `User::can()`. Los permisos de la membresía se cargan una vez por petición y no persisten entre peticiones. Un permiso nuevo del catálogo se añade a los roles existentes solo de forma explícita y solo añadiendo, nunca quitando. | Implementada | M2.2 (rama feat/m2-2-nucleo-permisos): enum `App\Authorization\Permission` (14 permisos), modelo `RolePermission`, migración `2026_11_11_000001_create_role_permissions_table`, `User::hasPermission(Permission)`, caché en `TenantContext` (clave usuario+org, invalidada por eventos de RolePermission/UserRole y por clear()), helper `TenantPolicy::hasPermission(User, Permission)`. Pruebas (re-medidas 2026-11-11): SQLite 377 pruebas, 368 aprobadas, 9 omitidas, 1177 aserciones; MariaDB 11.8 377 pruebas, 369 aprobadas, 8 omitidas, 1206 aserciones; 0 fallos. Mutaciones evaluadas: 13 (12 detectadas por pruebas; 1 equivalente demostrada: filtro organizacional de `loadPermissions()`, redundante por FK compuesta y PK global de `roles`). La omisión de `TenantContext::clear()` en `ResetTenantContext` se cubre con `ResetTenantContextTest` (nivel middleware). Diseño en `docs/M2/01-modelo-de-autorizacion.md`. |
| D-41 | Alcance de M2 sobre endpoints (opción híbrida). M2 no añade rutas de producción salvo un único endpoint de lectura como corte vertical (`GET /plants`) que ejerce autenticación, tenant, controlador, policy, permiso y `forbidden`; al implementarlo el contrato pasa a 2.2.0. El resto de rutas de dominio llega con los catálogos organizacionales. Todo lo demás se prueba con rutas temporales. | Implementada | M2.4: `GET /api/plants` implementado y validado localmente. Contrato actualizado a 2.2.0. |
| D-42 | Plantillas de rol y alcance. Roles base: Administrador, Supervisor, Inspector. `hasPermission()` opera a nivel de organización; las policies aplican el alcance por asignación (`user_plants`, `user_areas`): el Inspector solo ve y ejecuta en sus plantas y áreas asignadas. El alcance organizacional completo se expresa mediante `locations.view_all`, concedido a Administrador y Supervisor. Matriz en `docs/M2/01-modelo-de-autorizacion.md`. | Implementada | M2.3 (policies de Plant, Area y ProductionLine). Alcance org-wide confirmado y expresado por permiso dedicado `locations.view_all`; no por atajos de rol. |
| D-43 | Operadora sin acceso implícito. Un usuario de plataforma opera en un cliente solo con membresía activa y roles en él; la operadora no es superusuario. El alta de clientes y de las membresías de los consultores la hace el usuario de plataforma fuera del TenantContext. El permiso `memberships.manage` forma parte del catálogo desde M2.2. | Aprobada | Aprobada por el responsable el 11-nov-2026. Coherente con `PlatformOperatorPolicy` y `user_*` fuera de TenantContext (M1.5 P3.3.3/P3.3.5). |
| D-44 | Revocación de membresías y tokens (documenta D-06 para M2). Revocar una membresía bloquea el dominio de esa organización en la siguiente petición (D-20) pero no invalida tokens: siguen válidos para `/auth/me`, `/auth/organizations` y las demás organizaciones del usuario. Un usuario globalmente desactivado recibe 401 `unauthenticated` con token previo; el login conserva 403 `user_inactive`. Riesgo documentado: los tokens no expiran; la decisión sobre `sanctum.expiration` queda pendiente porque depende de la operación sin conexión de la PWA, y se registra entre los riesgos de despliegue. Reactivación y endpoints HTTP de membresías diferidos. | Implementada (M2.5) | Implementada en M2.5 (Alt A aprobada): `EnsureApiAuthenticated` devuelve 401 para tokens de usuarios inactivos; `MembershipService::revoke()` con autorización, transacción, auditoría y caché. |

**Fuera de la numeración:** P33-D07, diferir `TenantController` a M2, está implementada (rama feat/p33d07-tenant-controller): `TenantController` base con `Illuminate\Routing\Controllers\HasMiddleware` + middleware `EnsureTenantContext` que exige contexto organizacional activo (fail-closed); 21 controladores de dominio la extienden; `AuthController` y `OrganizationController` como excepciones; pruebas de arquitectura y de extremo a extremo. OpenAPI sin cambios (2.1.0).

## Discrepancias que conviene resolver

1. **Dos numeraciones en tus documentos.** (Documentada: el registro vigente prevalece — ver `ESTADO.md`, sección de concordancia de numeración; los archivos antiguos fuera de `docs/M1.5/` no se modificaron.) Tu checklist del 2 de noviembre usa D-20 para "unicidad organizacional" y D-21 para "identidad global". Aquí D-20 es la evaluación contra la organización activa y D-21 es `User` fuera del scope. Y `PLAN_TRABAJO_PENDIENTE.md` marca "D-21 pendiente". Quien lea esos archivos mezclará decisiones distintas con el mismo ID.
2. **D-30 y D-31 no tienen aprobación explícita.** (Resuelta: aprobadas explícitamente el 08-nov-2026; ver tabla.)
3. **D-10 tiene una enmienda sin registrar.** (Resuelta por enmienda en el registro: la doble escritura temporal se retiró en P3.2 (PR #7); un usuario creado fuera de las fixtures y de los seeders no recibe membresía automática y esta debe crearse explícitamente.)
4. **D-12 describe un orden de fases que ya no es el real.** (Resuelta por enmienda: el orden real de fases difiere del planeado; la fuente vigente es `docs/M1.5/avance/ESTADO.md` y el informe de cierre.)
5. **La versión 2.0.0 de D-16 está aprobada y no aplicada.** (Resuelta: aplicada en P5.4b, PR #16; contrato en 2.0.0.)
