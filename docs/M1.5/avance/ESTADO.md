# Estado del avance — P1 de M1.5

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1 y con la lista oficial de decisiones)

**Fecha de la sesión:** 2026-10-28
**Rama:** `feature/inspecta-m15-p1-p2`
**HEAD:** `0849b0b fix(m15): seeders crean membresías vía MembershipBackfill (P1.4 corregido)`
**Pruebas (VERIFICADO):** 90 pruebas, 274 aserciones. Línea base M1 intacta: 63 pruebas, 215 aserciones.
**Estado:** P1 cerrado; P2 sin iniciar; sin push.

---

## 2. Estado por fase

| Fase | Estado | Alcance |
|------|--------|---------|
| P0 — Decisiones y preflight | Completada (documental) | Decisiones aprobadas (D-01 a D-15, excepto las marcadas pendiente). Preflight de solo lectura ejecutado en sesiones anteriores. |
| P1 — Membresías, backfill y doble escritura | Completada | Tabla `organization_memberships`, `organizations.type`, backfill idempotente, doble escritura en `User`. Seeders generan membresías. |
| P2 — Índices de unicidad | Sin iniciar | Únicos `(organization_id, name)` en roles y `(organization_id, code)` en plants. Retiro de únicos globales. |
| P3 — Contexto, auth, policies, FK | Sin iniciar | `User` sin `BelongsToOrganization`, `ResolveTenantContext` por cabecera y membresía, `TenantController`, `PlatformOperatorPolicy`, `/auth/organizations`, `AuditLogger`. |
| P4 — Seeders, factories, fixtures, OpenAPI | Sin iniciar | Seeders multiempresa, IDs deterministas, `openapi.yaml` 1.4.0. |
| P5 — Deprecación y endurecimiento | Sin iniciar | Modo estricto del `OrganizationScope`, allowlist de `withoutTenant`, retirada de uso autorizante de `users.organization_id`. |

---

## 3. Qué existe hoy en el código de M1.5 (VERIFICADO)

| Componente | Ruta | Notas |
|------------|------|-------|
| Migración: tabla `organization_memberships` | `database/migrations/2026_10_28_000001_create_organization_memberships_table.php` | `id char(36) PK`, `user_id char(36) NOT NULL` (FK users, restrict/cascade), `organization_id char(36) NOT NULL` (FK organizations, restrict/cascade), `status string(20) NOT NULL default 'active'`, `revoked_at timestamp nullable`, `timestamps`. Único `(user_id, organization_id)`; índices `(organization_id, status)` y `(user_id, status)`. |
| Migración: `organizations.type` | `database/migrations/2026_10_28_000002_add_type_to_organizations_table.php` | `type string(20) NOT NULL default 'client'` después de `code`; índice `(type, status)`. |
| Migración: backfill | `database/migrations/2026_10_28_000003_backfill_organization_memberships.php` | Invoca `MembershipBackfill::run()`; `down()` sin operación. |
| Modelo `OrganizationMembership` | `app/Models/OrganizationMembership.php` | Sin `BelongsToOrganization`. Constantes `STATUS_ACTIVE='active'`, `STATUS_REVOKED='revoked'`. UUIDv7 en `booted()`. Relaciones `user()` y `organization()`. Método `isActive()`. |
| Clase `MembershipBackfill` | `app/Support/Tenancy/MembershipBackfill.php` | `run()` y `dryRun()` usando `DB::table` en chunks. Idempotente. Devuelve contadores. |
| Hook de doble escritura en `User` | `app/Models/User.php` (líneas 170-207) | `booted()` con `static::saved` que filtra `wasRecentlyCreated || wasChanged('organization_id')`. `ensureMembership()` usa `firstOrCreate` y nunca modifica una membresía existente. |
| Relación `User::memberships()` | `app/Models/User.php` (línea 54-57) | `hasMany(OrganizationMembership::class, 'user_id')`. |
| Factory `OrganizationMembership` | `database/factories/OrganizationMembershipFactory.php` | Estado `revoked()` disponible. |
| Factory `Organization` actualizada | `database/factories/OrganizationFactory.php` | `type` por defecto `TYPE_CLIENT`. |
| Seeder `Organization` actualizado | `database/seeders/OrganizationSeeder.php` | `INSP-01` con `type` explícito `TYPE_CLIENT`. |
| Seeder `User` actualizado | `database/seeders/UserSeeder.php` | Invoca `MembershipBackfill::run()` al final de `run()`. Mantiene `WithoutModelEvents`. |
| Pruebas: membresías | `tests/Feature/Tenancy/OrganizationMembershipTest.php` | 5 pruebas |
| Pruebas: tipo de organización | `tests/Feature/Tenancy/OrganizationTypeTest.php` | 3 pruebas |
| Pruebas: backfill | `tests/Feature/Tenancy/MembershipBackfillTest.php` | 4 pruebas |
| Pruebas: doble escritura | `tests/Feature/Tenancy/UserMembershipDoubleWriteTest.php` | 5 pruebas (1 renombrada) |
| Pruebas: integridad | `tests/Feature/Tenancy/MembershipIntegrityTest.php` | 9 pruebas (6 de doble escritura + 4 de seeders) |

