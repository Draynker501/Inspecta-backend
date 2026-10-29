# Bitácora de sesión — Documentación M1.5 alineada con P1 (2026-10-28)

**Estado:** inmutable. No se modifica tras crear.

---

## 1. Resumen de la sesión (máx. 10 líneas)

Se actualizó exclusivamente la documentación de `docs/M1.5` para eliminar contradicciones con el código de P1 (HEAD `0849b0b`), registrando las decisiones aprobadas D-22 y D-23 (fecha 2026-10-28). No se implementó código ni se tocó nada fuera de `docs/`. Se creó una copia de respaldo de `docs/` en `/tmp/docs-backup-20261028` antes de editar.

---

## 2. Archivos modificados

| # | Archivo | Cambios |
|---|---------|---------|
| 1 | `04-migraciones-backfill-indices.md` | §2 A1: `status` string(20) NOT NULL default `'active'`, sin `joined_at`, `created_at`/`updated_at` NOT NULL, nota D-23. Nota de transacción diferida en A3. §3 B1: backfill real con `DB::table` en chunks, `status='active'`, `created_at`/`updated_at`=`now()`, idempotente, sin modificar existentes, sin `joined_at`. |
| 2 | `03-ciclo-de-vida-usuarios-membresias.md` | §4.1: revocar `status='revoked'`+`revoked_at`, reactivar `status='active'`+`revoked_at=null` (operación explícita de P3), sin `joined_at`. Fórmula de acceso: `membership.status = 'active'`. |
| 3 | `01-plan-m1.5-revisado.md` | D-02: sin `joined_at`, `status` string. Esquema: `status` string(20), timestamps NOT NULL. `withPivot` sin `joined_at`. Notas P3 sobre `#[Fillable]` y `BelongsToOrganization`. |
| 4 | `02-modelo-operador-consultora.md` | Nota: `INSPECTA-OP` no creada, se crea en P4 con seeders multiempresa. |
| 5 | `06-fases-y-pruebas.md` | T47: nota de que el hook no usa transacción (evento `saved`), diferido a P3+. |
| 6 | `05-contexto-tenant-y-fk-usuarios.md` | `where(status, 'active')` en pseudocódigo de `assertActiveMembership()`. |
| 7 | `avance/ESTADO.md` | §5: D-22 y D-23 movidas a APROBADAS con fecha. Eliminada decisión pendente "status / joined_at". §6: divergencias marcadas RESUELTA (D-22, D-23, timestamps) o DIFERIDA (transacción P3+, INSPECTA-OP P4, Fillable/BelongsToOrganization P3). §9: línea de retorno añadida. |

---

## 3. Cambios aplicados (texto ANTERIOR → texto NUEVO)

### 3.1 `04-migraciones-backfill-indices.md` — §2 A1 (tabla de esquema)

**ANTERIOR:**
```
  status            boolean    NOT NULL  DEFAULT true
  joined_at         timestamp  NULL
  revoked_at        timestamp  NULL
  created_at        timestamp  NULL
  updated_at        timestamp  NULL
```
**NUEVO:**
```
  status            string(20) NOT NULL  DEFAULT 'active'   -- valores: 'active', 'revoked' (D-22)
  revoked_at        timestamp  NULL
  created_at        timestamp  NOT NULL   -- timestamps() de Laravel
  updated_at        timestamp  NOT NULL   -- timestamps() de Laravel
```
**Estado:** CONFIRMADO (coincide con `database/migrations/2026_10_28_000001_create_organization_memberships_table.php`).

**ANTERIOR (nota):**
- `joined_at` puede quedar nulo en datos históricos sin fecha fiable; el backfill usa `users.created_at` cuando existe.
**NUEVO (nota):**
- D-23: no se añade `joined_at`; `created_at` es la fecha de alta de la membresía.
**Estado:** CONFIRMADO (la migración no tiene columna `joined_at`; D-23 del prompt).

### 3.2 `04-migraciones-backfill-indices.md` — §3 A3 (misma transacción)

**ANTERIOR:**
Al crear un usuario, crear su membresía en `users.organization_id` dentro de la misma transacción.
**NUEVO:**
Al crear un usuario, crear su membresía en `users.organization_id`.
[Nota 2026-10-28] el código actual no usa transacción: el hook es `saved` en el evento de `User` (`app/Models/User.php`, `booted()`) y se difiere hasta que exista un endpoint de creación de usuarios (P3 o posterior).
**Estado:** CONFIRMADO (el hook de `User` usa `static::saved`, sin `DB::transaction`; verificado en código).

### 3.3 `04-migraciones-backfill-indices.md` — §3 B1 (backfill)

