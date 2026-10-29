# 01 — Plan revisado M1 → M1.5

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1)

**Estado:** propuesta de planificación. Nada implementado.
**Referencias de código:** todas las rutas son relativas a `Inspecta-backend/`.

---

## 1. Resumen ejecutivo

M1 implementa un modelo **“un usuario = una organización”**. La infraestructura es sólida y se
conserva: `TenantContext`, `OrganizationScope`, `BelongsToOrganization`, `ResetTenantContext`,
`ResolveTenantContext`, el grupo `api.tenant`, las policies fail-closed y el esquema de 404/422.
No se propone revertir nada de M1.

El supuesto de organización única, sin embargo, está codificado en cinco lugares y con el modelo
de consultoría **deniega accesos legítimos**:

1. `users.organization_id` es `NOT NULL` y es la fuente del tenant (`ResolveTenantContext`).
2. `User` usa `BelongsToOrganization`, por lo que hereda `OrganizationScope`.
3. `TenantPolicy::hasTenantContext()` compara `$user->organization_id` con el contexto.
4. `BelongsToOrganization::assertTenantParents()` exige que **toda** FK a `User` pertenezca a la
   organización del registro, en 10 modelos.
5. Seeders, factories y fixtures reproducen esa premisa.

**Recomendación:** ejecutar **M1.5** antes de M2, en seis etapas (P0–P5) con gates de pruebas
independientes. M1.5 introduce membresías, convierte `User` en identidad global, resuelve el
tenant por petición contra una membresía activa, adapta políticas, auth, seeders, pruebas y
contrato, y **no** implementa dominio, dispositivos ni sincronización.

**Tres decisiones que el plan anterior dejaba abiertas y que este documento cierra** (detalle en
`02` y `03`):

- La consultora se modela como **fila de `organizations` con `type = 'operator'`**, con
  invariantes estrictas y rechazo explícito en el resolver de tenant.
- Los **usuarios nunca se borran físicamente**; se desactivan. La evidencia es
  `audit_logs.user_id ... nullOnDelete()`, que borraría al actor del histórico.
- Las **membresías no usan soft delete**: fila única permanente con `status` + `revoked_at`.
  MariaDB no soporta índices únicos parciales y el borrado lógico bloquearía la re-contratación.

---

## 2. Hallazgos confirmados en esta revisión

Los siguientes puntos se verificaron leyendo código en esta sesión. Complementan y corrigen el
plan anterior.

### 2.1 Hallazgos nuevos (no estaban en el plan anterior)

