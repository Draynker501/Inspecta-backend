# 06 — Fases, gates y matriz de pruebas

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1)

**Objetivo:** que cada fase sea ejecutable y verificable por sí misma, sin depender de fases
futuras, y que la suite permanezca verde durante toda la transición.
**Estado:** propuesta. Nada implementado ni ejecutado.

---

## 1. Fases

### P0 — Decisiones y preflight

| Aspecto | Detalle |
|---|---|
| **Alcance** | Cerrar las decisiones de `07` §2. Ejecutar los prechecks read-only de `04` §5. Redactar el reporte de anomalías. |
| **Artefactos** | Ninguno de código. Reporte de preflight y decisiones firmadas. |
| **Límites** | Sin migraciones, sin código, sin seeders, sin escrituras. |
| **Gate** | Ninguna condición de parada dura activa (`04` §6.1). Backup verificable confirmado. |
| **Rollback** | No aplica. |

### P1 — Expand: membresías, backfill y doble escritura

| Aspecto | Detalle |
|---|---|
| **Alcance** | Migraciones A1 (`organization_memberships`) y A2 (`organizations.type`). Modelo `OrganizationMembership`. Comando de backfill con `--dry-run` y reporte. Doble escritura al crear usuario. Relaciones en `User` y `Organization`. Estados de factory para membresías. |
| **Límites** | **No** cambia la autorización: `ResolveTenantContext`, `TenantPolicy` y `BelongsToOrganization` siguen igual. **No** se tocan seeders existentes. **No** se cambian índices. **No** se modifica `openapi.yaml`. |
| **Gate** | Suite M1 intacta (63/63 declaradas; reverificar al inicio). Nuevas: T24, T38, T47, T48, T49, T50, T51. Backfill ejecutado dos veces con resultado idéntico. `migrate:rollback` deja el esquema como estaba. |
| **Rollback** | `DROP TABLE organization_memberships`, `DROP COLUMN type`. Sin pérdida de datos originales. |

**Por qué P1 no rompe nada:** el código de autorización no consulta las membresías todavía. Las
membresías se escriben “en paralelo” y se validan por pruebas. Es la fase más segura para empezar.

### P2 — Índices de unicidad

| Aspecto | Detalle |
|---|---|
| **Alcance** | C1: `roles (organization_id, name)`. C2: `plants (organization_id, code)`. |
| **Límites** | Solo índices. Sin datos. Sin cambios de código de aplicación. |
| **Gate** | Precheck S6/S7 = 0. `up` y `down` en SQLite. T36, T37, T52, T53, T54. Suite completa verde. |
| **Rollback** | Recrear índice global, **solo** si no hay datos multiempresa. |

**Por qué P2 va antes de P4:** no se puede sembrar “Administrador” en dos organizaciones mientras
`roles.name` sea único global. Ver `07` C1.

### P3 — Contexto, autenticación, policies y FK

| Aspecto | Detalle |
|---|---|
| **Alcance** | `User` global (sin `OrganizationScope`, sin `organization_id` en fillable). `ResolveTenantContext` por cabecera y membresía. `TenantContext::requireId()` y `withoutTenant($reason, ...)`. `TenantController`. `TenantPolicy` por membresía. `PlatformOperatorPolicy`. `/auth/me` solo-auth. `/auth/organizations`. `BelongsToOrganization` con FK a usuario por membresía y *dirty-only* en update. `AuditLogger`. `TenantFixtures` con membresías. Adaptación de pruebas M1 afectadas. |
| **Límites** | **No** se tocan seeders (P4). **No** se toca `openapi.yaml` (P4). **No** se implementa dominio. **No** se cambia el handler 429. |
| **Gate** | T01–T22, T26–T28, T30–T35, T39, T41–T46, T11b, T12b. Regresión M1 adaptada en verde. |
| **Rollback** | Revertir deploy. `users.organization_id` sigue disponible y las membresías quedan sin uso. |

**Es la única fase que rompe pruebas existentes.** Se asume explícitamente: las pruebas afectadas se
adaptan **en el mismo cambio** que el código que cubren.

### P4 — Seeders, factories, fixtures y OpenAPI

