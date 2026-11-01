# Estado del avance — P2 de M1.5

Última actualización: 2026-10-29

**Fecha de la sesión:** 2026-10-29
**Rama:** `feature/inspecta-m15-p1-p2`
**HEAD:** `1175802 test(m15): pruebas de violación de FK compuestas en pivotes contra SQLite (P2 cierre)`
**Pruebas (VERIFICADO):** 108 pruebas, 318 aserciones. Línea base M1 intacta: 63 pruebas, 215 aserciones.
**Estado:** P0 completada; P1 cerrada; P2 cerrada; P3 a P5 sin iniciar. Push a GitHub realizado (rama actualizada con origin).

---

## 2. Estado por fase

| Fase | Estado | Alcance |
|------|--------|---------|
| P0 — Decisiones y preflight | Completada (documental) | Decisiones aprobadas (D-01 a D-15, excepto las marcadas pendiente). Preflight de solo lectura ejecutado en sesiones anteriores. |
| P1 — Membresías, backfill y doble escritura | Cerrada | Tabla `organization_memberships`, `organizations.type`, backfill idempotente, doble escritura en `User`. Seeders generan membresías. |
| P2 — Índices de unicidad y FK compuestas | Cerrada | Únicos `(id, organization_id)` en roles, plants y areas. Únicos `(organization_id, name)` en roles y `(organization_id, code)` en plants; retirados los únicos globales. 6 FK compuestas en `user_roles`, `user_plants`, `user_areas`. `UserSeeder` reordenado (usuarios → backfill → pivotes). |
| P3 — Contexto, auth, policies, FK | Sin iniciar | `User` sin `BelongsToOrganization`, `ResolveTenantContext` por cabecera y membresía, `TenantController`, `PlatformOperatorPolicy`, `/auth/organizations`, `AuditLogger`. D-20 es bloqueante. |
| P4 — Seeders, factories, fixtures, OpenAPI | Sin iniciar | Seeders multiempresa, IDs deterministas, `openapi.yaml` 1.4.0. |
| P5 — Deprecación y endurecimiento | Sin iniciar | Modo estricto del `OrganizationScope`, allowlist de `withoutTenant`, retirada de uso autorizante de `users.organization_id`. |

---

## 3. Qué existe hoy en el código de M1.5 (VERIFICADO)

| Componente | Ruta | Notas |
|------------|------|-------|
| Migración: tabla `organization_memberships` | `database/migrations/2026_10_28_000001_create_organization_memberships_table.php` | `id char(36) PK`, `user_id char(36) NOT NULL` (FK users, restrict/cascade), `organization_id char(36) NOT NULL` (FK organizations, restrict/cascade), `status string(20) NOT NULL default 'active'`, `revoked_at timestamp nullable`, `timestamps`. Único `(user_id, organization_id)`; índices `(organization_id, status)` y `(user_id, status)`. |
| Migración: `organizations.type` | `database/migrations/2026_10_28_000002_add_type_to_organizations_table.php` | `type string(20) NOT NULL default 'client'` después de `code`; índice `(type, status)`. |
| Migración: backfill | `database/migrations/2026_10_28_000003_backfill_organization_memberships.php` | Invoca `MembershipBackfill::run()`; `down()` sin operación. |
| Migración: únicos auxiliares (P2.1) | `database/migrations/2026_10_29_000001_add_id_org_unique_to_roles_plants_areas.php` | Único `(id, organization_id)` en roles, plants y areas (`roles_id_org_unique`, `plants_id_org_unique`, `areas_id_org_unique`). |
| Migración: únicos por organización (P2.2) | `database/migrations/2026_10_29_000002_unique_by_organization_roles_plants.php` | Único `(organization_id, name)` en roles (`roles_org_name_unique`); único `(organization_id, code)` en plants (`plants_org_code_unique`); retira `roles_name_unique` y `plants_code_unique`. |
| Migración: FK compuestas (P2.3) | `database/migrations/2026_10_29_000003_composite_pivot_foreign_keys.php` | 6 FK compuestas con `restrictOnDelete()->cascadeOnUpdate()` en user_roles, user_plants, user_areas hacia `organization_memberships` y hacia roles/plants/areas. |
| Modelo `OrganizationMembership` | `app/Models/OrganizationMembership.php` | Sin `BelongsToOrganization`. Constantes `STATUS_ACTIVE='active'`, `STATUS_REVOKED='revoked'`. UUIDv7 en `booted()`. Relaciones `user()` y `organization()`. Método `isActive()`. |
| Clase `MembershipBackfill` | `app/Support/Tenancy/MembershipBackfill.php` | `run()` y `dryRun()` usando `DB::table` en chunks. Idempotente. Devuelve contadores. |
| Hook de doble escritura en `User` | `app/Models/User.php` (líneas 170-207) | `booted()` con `static::saved` que filtra `wasRecentlyCreated || wasChanged('organization_id')`. `ensureMembership()` usa `firstOrCreate` y nunca modifica una membresía existente. |
| Relación `User::memberships()` | `app/Models/User.php` (línea 54-57) | `hasMany(OrganizationMembership::class, 'user_id')`. |
| Factory `OrganizationMembership` | `database/factories/OrganizationMembershipFactory.php` | Estado `revoked()` disponible. |
| Factory `Organization` actualizada | `database/factories/OrganizationFactory.php` | `type` por defecto `TYPE_CLIENT`. |
| Seeder `Organization` actualizado | `database/seeders/OrganizationSeeder.php` | `INSP-01` con `type` explícito `TYPE_CLIENT`. |
| Seeder `User` reordenado (P2.3) | `database/seeders/UserSeeder.php` | Usuarios → `MembershipBackfill::run()` → pivotes. Mantiene `WithoutModelEvents`. |
| Pruebas: membresías | `tests/Feature/Tenancy/OrganizationMembershipTest.php` | 5 pruebas |
| Pruebas: tipo de organización | `tests/Feature/Tenancy/OrganizationTypeTest.php` | 3 pruebas |
| Pruebas: backfill | `tests/Feature/Tenancy/MembershipBackfillTest.php` | 4 pruebas |
| Pruebas: doble escritura | `tests/Feature/Tenancy/UserMembershipDoubleWriteTest.php` | 5 pruebas (1 renombrada) |
| Pruebas: integridad | `tests/Feature/Tenancy/MembershipIntegrityTest.php` | 9 pruebas (6 de doble escritura + 4 de seeders) |
| Pruebas: unicidad por organización (P2.2) | `tests/Feature/Tenancy/OrganizationUniquenessTest.php` | 5 pruebas |
| Pruebas: FK compuestas existen (P2.3) | `tests/Feature/Tenancy/CompositePivotForeignKeyTest.php` | 2 pruebas |
| Pruebas: violación de FK compuestas (P2 cierre) | `tests/Feature/Tenancy/CompositePivotForeignKeyViolationTest.php` | 11 pruebas |