**ANTERIOR:**
- `status` = `true` (no se infiere del estado del usuario; son dimensiones distintas).
- `joined_at` = `users.created_at` si no es nulo; si lo es, `now()`.
...
4. Inserción idempotente: `insertOrIgnore` sobre la clave única `(user_id, organization_id)` o `firstOrCreate`.
**NUEVO:**
- `status` = `'active'` (string; no se infiere del estado del usuario; son dimensiones distintas).
- `id` = UUIDv7 generado por la aplicación.
- `created_at` = `now()` y `updated_at` = `now()`.
- No se toca `joined_at`: la columna no existe (D-23).
...
4. Inserción idempotente: comprueba existencia por `(user_id, organization_id)` con `DB::table` y solo inserta si no existe; nunca modifica membresías existentes. Usa `DB::table` en chunks (ver `MembershipBackfill::run()`).
**Estado:** CONFIRMADO (verificado en `app/Support/Tenancy/MembershipBackfill.php`: `DB::table`, chunks de 500, `status => 'active'`, `created_at`/`updated_at` = `now()`, comprobación `exists()` antes de insertar, sin modificar existentes).

### 3.4 `03-ciclo-de-vida-usuarios-membresias.md` — §4.1 (tabla de membresías)

**ANTERIOR:**
| Suspender / revocar | Sí (operador) | `status = false`, `revoked_at = now()` | 403 en esa organización; otras membresías siguen vigentes |
| Reactivar | Sí (operador) | `status = true`, `revoked_at = null` | Recupera acceso; se conserva `joined_at` original |
**NUEVO:**
| Suspender / revocar | Sí (operador) | `status = 'revoked'`, `revoked_at = now()` | 403 en esa organización; otras membresías siguen vigentes |
| Reactivar | Sí (operador) | `status = 'active'`, `revoked_at = null` | Recupera acceso. Operación explícita que se implementa en P3; el hook actual de P1 **nunca** reactiva una membresía revocada. |
**Estado:** CONFIRMADO (coincide con `OrganizationMembership::STATUS_ACTIVE='active'`, `STATUS_REVOKED='revoked'`; el hook de `User` nunca modifica `status` de una membresía existente).

### 3.5 `03-ciclo-de-vida-usuarios-membresias.md` — fórmula de acceso (§6)

**ANTERIOR:**
  AND membership.status = true
**NUEVO:**
  AND membership.status = 'active'
**Estado:** CONFIRMADO (miembro activo = `status = 'active'`).

### 3.6 `01-plan-m1.5-revisado.md` — D-02 (§4)

**ANTERIOR:**
**Decisión:** `organization_memberships` con `status`, `joined_at`, `revoked_at`; **sin** `deleted_at`.
**NUEVO:**
**Decisión:** `organization_memberships` con `status` (string, valores `'active'` y `'revoked'`), `revoked_at` y `created_at`/`updated_at` NOT NULL; **sin** `deleted_at`, **sin** `joined_at` (D-23: `created_at` es la fecha de alta de la membresía).
**Estado:** CONFIRMADO.

### 3.7 `01-plan-m1.5-revisado.md` — esquema `organization_memberships` (§5.2)

**ANTERIOR:**
| `status` | `boolean` | `NOT NULL`, default `true` |
| `joined_at` | `timestamp` | `NULL` |
| `revoked_at` | `timestamp` | `NULL` |
| `created_at`, `updated_at` | `timestamp` | `NULL` |
**NUEVO:**
| `status` | `string(20)` | `NOT NULL`, default `'active'` (valores `'active'`, `'revoked'`; D-22) |
| `revoked_at` | `timestamp` | `NULL` |
| `created_at`, `updated_at` | `timestamp` | `NOT NULL` (`timestamps()` de Laravel) |
**Estado:** CONFIRMADO.

### 3.8 `01-plan-m1.5-revisado.md` — `withPivot` (§5.3)

**ANTERIOR:**
  `withPivot('status', 'joined_at', 'revoked_at')`.
**NUEVO:**
  `withPivot('status', 'revoked_at')`.
**Estado:** CONFIRMADO (no existe `joined_at`).

### 3.9 `01-plan-m1.5-revisado.md` — H14 (§2.1)

**ANTERIOR:**
| H14 | `User` declara `organization_id` en `#[Fillable]`. | `app/Models/User.php:18` | Debe retirarse para que el cliente no pueda manipular la adscripción. |
**NUEVO:**
| H14 | `User` declara `organization_id` en `#[Fillable]`. | `app/Models/User.php:18` | Debe retirarse para que el cliente no pueda manipular la adscripción. [Nota 2026-10-28] sigue vigente en el código de P1; es trabajo de P3. |
**Estado:** CONFIRMADO (`organization_id` sigue en `#[Fillable]` de `User` y `BelongsToOrganization` sigue vigente).

### 3.10 `01-plan-m1.5-revisado.md` — §5.5 `users.organization_id`

**ANTERIOR:**
...La nulabilidad y la retirada definitiva se deciden en P5, cuando ningún código la use para autorizar.
**NUEVO:**
...La nulabilidad y la retirada definitiva se deciden en P5, cuando ningún código la use para autorizar. [Nota 2026-10-28] `organization_id` sigue en `#[Fillable]` y `User` sigue usando `BelongsToOrganization` en el código de P1; ambos se retiran en P3.
**Estado:** CONFIRMADO.