---

## 4. Qué NO existe todavía (VERIFICADO)

- Sin FK compuestas `(user_id, organization_id)` en pivotes hacia membresías.
- Sin únicos `(organization_id, name)` en roles ni `(organization_id, code)` en plants.
- Sin cabecera `X-Organization-Id` ni middleware que la resuelva.
- Sin cambios en políticas (`Policies/`), middleware (`Http/Middleware/`), `AuthController` ni `openapi.yaml`.
- `users.organization_id` sigue siendo la fuente de autorización y el login sigue leyéndola (por email solo, sin filtro de organización).
- La membresía revocada aún no tiene ningún efecto: el hook no la reactiva ni la usa para denegar acceso.
- Sin organización operadora (`INSPECTA-OP`) creada.
- `organization_id` sigue en `#[Fillable]` de `User`.
- `User` sigue usando `BelongsToOrganization` (con `OrganizationScope`).
- `hasRole()` filtra solo por `roles.name`, sin filtro de organización.
- `sync_operations` y `devices` no tienen `organization_id`.

---

## 5. Decisiones (REPORTADO)

Las siguientes decisiones provienen de sesiones anteriores y del plan `docs/m1.5/`. No se pueden verificar en el repo; están listadas como referencia.

**APROBADAS:**
- D-01: `organizations.type = 'operator'` para la consultora.
- D-02: membresías sin soft delete; `status` + `revoked_at`.
- D-03: no borrado físico de usuarios.
- D-04: suspender y archivar organizaciones sin eliminar datos.
- D-05: retención indefinida, sin purga automática.
- D-06: sin revocación masiva de tokens en M1.5.
- D-09: únicos por organización en roles `(organization_id, name)` y plants `(organization_id, code)`. `norms` diferido (D-17).
- D-10: estrategia expand/contract con doble escritura temporal.
- D-12: separación A/B/C y orden P0 a P5.
- D-13: MariaDB 11.8 LTS como versión objetivo de desarrollo (verificada 11.8.9 en sesión anterior).
- D-14: preflight de solo lectura y backup verificable antes de producción.
- D-15 opción A: FK compuestas en `user_roles`, `user_plants`, `user_areas` hacia membresías y hacia su recurso.
- D-19: métricas entre clientes por vía separada, con consentimiento por organización; implementación diferida.
- D-20: roles, plantas y áreas se evalúan contra la organización activa y exigen membresía activa, en un solo punto (se implementa en P3).
- D-22 (2026-10-28): `organization_memberships.status` es `string(20)` NOT NULL con valores `'active'` y `'revoked'` (default `'active'`), junto con `revoked_at`. No es boolean.
- D-23 (2026-10-28): no se añade `joined_at`; `created_at` es la fecha de alta de la membresía. `created_at` y `updated_at` son NOT NULL (`timestamps()` de Laravel).

**PENDIENTES:**
- D-07 (P3): política de FK a usuarios y validación de campos modificados.
- D-08 (P3/P5): fail-closed en fronteras de P3 y scope estricto en P5.
- D-11 (P3): auditoría append-only mediante `AuditLogger`.
- D-16 (P4): versión de OpenAPI; recomendación vigente: 2.0.0 con ruptura (panel y PWA aún no consumen `AuthUser`).
- D-17: unicidad de `norms (code, version)` por organización; diferida a M2.
- D-18 (P4): configuración efectiva de CORS para `X-Organization-Id`.
- D-21 (propuesta, P3): `User` sale del scope de organización; listados de usuarios de una organización parten de las membresías.

**Divergencias con docs/m1.5:** resueltas por D-22 y D-23 (ver §5 APROBADAS); las restantes se marcan como DIFERIDAS en §6.

---