| Aspecto | Detalle |
|---|---|
| **Alcance** | Seeders multiempresa sin `first()` ni `firstWhere` ambiguo. Roles por organización con IDs deterministas. Membresías en seeders. Factories con estados. `TenantFixtures` definitivo. `openapi.yaml`. |
| **Límites** | Sin controladores de dominio. Sin cambios de esquema. |
| **Gate** | T23, T29, T55, T56. Seed ejecutado dos veces con conteos estables. OpenAPI válido. Suite completa verde. |
| **Rollback** | Revertir seeders; los datos sembrados en desarrollo se pueden recrear. |

### P5 — Deprecación y endurecimiento

| Aspecto | Detalle |
|---|---|
| **Alcance** | Retirar cualquier uso autorizante residual de `users.organization_id`. Modo estricto del `OrganizationScope`. Allowlist de `withoutTenant`. Documentar la deprecación. Evaluar nulabilidad (decisión separada). |
| **Límites** | Sin pérdida de datos. Sin eliminar la columna. |
| **Gate** | T40, T57, T58, T59. Suite completa verde. |
| **Rollback** | Revertir deploy; el modo estricto es configurable. |

### Después de M1.5

| Fase | Requisito |
|---|---|
| M2 — catálogos y controladores de dominio | P3, P4 y P5 verdes |
| Dispositivos y sincronización offline | M2 aprobado; diseño propio |
| Usuarios de empresas cliente | Decisión de producto y `membership_type` |
| Consolidados multiempresa | `PlatformOperatorPolicy` + endpoints explícitos y auditados |

---

## 2. Estrategia para mantener la suite verde

### 2.1 Línea base

- Estado declarado de M1: **63 pruebas, 215 aserciones**, SQLite `:memory:`.
- **No se reejecutó** en esta auditoría (la solicitud prohíbe escrituras y la suite usa SQLite en
  memoria, pero se optó por no ejecutar nada). Debe reverificarse al inicio de P1 y registrarse el
  número exacto antes de tocar código.

### 2.2 Reglas de transición

1. **Ninguna prueba se elimina.** Las que cambian de contrato se adaptan conservando su nombre y
   añadiendo una nota en el docblock: `M1.5: adaptado por <motivo>`.
2. **Cada fase añade sus pruebas.** No se posterga cobertura a fases futuras.
3. **Sin `markTestSkipped`, sin `--filter` en la verificación de gate, sin exclusiones.**
4. **Las pruebas se adaptan en el mismo cambio que el código que cubren.** Nunca en una fase
   posterior (ver `07` C2).
5. **Los fixtures crecen de forma aditiva.** `TenantFixtures` gana `withMembership()`,
   `operatorOrganization()`, `clientOrganization()` sin romper los métodos existentes hasta P3.
6. **SQLite aislado.** El guard de `tests/TestCase.php` sigue abortando si el driver no es SQLite o
   la base no es `:memory:`. No se ejecuta nada contra MariaDB.
7. **Sin datos compartidos.** `RefreshDatabase` en todas las pruebas de base de datos.

### 2.3 Pruebas M1 que se verán afectadas en P3

| Prueba | Motivo | Acción |
|---|---|---|
| `tests/Feature/Auth/AuthTest.php` | Login y `/me` devuelven `organization_id`; `/me` deja de exigir tenant | Adaptar contrato; añadir lista de organizaciones |
| `tests/Feature/Tenancy/TenantModelTest.php` | `User` figura entre los 23 modelos tenant-scoped | Retirar `User` de la lista; añadir prueba de que es global |
| `tests/Feature/Tenancy/TenantIsolationTest.php` | Asume organización única y `/me` con tenant | Reescribir con membresías y cabecera |
| `tests/Feature/Tenancy/TenantRoutingTest.php` | Exime login y logout de `api.tenant` | Añadir `/auth/me` y `/auth/organizations` |
| `tests/Feature/Database/SeederTest.php` | Exige coherencia con organización única | Mantener en P3 (seeders aún single-org); adaptar en P4 |
| `tests/Support/TenantFixtures.php` | Supuesto de organización única | Extender en P1, adaptar en P3 |

**Ninguna de estas pruebas se puede adaptar “después”**: el gate de P3 las exige verdes.

---

## 3. Códigos HTTP