---

## 4. Qué NO existe todavía (VERIFICADO)

- Sin cabecera `X-Organization-Id` ni middleware que la resuelva.
- Sin cambios en políticas (`Policies/`), middleware (`Http/Middleware/`), `AuthController` ni `openapi.yaml`.
- `users.organization_id` sigue siendo la fuente de autorización y el login sigue leyéndola (por email solo, sin filtro de organización).
- La membresía revocada aún no tiene ningún efecto: el hook no la reactiva ni la usa para denegar acceso.
- Sin organización operadora (`INSPECTA-OP`) creada.
- `organization_id` sigue en `#[Fillable]` de `User`.
- `User` sigue usando `BelongsToOrganization` (con `OrganizationScope`).
- `hasRole()` filtra solo por `roles.name`, sin filtro de organización.
- `sync_operations` y `devices` no tienen `organization_id`.
- Sin cambios en políticas, middleware, scopes, AuthController ni `users.organization_id` (por restricción de alcance de P2).

---

## 5. Decisiones

**IMPLEMENTADAS (P2):**
- D-09: únicos por organización en roles `(organization_id, name)` y plants `(organization_id, code)`. `norms` diferido (D-17).
- D-15 opción A: FK compuestas en `user_roles`, `user_plants`, `user_areas` hacia membresías y hacia su recurso.

**BLOQUEANTE de P3:**
- D-20: roles, plantas y áreas se evalúan contra la organización activa y exigen membresía activa, en un solo punto (se implementa en P3).

**APROBADAS (de sesiones anteriores):**
- D-01: `organizations.type = 'operator'` para la consultora.
- D-02: membresías sin soft delete; `status` + `revoked_at`.
- D-03: no borrado físico de usuarios.
- D-04: suspender y archivar organizaciones sin eliminar datos.
- D-05: retención indefinida, sin purga automática.
- D-06: sin revocación masiva de tokens en M1.5.
- D-10: estrategia expand/contract con doble escritura temporal.
- D-12: separación A/B/C y orden P0 a P5.
- D-13: MariaDB 11.8 LTS como versión objetivo de desarrollo (verificada 11.8.9).
- D-14: preflight de solo lectura y backup verificable antes de producción.
- D-19: métricas entre clientes por vía separada, con consentimiento por organización; implementación diferida.
- D-22 (2026-10-28): `organization_memberships.status` es `string(20)` NOT NULL con valores `'active'` y `'revoked'` (default `'active'`), junto con `revoked_at`. No es boolean.
- D-23 (2026-10-28): no se añade `joined_at`; `created_at` es la fecha de alta de la membresía. `created_at` y `updated_at` son NOT NULL (`timestamps()` de Laravel).

