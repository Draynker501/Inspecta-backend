# 04 — Migraciones, backfill e índices

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1)

**Objetivo:** separar con precisión tres operaciones distintas, definir comprobaciones read-only
previas, condiciones de parada, rollback y el orden de despliegue.
**Estado:** propuesta. Nada ejecutado.

---

## 1. Separación estricta de operaciones

| Bucket | Qué contiene | Cambia datos existentes | Ventana | Reversible |
|---|---|---|---|---|
| **A. Aditivas** | Crear `organization_memberships`; añadir `organizations.type` | No | Cualquiera | `DROP` de lo creado |
| **B. Backfill** | Poblar membresías desde `users.organization_id` | Solo la tabla nueva | Tras A y tras desplegar doble escritura | `TRUNCATE` / `DROP` |
| **C. Índices** | Unicidad de `roles` y `plants` | No | Ventana controlada | Recrear índice anterior |

**Nunca se mezclan en una misma migración ni en un mismo despliegue.**

Además, M1.5 **no** incluye:

- Hacer `users.organization_id` nullable.
- Eliminar `users.organization_id`.
- Cambiar la unicidad de `norms (code, version)`.
- Añadir `organization_id` a `devices` ni a `sync_operations`.
- Cambiar FKs existentes de pivotes.

---

## 2. Bucket A — Migraciones aditivas

### A1. `create_organization_memberships_table`

```text
organization_memberships
  id                char(36)   PK
  user_id           char(36)   NOT NULL  FK users.id           ON DELETE RESTRICT ON UPDATE CASCADE
  organization_id   char(36)   NOT NULL  FK organizations.id   ON DELETE RESTRICT ON UPDATE CASCADE
  status            string(20) NOT NULL  DEFAULT 'active'   -- valores: 'active', 'revoked' (D-22)
  revoked_at        timestamp  NULL
  created_at        timestamp  NOT NULL   -- timestamps() de Laravel
  updated_at        timestamp  NOT NULL   -- timestamps() de Laravel

  UNIQUE (user_id, organization_id)          -- organization_memberships_user_org_unique
  INDEX  (organization_id, status)           -- organization_memberships_org_status_idx
  INDEX  (user_id, status)                   -- organization_memberships_user_status_idx
```

Notas:

- `id` es `char(36)` y lo genera la aplicación (UUIDv7), coherente con el resto del esquema.
- Sin `deleted_at` (decisión D-02).
- Sin `created_by` / `revoked_by` en esta fase.
- `created_at` y `updated_at` son `NOT NULL` (`timestamps()` de Laravel).
- D-23: no se añade `joined_at`; `created_at` es la fecha de alta de la membresía.

### A2. `add_type_to_organizations_table`

```text
organizations.type  varchar(20) NOT NULL DEFAULT 'client'
INDEX (type, status)   -- organizations_type_status_idx
```

Notas:

- `NOT NULL` con default es seguro incluso con filas existentes: todas pasan a `client`.
- El backfill del operador (si no existe) es una operación **de datos** y pertenece al bucket B, no
  a la migración. La migración solo añade la columna.
- Verificar en MariaDB que añadir la columna no reconstruya la tabla; si lo hiciera, planificar
  ventana. En SQLite la tabla se reconstruye; es aceptable en pruebas.

### A3. Código de doble escritura (no es migración, pero es requisito de A)

Al crear un usuario, crear su membresía en `users.organization_id`.
[Nota 2026-10-28] el código actual no usa transacción: el hook es `saved` en el evento de `User`
(`app/Models/User.php`, `booted()`) y se difiere hasta que exista un endpoint de creación de usuarios
(P3 o posterior). Sin esto, el backfill queda incompleto por definición (ver §6).

---

## 3. Bucket B — Backfill

### B1. Comando propuesto

`php artisan tenancy:backfill-memberships {--dry-run} {--chunk=500} {--report=}`

Comportamiento:

1. Ejecuta con `TenantContext::withoutTenant('backfill-memberships')`.
2. Lee usuarios con `organization_id` no nulo en lotes de 500, ordenados por `id`.
3. Para cada usuario:
   - `organization_id` = `users.organization_id`.
   - `status` = `'active'` (string; no se infiere del estado del usuario; son dimensiones distintas).
   - `id` = UUIDv7 generado por la aplicación.
   - `created_at` = `now()` y `updated_at` = `now()`.
   - No se toca `joined_at`: la columna no existe (D-23).
4. Inserción idempotente: comprueba existencia por `(user_id, organization_id)` con `DB::table`
   y solo inserta si no existe; nunca modifica membresías existentes. Usa `DB::table` en chunks
   (ver `MembershipBackfill::run()`).