| Situación | Código | Cuerpo |
|---|---|---|
| Login correcto | 200 | Usuario, token, organizaciones |
| Listado de organizaciones | 200 | Solo membresías activas y `type = 'client'` |
| Operación de dominio correcta | 200/201/204 | Según operación |
| Token ausente o inválido | 401 | `unauthenticated` |
| Usuario inactivo | 403 | `user_inactive` |
| Cabecera ausente | 400 | `organization_required` |
| Cabecera mal formada | 400 | `organization_invalid` |
| Organización inexistente, inactiva o archivada | 403 | `organization_access_denied` |
| Sin membresía o membresía inactiva | 403 | `organization_access_denied` |
| Organización operadora como tenant | 403 | `organization_not_tenant` |
| Recurso de otra organización | 404 | `Recurso no encontrado.` |
| Actualizar/eliminar recurso ajeno | 404 | Sin cambios en BD |
| Crear con `organization_id` ajeno | 422 | `CrossTenantOperationException` |
| FK padre de otra organización o usuario sin membresía | 422 | Error del campo |
| Payload inválido | 422 | Errores de validación |
| Sin contexto en código interno | 500 controlado | No debe alcanzar HTTP |
| Rate limit de login | 429 | **Sin cambios; el handler no se toca** |

Diferencias deliberadas: **400** = falta seleccionar empresa; **403** = no tienes acceso;
**404** = el recurso no existe o no es visible; **422** = la escritura es inválida. No todos los
errores deben responder igual.

---

## 4. Matriz de pruebas

### 4.1 Identidad y membresías

| ID | Escenario | Fase | Resultado esperado |
|---|---|---|---|
| T01 | Identidad global con membresías en A y B | P3 | Login 200; una cuenta; un token |
| T02 | Listar organizaciones | P3 | Solo A y B; nunca C ni el operador |
| T03 | Seleccionar A y B con la misma identidad | P3 | 200 en ambas; datos de la empresa activa |
| T04 | Roles distintos en A y B | P3 | `hasRole` y policies coherentes por empresa |
| T05 | Cabecera de organización sin membresía | P3 | 403 |
| T06 | Membresía revocada en A, activa en B | P3 | 403 en A; 200 en B |
| T07 | Organización inactiva | P3 | 403 |
| T08 | Organización archivada | P3 | 403 |
| T16 | Revocación inmediata con token vigente | P3 | Siguiente petición 403; logout 200 |
| T17 | Logout con organización inactiva | P3 | 200 |
| T18 | `/auth/me` sin cabecera | P3 | 200; identidad y membresías |
| T27 | Cabecera manipulada | P3 | 403 |
| T28 | Dos peticiones A/B del mismo usuario | P3 | Sin fuga de contexto |
| T33 | `hasRole` por organización | P3 | True en A, false en B |
| T34 | `User` global | P3 | `User::find` no se filtra por organización |
| T38 | Membresía duplicada | P1 | Rechazada por unique |
| T39 | Usuario inactivo | P3 | 403 |
| T44 | Desactivación de usuario | P3 | Login y peticiones rechazados |
| T45 | Borrado físico de usuario | P3 | Bloqueado |
| T46 | Re-conceder membresía | P3 | Reutiliza la fila; sin duplicado |

### 4.2 Aislamiento y escrituras

| ID | Escenario | Fase | Resultado esperado |
|---|---|---|---|
| T10 | Recurso de B con contexto A | P3 | 404 |
| T11 | Update/delete de recurso de B con contexto A | P3 | 404; BD sin cambios |
| T12 | Crear con `organization_id` de B | P3 | 422 |
| T13 | Área con `plant_id` de B | P3 | 422 |
| T14 | Relaciones anidadas | P3 | Solo hijos de A |
| T31 | Admin de A intenta gestionar catálogo de B | P3 | 403/404 |
| T32 | Inspector con planta A1 | P3 | Ve A1, no A2 |

### 4.3 Actor, auditoría e histórico

| ID | Escenario | Fase | Resultado esperado |
|---|---|---|---|
| T15 | Consultor miembro de A crea inspección en A | P3 | 201; auditoría con `organization_id=A`, `user_id=consultor` |
| T35 | Auditoría de acción cross-org | P3 | Registro en la organización correcta |
| T43 | Auditoría al revocar la membresía del actor | P3 | Se escribe sin error |
| T11b | Editar registro histórico con responsable revocado | P3 | 200; no se revalida la FK no modificada |
| T12b | Reasignar a usuario sin membresía | P3 | 422 |

### 4.4 Contexto interno

| ID | Escenario | Fase | Resultado esperado |
|---|---|---|---|
| T19 | `requireId()` sin contexto | P3 | `MissingTenantContextException` |
| T20 | Job con organización | P3 | Escribe en esa organización |
| T21 | Job sin organización | P3 | Falla explícitamente |