### 3.11 `02-modelo-operador-consultora.md` — §4.1 (INSPECTA-OP)

**ANTERIOR:**
5. No aparece en `/auth/organizations` ni en ningún selector de empresa.
**NUEVO:**
5. No aparece en `/auth/organizations` ni en ningún selector de empresa.
[Nota 2026-10-28] La organización operadora (`INSPECTA-OP`) **no está creada** en P1: solo existe la columna `organizations.type` y las constantes. Se crea en P4 con los seeders de varias organizaciones.
**Estado:** CONFIRMADO (verificado en ESTADO.md §4: "Sin organización operadora (`INSPECTA-OP`) creada"; no hay seeder que la cree en P1).

### 3.12 `06-fases-y-pruebas.md` — T47 (§4.6)

**ANTERIOR:**
| T47 | Crear usuario crea membresía (doble escritura) | P1 | Membresía creada en la misma transacción |
**NUEVO:**
| T47 | Crear usuario crea membresía (doble escritura) | P1 | Membresía creada al crear el usuario. [Nota 2026-10-28] el código actual no usa transacción (hook en el evento `saved`); se difiere hasta que exista un endpoint de creación de usuarios (P3 o posterior). |
**Estado:** CONFIRMADO (el hook de `User` no usa transacción).

### 3.13 `05-contexto-tenant-y-fk-usuarios.md` — `assertActiveMembership()` (§4.2)

**ANTERIOR:**
        ->where(status, true)
**NUEVO:**
        ->where(status, 'active')
**Estado:** CONFIRMADO (miembro activo = `status = 'active'`).

### 3.14 `avance/ESTADO.md` — §5 (APROBADAS)

**ANTERIOR:** (no existían D-22 ni D-23 en APROBADAS)
**NUEVO:** añadidas D-22 (2026-10-28) y D-23 (2026-10-28) al final de APROBADAS.
**Estado:** CONFIRMADO.

### 3.15 `avance/ESTADO.md` — decisión pendente eliminada

**ANTERIOR:**
**Divergencias con docs/m1.5 (decisión pendiente):** status de la membresía como string (`'active'`/`'revoked'`) en vez de boolean; añadir o no `joined_at`. Recomendación vigente: conservar string y no añadir `joined_at`.
**NUEVO:**
**Divergencias con docs/m1.5:** resueltas por D-22 y D-23 (ver §5 APROBADAS); las restantes se marcan como DIFERIDAS en §6.
**Estado:** CONFIRMADO.

### 3.16 `avance/ESTADO.md` — §6 (tabla de divergencias)

**ANTERIOR:** seis filas con estado `NO CONFIRMADA`.
**NUEVO:** `status`/`joined_at`/timestamps = RESUELTA (D-22/D-23); transacción = DIFERIDA (P3+); INSPECTA-OP = DIFERIDA (P4); Fillable/BelongsToOrganization = DIFERIDA (P3).
**Estado:** CONFIRMADO.

---

## 4. Contradicciones adicionales halladas y NO corregidas

Durante el paso 2 se revisó cada documento de `docs/`. Se confirmó que las únicas apariciones de `joined_at` y de `status` booleano de **membresía** son las corregidas en el paso 3. Las apariciones restantes de `status = false` / `status = true` se refieren a `users.status` (desactivación de usuario) o a `organizations.status` (suspensión de organización), que **no** son decisión de este prompt y no se modificaron.

No se hallaron otras contradicciones entre documentos y código que no estén listadas en este prompt o en las divergencias ya reconocidas de `ESTADO.md`.

---

## 5. NO VERIFICADO y por qué

- No se ejecutó la suite de pruebas (prohibido por instrucción: no comandos artisan, no PHPUnit). Los conteos de pruebas (90/274) se toman de `ESTADO.md` y de la bitácora de sesión P1, no se reverificaron.
- La verificación contra MariaDB no se repitió (la verificación previa fue en sesión anterior, contenedor destruido).
- El contenido de los documentos que no se modificaron (`07-contradicciones-y-decisiones-pendientes.md`, `README.md`) no se revisó en busca de `joined_at`/`status` más allá del grep del paso 2, que no arrojó coincidencias en esos archivos para esos términos.

---

## 6. Conformidad con las restricciones

- Solo se editaron archivos dentro de `docs/M1.5` y `docs/M1.5/avance/`.
- Se creó un único archivo nuevo: `docs/M1.5/avance/sesiones/2026-10-28-documentacion.md`.
- Se creó copia de respaldo de `docs/` en `/tmp/docs-backup-20261028` (fuera del repositorio).
- No se modificó código, migraciones, pruebas, `config/`, `openapi.yaml`, `.env` ni ningún archivo fuera de `docs/`.
- No se hizo commit, push, stash, reset, checkout, amend ni cambio de rama.
- No se ejecutaron migraciones, seeders, Docker ni comandos artisan; no se conectó a ninguna base de datos; no se leyó ni imprimió `.env`.