| # | Hallazgo | Evidencia | Impacto en el plan |
|---|---|---|---|
| H1 | Solo `Organization` usa `SoftDeletes`. `users` tiene `status` pero **no** `deleted_at`. | `app/Models/Organization.php:14`; ausencia de `use SoftDeletes` en el resto de `app/Models/`; `database/migrations/2026_10_10_000004_create_users_table.php:14-19` | La política de ciclo de vida debe basarse en `status`, no en borrado lógico. Añadir `deleted_at` a `users` sería una migración adicional no justificada. |
| H2 | `audit_logs.user_id` es `nullable` y su FK es **`nullOnDelete`**. | `database/migrations/2026_10_10_0200268_create_audit_logs_table.php:13,21` | Un borrado físico de usuario **borra al actor del histórico** de forma silenciosa. Justifica “nunca borrar usuarios”. |
| H3 | Las FK `user_id` de los pivotes son **`cascadeOnDelete`**. | `..._create_user_roles_table.php:17`, `..._create_user_plants_table.php:17`, `..._create_user_areas_table.php:17` | Un borrado físico destruye asignaciones sin auditoría. Refuerza H2. |
| H4 | `ResolveTenantContext` usa `Organization::withTrashed()->find()` y luego rechaza `trashed()`. | `app/Http/Middleware/ResolveTenantContext.php:38-52` | Comportamiento correcto a preservar: organización archivada ⇒ 403. El resolver nuevo debe conservarlo. |
| H5 | `RoleSeeder` usa **UUID fijos** y `updateOrCreate(['id' => ...])`. | `database/seeders/RoleSeeder.php:26-49,54-57` | Con roles por organización, los IDs fijos hacen que solo una organización reciba roles. Debe pasar a UUIDv5 determinista por `(organization_id, name)` o a clave `(organization_id, name)`. |
| H6 | `UserSeeder` resuelve roles con `Role::where('name', ...)->first()`. | `database/seeders/UserSeeder.php:28-30` | Ambiguo en cuanto existan nombres repetidos entre organizaciones. Debe filtrar por organización explícita. |
| H7 | `TenantContext::withoutTenant(Closure)` no recibe motivo. | `app/Support/Tenancy/TenantContext.php:64-80` | No es auditable ni greppable por intención. Se propone `withoutTenant(string $reason, Closure $callback)`. |
| H8 | `assertTenantParents()` se ejecuta en **todo** `updating` y revalida **todas** las FK padre, no solo las modificadas. | `app/Models/Concerns/BelongsToOrganization.php:36-38,102-124` | Bloquea la edición de registros históricos cuando la membresía del actor o del asignado fue revocada. Debe pasar a validar solo FK *dirty*. |
| H9 | `organizations` tiene `status` + `deleted_at` + índice `organizations_status_idx`. | `2026_10_22_000001_create_organizations_table.php:24-27` | No hace falta migración para suspender/archivar; solo para `type`. |
| H10 | `organizations.code` es único global y hay un índice creado idempotentemente. | `2026_10_22_000001_create_organizations_table.php:25,64-68` | La organización operadora necesita un **código reservado** y no colisionable. |
| H11 | La FK `organization_id` de todas las tablas de dominio es `restrictOnDelete` + `cascadeOnUpdate`. | `2026_10_22_000002_add_organization_id_to_domain_tables.php:49-57` | Un borrado físico de organización ya es imposible con datos. El archivado debe ser lógico. |
| H12 | `EnsureApiAuthenticated` no valida expiración ni abilities; `sanctum.expiration` es `null`. | `app/Http/Middleware/EnsureApiAuthenticated.php`; `config/sanctum.php` | Riesgo residual aceptado: la garantía de corte es la validación por petición, no la expiración. |
| H13 | No existen jobs, comandos de tenant, tareas programadas ni escritores de auditoría. `routes/console.php` solo define `inspire`. | `routes/console.php`; ausencia de `app/Jobs`; sin referencias a `AuditLog` fuera de modelo/controlador | El diseño de jobs/consola es preventivo; no hay código que migrar. Reduce el riesgo de M1.5. |
| H14 | `User` declara `organization_id` en `#[Fillable]`. | `app/Models/User.php:18` | Debe retirarse para que el cliente no pueda manipular la adscripción. [Nota 2026-10-28] sigue vigente en el código de P1; es trabajo de P3. |

### 2.2 Hallazgos ya establecidos y reconfirmados

| Hallazgo | Evidencia |
|---|---|
| `OrganizationScope` sin contexto **no filtra**. | `app/Support/Tenancy/OrganizationScope.php:18-25` |
| `TenantPolicy` es fail-closed sin contexto y compara `user.organization_id`. | `app/Policies/TenantPolicy.php:54-71` |
| `api.tenant` = `EnsureApiAuthenticated` → `ResolveTenantContext` → `SubstituteBindings`. | `bootstrap/app.php:34-42` |
| `/auth/me` usa `api.tenant`; `/auth/logout` usa `api.auth`. | `routes/api.php:21-23` |
| `roles.name` es único global. | `2026_10_10_000005_create_roles_table.php` |
| `plants.code` es único global. | `2026_10_10_000001_create_plants_table.php` |
| `users.email` es único global (correcto). | `2026_10_10_000004_create_users_table.php:14` |
| `users.organization_id` es `NOT NULL` con FK restrict. | `2026_10_22_000002_...php:49-55` |
| No existe `UserPolicy` ni `MembershipPolicy`. | `app/Providers/TenancyServiceProvider.php` |
| `devices` y `sync_operations` no tienen `organization_id`. | migraciones `2026_10_22_000006/000007` |
| Pruebas en SQLite `:memory:` con guard anti-MariaDB. | `phpunit.xml`; `tests/TestCase.php` |