5. Contadores: leídos, creados, ya existentes, omitidos, errores.
6. `--dry-run` calcula y reporta sin escribir.
7. `--report=` escribe CSV con anomalías y resultado.
8. **No** modifica `users.organization_id`.
9. **No** crea membresías para usuarios sin organización: los reporta.

### B2. Regla del operador

Si existe la organización operadora, los usuarios con `users.organization_id = <operador>` reciben
su membresía en el operador. Esa membresía **no autoriza dominio** (ver `02` §4.4). El comando debe
reportar cuántas membresías de operador creó, para que sea visible y no un efecto colateral.

### B3. Idempotencia

Ejecutar el comando dos veces debe producir:

- `creados` = 0 en la segunda ejecución.
- `ya existentes` = total de la primera.
- Sin cambios en `updated_at` de las filas existentes.
- Mismo conteo final.

Esta es la prueba de aceptación T24.

---

## 4. Bucket C — Índices

### C1. Roles

```text
DROP INDEX roles_name_unique
CREATE UNIQUE INDEX roles_organization_name_unique (organization_id, name)
```

### C2. Plantas

```text
DROP INDEX plants_code_unique
CREATE UNIQUE INDEX plants_organization_code_unique (organization_id, code)
```

### C3. Por qué ahora es seguro

- Hoy `roles.name` es único **global**: no puede haber dos roles con el mismo nombre en ninguna
  organización, así que el nuevo índice compuesto no puede fallar.
- Hoy `plants.code` es único **global**: idem.
- El riesgo aparece si se siembran datos multiempresa **antes** del cambio. Por eso el cambio va en
  P2, antes de P4.

### C4. Qué **no** se toca

| Índice | Decisión |
|---|---|
| `users_email_unique` | Se mantiene global (identidad única) |
| `organizations_code_unique` | Se mantiene global |
| `areas_plant_code_unique` | Se mantiene (ya acotado por planta) |
| `production_lines_area_code_unique` | Se mantiene (ya acotado por área) |
| `norms_code_version_unique` | Se reevalúa en M2 |
| Pivotes únicos | Se mantienen |

### C5. MariaDB

- `DROP INDEX` + `CREATE UNIQUE INDEX` puede bloquear la tabla. Confirmar versión y soporte de DDL
  online (`ALGORITHM=INPLACE, LOCK=NONE`) antes de la ventana.
- En SQLite, Laravel reconstruye la tabla al dropear índices; validar `up` y `down` en la suite.
- `down()` debe recrear el índice global; si existieran duplicados por nombre en distintas
  organizaciones, el `down` fallará. Documentarlo: el rollback de C solo es posible mientras no
  haya datos multiempresa sembrados.

---

## 5. Prechecks read-only

Ejecutar **antes** de cualquier escritura, en el entorno destino, y guardar el reporte. Todas son
consultas de lectura.

### 5.1 Integridad de usuarios

```sql
-- P1: usuarios sin organización
SELECT id, email FROM users WHERE organization_id IS NULL OR organization_id = '';

-- P2: usuarios con organización inexistente
SELECT u.id, u.email, u.organization_id
FROM users u
LEFT JOIN organizations o ON o.id = u.organization_id
WHERE u.organization_id IS NOT NULL AND o.id IS NULL;

-- P3: usuarios desactivados (informativo)
SELECT COUNT(*) FROM users WHERE status = 0;
```

### 5.2 Integridad de organizaciones

```sql
-- P4: organizaciones con estado inconsistente
SELECT id, code, status, deleted_at FROM organizations
WHERE deleted_at IS NOT NULL;

-- P5: candidatos a operador
SELECT id, code, name FROM organizations
WHERE code = 'INSPECTA-OP' OR type = 'operator';   -- type aún no existe: ejecutar solo el code

-- P6: organizaciones sin usuarios (informativo)
SELECT o.id, o.code FROM organizations o
LEFT JOIN users u ON u.organization_id = o.id
WHERE u.id IS NULL;
```

### 5.3 Integridad de pivotes