## 6. Divergencias entre docs/m1.5 y el código

| Punto | Qué dice el documento | Qué hay en código | Estado |
|-------|----------------------|-------------------|--------|
| `status` de membresía | `boolean NOT NULL DEFAULT true` (04 §2, 03 §4.1) | `string(20) NOT NULL DEFAULT 'active'` con valores `'active'`/`'revoked'` | RESUELTA (D-22, 2026-10-28; docs alineados) |
| `joined_at` | `timestamp NULL` en `organization_memberships` (04 §2) | Columna ausente | RESUELTA (D-23, 2026-10-28; docs alineados) |
| `created_at` / `updated_at` | `timestamp NULL` (04 §2) | `timestamps()` de Laravel (NOT NULL por defecto en MariaDB) | RESUELTA (D-23, 2026-10-28; docs alineados) |
| Transacción al crear usuario | Pide doble escritura en misma transacción (04 §3 A3) | No hay `DB::transaction` en el hook | DIFERIDA (P3 o posterior; endpoint de creación de usuarios) |
| Organización operadora INSPECTA-OP | Documentada con código reservado (02 §4.1) | No creada; solo columna `type` y constantes | DIFERIDA (P4; seeders multiempresa) |
| `organization_id` en `#[Fillable]` y `BelongsToOrganization` en `User` | Se retiran en P3 | Siguen presentes en P1 | DIFERIDA (P3; vigente en el código de P1) |

---

## 7. Riesgos y alertas vigentes (REPORTADO)

- La membresía revocada no tiene efecto hasta P3: el login y el contexto siguen leyendo `users.organization_id`.
- El hook de doble escritura no corre en transacción y se salta con `saveQuietly`, `withoutEvents` o `DB::table`.
- Con FK compuestas hacia membresías, `UserSeeder` deberá crear la membresía antes que los pivotes (reordenar en P2.3).
- Las pruebas en SQLite no demuestran las FK compuestas; esa verificación vive contra MariaDB.
- `OrganizationScope` sigue siendo fail-open en lecturas sin contexto (D-08).
- `User` sigue bajo `BelongsToOrganization`: con varias membresías sería invisible en una organización distinta a la de origen (D-21, P3).
- `hasRole()` filtra solo por nombre; con D-09 y varias organizaciones sería una escalada de privilegios si no se corrige en P3 (D-20).
- Los documentos de `docs/m1.5/` pueden contradecir al código hasta que se actualicen.
- `sync_operations` y `devices` no tienen `organization_id`.
- La CI usa SQLite y no cubre MariaDB.

---

## 8. Próximo paso: P2

| Paso | Alcance (una línea) | Gate |
|------|---------------------|------|
| 2.1 | Únicos `(id, organization_id)` en roles, plants y areas | Suite verde |
| 2.2 | D-09: únicos por organización en roles y plants; retiro de los globales | Suite verde; no hay duplicados |
| 2.3 | D-15 A: 6 FK compuestas en `user_roles`, `user_plants`, `user_areas`; reordenar `UserSeeder`; verificación en MariaDB con inserción cruzada | Suite verde; MariaDB verificado |

El prompt completo de implementación de P2 lo guarda el usuario fuera del repo. No se escribe aquí.

---

## 9. Cómo retomar (checklist de la siguiente sesión)

1. Rama `feature/inspecta-m15-p1-p2`.
2. HEAD esperado = el HEAD que verifiques hoy (si el usuario commitea estos documentos, será HEAD más un commit de docs).
3. Suite esperada: 90 pruebas, 274 aserciones (línea base M1: 63 pruebas, 215 aserciones).
4. Árbol limpio salvo `docs/` (si no se ha commiteado).
5. Docker disponible y puerto 33061 libre (para verificación en MariaDB).
6. Leer este `ESTADO.md` antes de empezar.
7. Tras el commit docs(m1.5) hecho sobre 0849b0b, el HEAD esperado para P2 es ese commit (ver git log -1); el código es idéntico a 0849b0b (git diff --stat 0849b0b..HEAD solo toca docs/M1.5).

---

## 10. Restricciones permanentes

- No hacer push.
- No modificar migraciones existentes ni pruebas previas a P1.
- No implementar P3 ni `/sync/*` ni `/devices/*`.
- No tocar `.env`.
- MariaDB solo en contenedor aislado con variables en línea.
- Las aprobaciones de arquitectura no autorizan por sí solas cambios de esquema.

---

*Fin del documento ESTADO.md.*