### 2.3 Hipótesis que siguen sin verificar

No deben tratarse como hechos: estado y volumen de datos en producción; existencia de consumidores
del contrato 1.3.0; versión exacta de MariaDB y disponibilidad de DDL online; política CORS para
`X-Organization-Id`; si habrá varias consultoras operando la plataforma; si los empleados de
clientes tendrán cuentas y en qué plazo; si un dispositivo podrá contener datos de varias empresas.

---

## 3. Diferencias entre M1 y el modelo objetivo

| Dimensión | M1 actual | M1.5 objetivo |
|---|---|---|
| Identidad | Usuario ligado a una organización | Identidad global, email único |
| Fuente del tenant | `users.organization_id` | Cabecera `X-Organization-Id` + membresía activa |
| Scope de `User` | Tenant-scoped | Global, sin `OrganizationScope` |
| Autorización | `user.organization_id === contexto` | Membresía activa + rol en esa organización |
| Roles | Por organización, `name` único global | Por organización, único `(organization_id, name)` |
| FK a `User` | Misma organización que el registro | Membresía activa en la organización del registro |
| `/auth/me` | Requiere tenant | Solo autenticación; devuelve membresías |
| Selección de empresa | Implícita | Explícita y validada por petición |
| Consultora | No modelada | `organizations.type = 'operator'`, sin datos operativos |
| Ciclo de vida | Sin política | Desactivación, revocación e histórico definidos |
| Auditoría | Actor y tenant en la misma organización | Tenant cliente + actor global, append-only |
| Sync/dispositivos | Sin organización | Diseño futuro, fuera de M1.5 |
| Pruebas | Supuesto de organización única | Matriz multiempresa T01–T46 |

---

## 4. Decisiones definitivas propuestas

Las decisiones 1–10 ya están aprobadas y no se reabren. Estas son las que el plan anterior dejaba
abiertas. La numeración `D-xx` es la canónica de [`07`](07-contradicciones-y-decisiones-pendientes.md) §2
y está ordenada por tema, no por número; `D-05` (retención) se detalla en
[`03`](03-ciclo-de-vida-usuarios-membresias.md) §8.

### D-01. La consultora es una organización con `type = 'operator'`

**Decisión:** añadir `organizations.type` (`client` | `operator`), con exactamente una fila
operadora, código reservado, `status = true` permanente y sin datos operativos.

Justificación completa, alternativas y mitigaciones en [`02`](02-modelo-operador-consultora.md).

**Consecuencia operativa:** `ResolveTenantContext` rechaza organizaciones `operator` como tenant
de dominio (403). Las membresías en la organización operadora existen solo para portar roles de
plataforma y **nunca autorizan acceso a datos de clientes**.

### D-02. Membresías sin borrado lógico

**Decisión:** `organization_memberships` con `status` (string, valores `'active'` y `'revoked'`),
`revoked_at` y `created_at`/`updated_at` NOT NULL; **sin** `deleted_at`, **sin** `joined_at`
(D-23: `created_at` es la fecha de alta de la membresía). Una sola fila por
`(user_id, organization_id)` durante toda la vida de la relación. Re-conceder acceso reactiva la
misma fila (operación explícita de P3; el hook de P1 nunca reactiva).

Motivo: MariaDB no tiene índices únicos parciales; con soft delete, el índice único bloquearía
re-contratar a la misma persona o forzaría soluciones frágiles.