```sql
-- P7: user_roles con organización incoherente
SELECT ur.id FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE ur.organization_id <> u.organization_id
   OR ur.organization_id <> r.organization_id;

-- P8: user_plants incoherentes
SELECT up.id FROM user_plants up
JOIN users u ON u.id = up.user_id
JOIN plants p ON p.id = up.plant_id
WHERE up.organization_id <> u.organization_id
   OR up.organization_id <> p.organization_id;

-- P9: user_areas incoherentes
SELECT ua.id FROM user_areas ua
JOIN users u ON u.id = ua.user_id
JOIN areas a ON a.id = ua.area_id
WHERE ua.organization_id <> u.organization_id
   OR ua.organization_id <> a.organization_id;

-- P10: pivotes huérfanos (usuario o destino inexistente)
SELECT 'user_roles' AS tabla, ur.id FROM user_roles ur
LEFT JOIN users u ON u.id = ur.user_id
WHERE u.id IS NULL
UNION ALL
SELECT 'user_plants', up.id FROM user_plants up
LEFT JOIN users u ON u.id = up.user_id
WHERE u.id IS NULL
UNION ALL
SELECT 'user_areas', ua.id FROM user_areas ua
LEFT JOIN users u ON u.id = ua.user_id
WHERE u.id IS NULL;
```

### 5.4 Duplicados que romperían los índices

```sql
-- P11: roles duplicados por organización (debe ser 0 por el índice global actual)
SELECT organization_id, name, COUNT(*) c FROM roles
GROUP BY organization_id, name HAVING c > 1;

-- P12: plantas duplicadas por organización (debe ser 0)
SELECT organization_id, code, COUNT(*) c FROM plants
GROUP BY organization_id, code HAVING c > 1;
```

### 5.5 Integridad de dominio

```sql
-- P13: registros de dominio sin organización
SELECT 'plants' t, COUNT(*) FROM plants WHERE organization_id IS NULL
UNION ALL SELECT 'inspections', COUNT(*) FROM inspections WHERE organization_id IS NULL
UNION ALL SELECT 'incidents', COUNT(*) FROM incidents WHERE organization_id IS NULL
UNION ALL SELECT 'evidences', COUNT(*) FROM evidences WHERE organization_id IS NULL
UNION ALL SELECT 'corrective_actions', COUNT(*) FROM corrective_actions WHERE organization_id IS NULL
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs WHERE organization_id IS NULL;

-- P14: FKs a usuarios inexistentes
SELECT i.id FROM inspections i LEFT JOIN users u ON u.id = i.user_id WHERE u.id IS NULL;
SELECT i.id FROM inspections i LEFT JOIN users u ON u.id = i.supervisor_id WHERE i.supervisor_id IS NOT NULL AND u.id IS NULL;
SELECT a.id FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.user_id IS NOT NULL AND u.id IS NULL;
```

### 5.6 Conteos de referencia

```sql
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM organizations;
SELECT COUNT(*) FROM user_roles;
SELECT organization_id, COUNT(*) FROM users GROUP BY organization_id;
```

Guardar estos números: son la base de la validación posterior (§7).

---

## 6. Condiciones de parada

### 6.1 Parada dura (no continuar)

| # | Condición | Acción |
|---|---|---|
| S1 | Usuarios con `organization_id` nulo o vacío > 0 | Reportar y decidir caso por caso. **No inventar membresías.** |
| S2 | Usuarios con organización inexistente > 0 | Corregir datos antes de migrar |
| S3 | Cualquier registro de dominio con `organization_id` nulo > 0 | Detener |
| S4 | Pivotes con organización incoherente > 0 | Detener; requiere decisión funcional |
| S5 | Pivotes huérfanos > 0 | Detener |
| S6 | Roles duplicados por organización > 0 | Detener antes del índice C1 |
| S7 | Plantas duplicadas por organización > 0 | Detener antes del índice C2 |
| S8 | FK a usuarios inexistentes en tablas de dominio > 0 | Detener |
| S9 | Tras el backfill, usuarios con organización y sin membresía > 0 | Detener; reejecutar o investigar |
| S10 | Membresías duplicadas por `(user_id, organization_id)` > 0 | Detener |
| S11 | Organizaciones con `type` inválido tras A2 | Detener |
| S12 | Conteo de organizaciones operadoras ≠ 1 (cuando se decida que debe existir) | Detener |
| S13 | Versión de MariaDB o soporte de DDL online no confirmados para C | Posponer C |
| S14 | No existe backup verificable | No iniciar |
| S15 | El código con doble escritura no está desplegado y no hay ventana de solo lectura | No ejecutar B |

### 6.2 Advertencias (registrar y continuar)

| # | Condición | Motivo |
|---|---|---|
| W1 | Usuarios con `status = false` | Reciben membresía; el acceso lo corta `users.status` |
| W2 | Usuarios sin roles | Válido; la membresía existe sin rol |
| W3 | Organizaciones sin usuarios | Válido |
| W4 | `audit_logs.user_id` nulo | Acciones de sistema |
| W5 | Usuarios con `organization_id` = operador | Esperado para consultores; reportar conteo |

---

## 7. Validación posterior al backfill