> T20 y T21 se validan con un **job de prueba** dentro de la suite. No se introduce ningún job de producción en M1.5; el objetivo es probar el trait `TenantAwareJob` antes de que exista el primer job real.
| T22 | Comando sin `--organization` | P3 | Error de validación |
| T40 | Scope estricto sin contexto | P5 | Excepción |
| T57 | Allowlist de `withoutTenant` | P5 | Sin usos no declarados |
| T58 | `users.organization_id` no autoriza | P5 | Prueba de grep/arquitectura |
| T59 | Todo controlador de dominio extiende `TenantController` | P5 | Prueba de arquitectura |

### 4.5 Operador

| ID | Escenario | Fase | Resultado esperado |
|---|---|---|---|
| T41 | Cabecera con id del operador | P3 | 403 `organization_not_tenant` |
| T42 | Rol de plataforma no concede dominio | P3 | 403 en datos de cliente |
| T55 | Seeder rechaza código reservado | P4 | Error explícito |
| T56 | Ningún dato de dominio referencia al operador | P4 | Conteo 0 en todas las tablas |

### 4.6 Índices, seeders y contrato

| ID | Escenario | Fase | Resultado esperado |
|---|---|---|---|
| T23 | Seeders dos veces | P4 | Conteos estables; pivotes coherentes |
| T24 | Backfill dos veces | P1 | Idempotente; sin duplicados |
| T29 | OpenAPI válido | P4 | 0 `$ref` rotos; esquema válido |
| T30 | Cobertura de rutas | P3 | Toda ruta `/api` en `api.tenant` o allowlist |
| T36 | Mismo nombre de rol en A y B | P2 | Permitido |
| T37 | Mismo código de planta en A y B | P2 | Permitido |
| T52 | Rol duplicado dentro de A | P2 | Rechazado |
| T53 | Planta duplicada dentro de A | P2 | Rechazado |
| T54 | Migración de índices up/down | P2 | Reversible en SQLite |
| T26 | Token inválido | P3 | 401 |

### 4.7 Backfill

| ID | Escenario | Fase | Resultado esperado |
|---|---|---|---|
| T47 | Crear usuario crea membresía (doble escritura) | P1 | Membresía creada al crear el usuario. [Nota 2026-10-28] el código actual no usa transacción (hook en el evento `saved`); se difiere hasta que exista un endpoint de creación de usuarios (P3 o posterior). |
| T48 | Backfill `--dry-run` | P1 | No escribe; reporta conteos |
| T49 | Usuario sin organización | P1 | Sin membresía; reportado |
| T50 | Usuario con home = operador | P1 | Membresía en operador; reportada |
| T51 | Rollback de A1 | P1 | Esquema restaurado |
| T24 | Idempotencia | P1 | Segunda ejecución: 0 creados |

---

## 5. Criterios de aceptación de M1.5

1. Un usuario con membresías en A y B inicia sesión una vez y accede a ambas con una sola identidad.
2. Puede tener roles distintos en A y B.
3. Sin cabecera, sin membresía activa, con empresa inactiva o archivada: no hay acceso (400/403).
4. Recurso de otra empresa: 404. Escritura con organización o padre ajeno: 422.
5. Un consultor puede ser actor de una operación en la empresa cliente correcta; la auditoría se
   registra en esa empresa con el actor global.
6. Las consultas sin contexto fallan de forma segura en las fronteras (P3) y en el scope (P5).
7. Los seeders son idempotentes y mantienen coherencia en las tablas pivote.
8. Jobs y consola exigen contexto explícito y validado.
9. Login, logout y `/auth/me` funcionan según el contrato nuevo.
10. La suite completa (M1 adaptada + matriz nueva) está verde.
11. OpenAPI valida y documenta cabecera, organizaciones, errores y `type`.
12. El handler 429 no se modificó.
13. Ningún dato de dominio referencia la organización operadora.
14. Ningún usuario se borra físicamente; la auditoría conserva al actor.

---

## 6. Evidencia que debe quedar al cerrar cada fase

- Salida completa de la suite (pruebas y aserciones).
- Lista de pruebas nuevas y de pruebas adaptadas con su motivo.
- Para P1: salida del backfill en `--dry-run` y real, con conteos.
- Para P2: salida de `migrate` y `migrate:rollback` en SQLite.
- Para P4: salida del seeder ejecutado dos veces.
- Para P5: resultado de las pruebas de arquitectura y de la allowlist.

Sin esa evidencia, la fase no se considera cerrada.