### D-03. Los usuarios no se borran

**Decisión:** nunca `DELETE` físico sobre `users` desde API ni consola. Desactivación por
`status = false`. Sin `deleted_at` en `users` durante M1.5. Anonimización por solicitud legal:
fase posterior y con aprobación explícita (interactúa con `users.email` único).

Motivo: H2 y H3. Un borrado físico destruye actor de auditoría y asignaciones.

Detalle en [`03`](03-ciclo-de-vida-usuarios-membresias.md).

### D-04. La organización se suspende y se archiva, no se borra

**Decisión:** `status = false` suspende (403 inmediato, datos intactos). `deleted_at` archiva, solo
por consola, solo si no hay membresías activas, y nunca sobre el operador. El borrado físico sigue
impedido por las FK `restrictOnDelete`.

### D-07. Validación de FK a usuario: por membresía y solo en creación o cambio de la FK

[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P3).

**Decisión:** en `creating` se validan todas las FK padre; en `updating`, solo las FK cuyo valor
cambió (`isDirty($fk)`). La comprobación de un padre `User` pasa a “membresía activa en la
organización del registro + usuario activo”.

Detalle en [`05`](05-contexto-tenant-y-fk-usuarios.md) §4.

### D-08. Fail-closed en fronteras primero, scope estricto después

[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P3/P5).

**Decisión:** en P3 se introduce `TenantContext::requireId()` y una clase base `TenantController`
obligatoria para controladores de dominio, más pruebas de cobertura de rutas. El modo estricto del
`OrganizationScope` (excepción sin contexto) se difiere a P5, cuando seeders y factories ya están
envueltos explícitamente.

Motivo: activar el modo estricto en P3 rompería seeders, factories, migraciones y consola en el
mismo cambio que introduce el contexto. Detalle y alternativas en [`05`](05-contexto-tenant-y-fk-usuarios.md) §2.

### D-09. Índices de unicidad antes de sembrar datos multiempresa

**Decisión:** el cambio `roles_name_unique → (organization_id, name)` y
`plants_code_unique → (organization_id, code)` ocurre en **P2**, inmediatamente después de la
tabla de membresías y **antes** de cualquier seeder multiempresa.

Motivo: el orden anterior (seeders en F4, índices en F5) era imposible: no se puede sembrar
“Administrador” en dos organizaciones mientras el nombre sea único global. Detalle en
[`07`](07-contradicciones-y-decisiones-pendientes.md) C1.

### D-10. Expand/contract para el despliegue

**Decisión:** el backfill no se ejecuta contra código que aún ignore las membresías. Secuencia:
tabla aditiva → código con doble escritura (crear membresía al crear usuario) → backfill →
validación → conmutación de autorización → retiro del fallback.

Motivo: sin doble escritura, todo usuario creado durante la ventana del backfill queda sin
membresía. Detalle en [`04`](04-migraciones-backfill-indices.md) §6.

### D-11. La auditoría es append-only y no depende de la membresía

[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P3).

**Decisión:** `audit_logs` nunca se borra; no se añade FK a membresías. El `AuditLogger` escribe
con el actor global aunque su membresía esté revocada, usando una excepción documentada a la
validación de FK a usuario.

Motivo: si la auditoría se valida por membresía activa, es imposible registrar la propia
revocación. Detalle en [`05`](05-contexto-tenant-y-fk-usuarios.md) §5.

### D-06. Sin cambios en tokens durante M1.5

**Decisión:** se mantiene el token Sanctum global y `sanctum.expiration = null`. La contención se
logra validando membresía, organización y usuario en cada petición. La revocación masiva de tokens
al revocar la última membresía queda como endurecimiento opcional posterior. [Nota 2026-10-28] el
token de Sanctum no lleva organización (verificado en P1).

### D-16. Versión del contrato OpenAPI