```sql
-- V1: usuarios con organización sin membresía (debe ser 0)
SELECT COUNT(*) FROM users u
LEFT JOIN organization_memberships m
  ON m.user_id = u.id AND m.organization_id = u.organization_id
WHERE u.organization_id IS NOT NULL AND m.id IS NULL;

-- V2: membresías duplicadas (debe ser 0)
SELECT user_id, organization_id, COUNT(*) c FROM organization_memberships
GROUP BY user_id, organization_id HAVING c > 1;

-- V3: membresías con organización inexistente (debe ser 0)
SELECT m.id FROM organization_memberships m
LEFT JOIN organizations o ON o.id = m.organization_id
WHERE o.id IS NULL;

-- V4: membresías con usuario inexistente (debe ser 0)
SELECT m.id FROM organization_memberships m
LEFT JOIN users u ON u.id = m.user_id
WHERE u.id IS NULL;

-- V5: pivotes sin membresía (informativo; decisión D-15)
SELECT ur.id FROM user_roles ur
LEFT JOIN organization_memberships m
  ON m.user_id = ur.user_id AND m.organization_id = ur.organization_id
WHERE m.id IS NULL;

-- V6: conteo total
SELECT COUNT(*) FROM organization_memberships;
```

Criterio de aceptación: V1–V4 en 0 y el conteo de V6 igual a “usuarios con organización no nula”.

---

## 8. Carrera de despliegue y patrón expand/contract

### 8.1 El problema

Si se ejecuta el backfill mientras corre el código antiguo:

1. Backfill termina.
2. Un administrador crea un usuario nuevo.
3. El código antiguo **no** crea membresía.
4. Ese usuario queda sin membresía y, tras la conmutación, no puede acceder.

### 8.2 Secuencia correcta

```text
1. Backup + conteos + prechecks (read-only)
2. Migración A1 + A2                        (aditiva, sin efecto funcional)
3. Deploy del código con doble escritura    (crear usuario ⇒ crear membresía)
                                            (autorización sigue usando users.organization_id)
4. Ejecutar backfill B (dry-run, luego real)
5. Validación V1–V4
6. Deploy del código de P3                  (autorización por membresía + cabecera)
7. Retirar el fallback de autorización      (mismo deploy o el siguiente, con fecha límite)
8. Índices C en ventana controlada          (P2 puede adelantarse: ver §9)
9. Seeders multiempresa + OpenAPI           (P4)
```

### 8.3 Alternativa sin doble escritura

Ventana de solo lectura de la aplicación durante el backfill. Es válida si el negocio acepta la
parada. Se documenta como plan B.

---

## 9. Sobre el orden P2 (índices) frente a §8

El cambio de índices es independiente del backfill y **no requiere** que existan membresías. Como
hoy los índices globales garantizan cero duplicados, C puede ejecutarse:

- **Opción preferida:** en P2, justo después de A1/A2 y antes de cualquier dato multiempresa.
- **Opción alternativa:** dentro de la misma ventana que A, si se prefiere una sola intervención.

Lo que **no** es válido es ejecutarlo después de P4 (seeders multiempresa): el `CREATE UNIQUE`
podría fallar o el seeder no podría crear roles homónimos.

---

## 10. Rollback

| Falla en | Acción | Efecto |
|---|---|---|
| A1 | `DROP TABLE organization_memberships` | Sin cambios en datos existentes |
| A2 | `DROP COLUMN type` | Sin cambios en datos existentes |
| B (backfill) | `TRUNCATE organization_memberships` y corregir | Tabla nueva; sin pérdida de datos originales |
| B (validación V1–V4) | Reejecutar backfill (idempotente) o investigar | — |
| P3 (código) | Revertir deploy | `users.organization_id` sigue disponible; membresías quedan sin uso |
| C1/C2 (índices) | Recrear índice global **solo si no hay duplicados** | Si hay datos multiempresa, el rollback no es posible sin limpiar duplicados |
| Corrupción de datos | Restaurar backup | No reparar manualmente sin auditoría |

**Regla:** el rollback de C solo es seguro antes de P4. A partir de P4, la vuelta atrás exige un
plan de datos específico.

---

## 11. Decisiones solicitadas

- **D-12:** aprobar la separación A/B/C y el orden P1 → P2 → P3 → P4.
- **D-13:** confirmar ventana de mantenimiento, versión de MariaDB y DDL online para C.
- **D-14:** confirmar que el preflight read-only puede ejecutarse en el entorno real y que hay
  backup verificable.
- **D-10:** elegir entre doble escritura (recomendado) o ventana de solo lectura para el backfill.