**PENDIENTES (propuestas):**
- D-07 (P3): política de FK a usuarios y validación de campos modificados.
- D-08 (P3/P5): fail-closed en fronteras de P3 y scope estricto en P5.
- D-11 (P3): auditoría append-only mediante `AuditLogger`.
- D-16 (P4): versión de OpenAPI; recomendación vigente: 2.0.0 con ruptura.
- D-17: unicidad de `norms (code, version)` por organización; diferida a M2.
- D-18 (P4): configuración efectiva de CORS para `X-Organization-Id`.
- D-21 (propuesta, P3): `User` sale del scope de organización; listados de usuarios de una organización parten de las membresías.

**PROPUESTAS (no aprobadas, nuevas en esta sesión):**
- D-24: `UserSeeder` solo usa contraseñas fijas en entorno local; en otros entornos las toma de variables de entorno o falla.
- D-25: job de CI con MariaDB 11.8 como servicio que ejecute `migrate:fresh --seed`.

---

## 6. Estado de Git (REPORTADO y VERIFICADO)

- M1 ya está en `master` (merge commits de los PR #2 y #3, hash `500b169`).
- La rama `feature/inspecta-m15-p1-p2` está publicada en GitHub (push fast-forward, sin force).
- El método de merge del equipo es merge commit.
- El merge de `master` en `M15` se ensayó en rama temporal `tmp/merge-check-master` (resultado: sin conflictos, solo `openapi.yaml` cambia con placeholders de credenciales, 108 pruebas verdes). Rama temporal eliminada.
- PR de `M15` contra `master`: borrador, no creado.
- El PR de `organizacion-esquema-bd` es redundante (rama `feature/inspecta-organizacion-esquema-bd` existe en local y remoto).

---

## 7. Alertas y riesgos vigentes

| Riesgo | Estado |
|--------|--------|
| La membresía revocada no tiene efecto hasta P3 | ABIERTA |
| El hook de doble escritura no corre en transacción y se salta con `saveQuietly`, `withoutEvents` o `DB::table` | ABIERTA |
| Con FK compuestas hacia membresías, `UserSeeder` debe crear la membresía antes que los pivotes (ya reordenado en P2.3) | MITIGADA |
| Las pruebas en SQLite no demuestran las FK compuestas; esa verificación vive contra MariaDB | MITIGADA (verificado en contenedor) |
| `OrganizationScope` sigue siendo fail-open en lecturas sin contexto (D-08) | ABIERTA |
| `User` sigue bajo `BelongsToOrganization`: con varias membresías sería invisible en una organización distinta a la de origen (D-21, P3) | ABIERTA |
| `hasRole()` filtra solo por nombre; con D-09 y varias organizaciones sería una escalada de privilegios si no se corrige en P3 (D-20, BLOQUEANTE) | ABIERTA |
| Los documentos de `docs/m1.5/` pueden contradecir al código hasta que se actualicen | ABIERTA |
| `sync_operations` y `devices` no tienen `organization_id` | ABIERTA |
| La CI usa SQLite y no cubre MariaDB; simulación en PHP 8.3 falla, en PHP 8.4 OK (108/318) | MITIGADA (parcial) |
| `pint --test`: 44 archivos con problemas de estilo (18 creados/modificados entre 40ed371 y HEAD) | ABIERTA (se resuelve en PR de estilo posterior) |
| Alerta de GitGuardian (credenciales de ejemplo en openapi.yaml, corregido con `445bf18`, `542637e`, `32f681c`; valor permanece en historial `46308ff`) | ABIERTA (cierre manual pendiente del usuario) |
| `docs/.obsidian` y `docs/ACCESOS_Y_PERMISOS.md` excluidos localmente y nunca versionados | MITIGADA |
| Puerta sin retorno: tras usar D-09 con datos reales, el `down()` de 2.2 falla; D-14 debe cumplirse antes de producción | ABIERTA |

---

## 8. Restricciones permanentes (actualizadas)

- Nunca se hace push de `master`.
- El push de ramas de funcionalidad solo con autorización explícita de la sesión y sin `--force`.
- No modificar migraciones existentes ni pruebas previas.
- No implementar P3, `/sync/*` ni `/devices/*` sin autorización.
- No tocar `.env`.
- MariaDB solo en contenedor aislado con variables en línea.
- Las aprobaciones de arquitectura no autorizan por sí solas cambios de esquema.

---

## 9. Cómo retomar (checklist de la siguiente sesión)

1. Rama `feature/inspecta-m15-p1-p2`.
2. HEAD esperado = `1175802` (commit de documentación de esta sesión).
3. Suite esperada: 108 pruebas, 318 aserciones (línea base M1: 63 pruebas, 215 aserciones).
4. Árbol limpio salvo `docs/M1.5` (si no se ha commiteado).
5. Leer este `ESTADO.md` antes de empezar.
6. Tras el commit `docs(m1.5)` hecho sobre `1175802`, el HEAD esperado para P3 es ese commit.
7. Los documentos de `docs/M1.5` quedan commiteados; `docs/.obsidian` y `docs/ACCESOS_Y_PERMISOS.md` se excluyen de forma local (`.git/info/exclude`) y nunca se versionan.

---

*Fin del documento ESTADO.md.*