[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P4). Recomendación vigente: 2.0.0 con ruptura, porque panel y PWA aún no consumen `AuthUser`.

**Decisión:** pendiente. Recomendación: **1.4.0 aditiva** si no hay consumidores externos confirmados (mantener `organization_id` como campo legacy y añadir `organizations`); **2.0.0** si los hay.

### D-19. Métricas entre clientes

**Decisión:** las métricas entre clientes van por una vía separada (agregados o datos desidentificados), con consentimiento por organización; nunca relajando `OrganizationScope`. Implementación diferida a un hito posterior.

### D-20. Evaluación de roles, plantas y áreas por organización activa

**Decisión:** roles, plantas y áreas se evalúan contra la organización activa y exigen membresía activa, en un único punto de la aplicación. Se implementa en P3.

### D-21. User sale del scope de organización

[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P3).

**Decisión:** `User` sale del scope de organización y los listados de usuarios de una organización parten de las membresías.

### D-22. Tipo de status en membresías

**Decisión:** `organization_memberships.status` es `string(20)` con valores `'active'` y `'revoked'`, junto con `revoked_at`. No es boolean.

### D-23. Fecha de alta de la membresía

**Decisión:** no se añade `joined_at`; `created_at` es la fecha de alta de la membresía.

---

## 5. Esquema propuesto

### 5.1 `organizations` (cambio aditivo)

| Columna | Tipo | Regla |
|---|---|---|
| `type` | `string(20)` | `NOT NULL`, default `'client'`. Valores de aplicación: `client`, `operator`. Sin `ENUM` de BD, por portabilidad MariaDB/SQLite y por facilidad de evolución. |

Índice propuesto: `index (type, status)` para el selector y los filtros del resolver.

Invariantes:

- Exactamente una fila con `type = 'operator'`.
- Código reservado del operador (p. ej. `INSPECTA-OP`), documentado y no reutilizable por clientes.
- `status = true` y `deleted_at IS NULL` permanentes.
- Sin plantas, inspecciones, incidencias ni ningún registro de dominio.

### 5.2 `organization_memberships` (tabla nueva)

| Columna | Tipo | Regla |
|---|---|---|
| `id` | `char(36)` | PK, UUIDv7 generado por la aplicación |
| `user_id` | `char(36)` | `NOT NULL`, FK `users.id`, **`restrictOnDelete`**, `cascadeOnUpdate` |
| `organization_id` | `char(36)` | `NOT NULL`, FK `organizations.id`, **`restrictOnDelete`**, `cascadeOnUpdate` |
| `status` | `string(20)` | `NOT NULL`, default `'active'` (valores `'active'`, `'revoked'`; D-22) |
| `revoked_at` | `timestamp` | `NULL` |
| `created_at`, `updated_at` | `timestamp` | `NOT NULL` (`timestamps()` de Laravel) |

Índices:

- `unique (user_id, organization_id)` — una relación por par, permanente.
- `index (organization_id, status)` — miembros activos por empresa.
- `index (user_id, status)` — empresas activas por usuario.

El índice único cubre además la comprobación de FK a usuario (lookup por `user_id` +
`organization_id`), por lo que **no** hace falta un índice adicional para esa validación.

**No** se incluyen en la primera migración: `created_by`, `revoked_by`, `membership_type`,
`source`. La trazabilidad se cubre con `audit_logs`; los dos últimos se reevalúan cuando existan
usuarios de clientes.

### 5.3 Relaciones

- `User::memberships()` → `hasMany(OrganizationMembership::class)`.
- `User::organizations()` → `belongsToMany(Organization::class, 'organization_memberships')` con
  `withPivot('status', 'revoked_at')`.
- `Organization::memberships()` → `hasMany`.
- `Organization::members()` → `belongsToMany(User::class, 'organization_memberships')`.
- `Organization::users()` se conserva como **“usuarios con adscripción de origen”**; se documenta
  que no es fuente de acceso.
- `OrganizationMembership::user()` / `organization()` → `belongsTo`.

### 5.4 Invariantes del modelo

1. Acceso efectivo = `users.status` **y** `organizations.status` **y** `organizations.deleted_at IS NULL`
   **y** `organizations.type = 'client'` **y** `membership.status` **y** token válido.
2. Un `user_roles` requiere membresía activa y un rol de la misma organización.
3. Un `user_plants` / `user_areas` requiere membresía activa y planta/área de la misma organización.
4. Todo registro de dominio pertenece a una organización `client`; nunca al operador.
5. Las FK a `User` se validan por membresía, no por `users.organization_id`.
6. `users.email` es único global, incluso para usuarios desactivados.
7. `roles.name` es único por organización; `plants.code` es único por organización.
8. `organization_id` de un registro no se modifica desde HTTP (comportamiento ya vigente).

### 5.5 `users.organization_id`

Se mantiene `NOT NULL`, se retira de `#[Fillable]` y se documenta como **adscripción de origen**
("home organization"), nunca como permiso. Para consultores, su valor es el id de la organización
operadora. La nulabilidad y la retirada definitiva se deciden en P5, cuando ningún código la use
para autorizar. [Nota 2026-10-28] `organization_id` sigue en `#[Fillable]` y `User` sigue usando
`BelongsToOrganization` en el código de P1; ambos se retiran en P3.

### 5.6 Restricciones de integridad deliberadamente **no** incluidas

- FK compuesta `(user_id, organization_id)` de pivotes hacia membresías: obligaría a que las
  membresías nunca se borren (ya es el caso) pero complica la migración y no aporta protección
  frente a membresías desactivadas, que es el caso real.
- FK compuesta de `inspections.user_id`, `audit_logs.user_id`, etc. hacia membresías: impediría
  registros históricos. Se valida en aplicación.
- `CHECK` de `organizations.type IN ('client','operator')`: MariaDB lo soporta desde 10.2, pero
  añade fricción en SQLite y en futuras evoluciones. Se valida en aplicación y en pruebas.

---

## 6. Plan de datos (resumen)

Detalle completo, SQL de prechecks, condiciones de parada y rollback en
[`04`](04-migraciones-backfill-indices.md).

| Etapa | Tipo | Cambia datos | Reversible |
|---|---|---|---|
| Crear `organization_memberships` | Aditiva | No | `DROP TABLE` |
| Añadir `organizations.type` | Aditiva | No (default) | `DROP COLUMN` |
| Backfill de membresías | Datos | Sí (solo tabla nueva) | `TRUNCATE` / `DROP` |
| Índices de roles y plantas | Índices | No | Recrear índice anterior |
| Retirar `users.organization_id` de fillable | Código | No | Revertir deploy |

Regla: **nunca** se inventan membresías. Un usuario sin organización se reporta y se detiene la
migración.

---

## 7. Cambios por componente (resumen)

Detalle por fase en [`06`](06-fases-y-pruebas.md) §1.

| Componente | Cambio | Fase |
|---|---|---|
| `app/Models/OrganizationMembership.php` (nuevo) | Modelo, relaciones, casts | P1 |
| Migración `create_organization_memberships_table` | Tabla, FKs, índices | P1 |
| Migración `add_type_to_organizations_table` | `type` + índice | P1 |
| `app/Console/Commands/BackfillOrganizationMemberships.php` (nuevo) | Backfill idempotente, dry-run, reporte | P1 |
| `app/Models/User.php` | Membresías; doble escritura (P1); quitar `BelongsToOrganization` y `organization_id` de fillable (P3); helpers por contexto | P1/P3 |
| `app/Models/Organization.php` | `memberships()`, `members()`, `isOperator()`, `scopeClients()` | P1 |
| Migración de índices de roles | Único `(organization_id, name)` | P2 |
| Migración de índices de plantas | Único `(organization_id, code)` | P2 |
| `app/Http/Middleware/ResolveTenantContext.php` | Cabecera, membresía, tipo, 400/403 | P3 |
| `app/Support/Tenancy/TenantContext.php` | `requireId()`, `withoutTenant($reason, ...)` | P3 |
| `app/Policies/TenantPolicy.php` + `PlatformOperatorPolicy` (nuevo) | Membresía y rol por contexto | P3 |
| `app/Models/Concerns/BelongsToOrganization.php` | FK a usuario por membresía; solo dirty en update | P3 |
| `app/Http/Controllers/Auth/AuthController.php` | Login global, `/auth/me` sin tenant, `/auth/organizations` | P3 |
| `routes/api.php` | `/auth/me` y `/auth/organizations` con `api.auth` | P3 |
| `database/seeders/*` | Multiempresa, sin `first()`, IDs deterministas | P4 |
| `database/factories/*` | Estados de membresía; sin organización automática por usuario | P4 |
| `tests/Support/TenantFixtures.php` | Membresías y cabecera | P3/P4 |
| `openapi.yaml` | Cabecera, endpoints, esquemas, errores | P4 |
| `app/Support/Audit/AuditLogger.php` (nuevo) | Escritura append-only con excepción de actor | P3 |

**Sin cambios:** `app/Exceptions/Handler.php` (429 intacto), `EnsureApiAuthenticated`,
`config/sanctum.php`, `config/auth.php`, `Device`, `SyncOperation`, `SyncChange`, módulos de
dominio pendientes.

---

## 8. Riesgos principales

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Usuarios creados durante la ventana de backfill sin membresía | Acceso denegado o fallback inseguro | Expand/contract con doble escritura (D-10) |
| Organización operadora tratada como tenant | Fuga entre consultora y clientes | `type` + rechazo en resolver + pruebas T41/T42 |
| Borrado físico de usuario | Pérdida de actor en auditoría | Prohibición + policy + prueba T45 |
| Revocación bloquea escritura de auditoría | Pérdida de trazabilidad | `AuditLogger` con excepción documentada |
| Índices bloquean MariaDB | Downtime | Ventana, backup, DDL online, precheck |
| `User` global expone listados | Fuga de datos | `UserPolicy`, filtros explícitos, pruebas T34 |
| Fallback temporal se queda instalado | Autorización por `users.organization_id` | Fecha de retiro en P3, prueba de grep en P5 |
| Header ausente | 400 o consultas amplias | `TenantController` + prueba de cobertura de rutas |
| Contrato rompe clientes | Integraciones fallidas | Decisión de versión (pendiente D-16) |

---

## 9. Fases

| Fase | Alcance | Gate |
|---|---|---|
| **P0** | Decisiones + preflight read-only | Decisiones firmadas; reporte sin condiciones de parada |
| **P1** | Tabla, modelo, backfill, doble escritura | Suite M1 intacta + 6 pruebas nuevas; backfill idempotente; rollback probado |
| **P2** | Índices de roles y plantas | Up/down en SQLite; duplicados 0; T36/T37 |
| **P3** | Contexto, auth, policies, FK, fail-closed | T01–T22, T26–T28, T30–T35, T39–T46 |
| **P4** | Seeders, factories, fixtures, OpenAPI | T23, T29, suite completa, seed doble |
| **P5** | Deprecación y endurecimiento | Pruebas de arquitectura/grep; suite completa |

**Regla:** M2 no comienza hasta que P3, P4 y P5 estén verdes. Detalle en
[`06`](06-fases-y-pruebas.md).

---

## 10. Cierre

No se implementó nada. El plan anterior queda corregido en el orden de índices, el tratamiento de
borrado, la validación de FK en actualización, la estrategia fail-closed y la carrera del backfill.
Las decisiones que aún requieren aprobación están en
[`07`](07-contradicciones-y-decisiones-pendientes.md) §2.
