# Bitácora de sesión — Alineación de documentos con lista oficial de decisiones (2026-10-28)

**Estado:** inmutable. No se modifica tras crear.

---

## 1. Resumen de la sesión (máx. 10 líneas)

Se alinearon los documentos de planificación de `docs/M1.5` con la lista oficial de decisiones del 2026-10-28. Se actualizaron estados de D-07, D-08, D-11 (pendientes), D-13 (aprobada para desarrollo), D-15 (opción A con FK compuestas), y se añadieron D-16, D-19, D-20, D-21, D-22, D-23. Se añadió nota de Sanctum a D-06 y recomendación vigente a D-16. Se añadió nota a E15 en 03 sobre reactivación explícita en P3. Se añadió encabezado de última actualización a ESTADO.md. No se implementó código.

---

## 2. Archivos modificados

| # | Archivo | Cambios |
|---|---------|---------|
| 1 | `07-contradicciones-y-decisiones-pendientes.md` | §2: D-15 opción A (FK compuestas); D-13 aprobada para desarrollo; D-07, D-08, D-11 marcadas PROPUESTA pendiente; D-06 nota Sanctum; D-16 nota recomendación vigente; añadidas filas D-19, D-20, D-21, D-22, D-23 |
| 2 | `01-plan-m1.5-revisado.md` | §4: D-07, D-08, D-11 con nota de estado PROPUESTA; D-06 nota Sanctum; añadidas secciones D-16, D-19, D-20, D-21, D-22, D-23 |
| 3 | `03-ciclo-de-vida-usuarios-membresias.md` | Fila E15: nota de reactivación explícita en P3 |
| 4 | `avance/ESTADO.md` | Línea 3: encabezado "Última actualización: 2026-10-28..." |

---

## 3. Cambios aplicados (texto ANTERIOR → texto NUEVO)

### 3.1 `07-contradicciones-y-decisiones-pendientes.md` — §2, fila D-15

**ANTERIOR:**
```
| **D-15** | ¿Se exige membresía a `user_roles`/`user_plants`/`user_areas`? | **Sí**, validado en aplicación; sin FK compuesta |
```
**NUEVO:**
```
| **D-15** | ¿Se exige membresía a `user_roles`/`user_plants`/`user_areas`? | **Sí**, opción A: FK compuestas `(user_id, organization_id)` hacia `organization_memberships(user_id, organization_id)` y `(role_id|plant_id|area_id, organization_id)` hacia `roles`, `plants` y `areas`, en `user_roles`, `user_plants` y `user_areas`. La unicidad usa índices únicos `(id, organization_id)` en `roles`, `plants` y `areas`. |
```
**Estado:** CONFIRMADO (línea 271 antes de editar).

### 3.2 `07-contradicciones-y-decisiones-pendientes.md` — §2, fila D-13

**ANTERIOR:**
```
| **D-13** | ¿Ventana de mantenimiento, versión de MariaDB y DDL online confirmados? | Confirmar antes de ejecutar |
```
**NUEVO:**
```
| **D-13** | ¿Ventana de mantenimiento, versión de MariaDB y DDL online confirmados? | APROBADA para desarrollo: MariaDB 11.8 LTS (11.8.9 verificada). Ventana de mantenimiento: pendiente para producción |
```
**Estado:** CONFIRMADO (línea 270 antes de editar).

### 3.3 `07-contradicciones-y-decisiones-pendientes.md` — §2, filas D-07, D-08, D-11

**ANTERIOR:**
```
| **D-07** | ¿FK a usuario por membresía y solo *dirty* en update? | **Sí** |
| **D-08** | ¿Fail-closed en fronteras (P3) y scope estricto en P5? | **Sí** |
| **D-11** | ¿Auditoría append-only con `AuditLogger` como excepción? | **Sí** |
```
**NUEVO:**
```
| **D-07** | ¿FK a usuario por membresía y solo *dirty* en update? | PROPUESTA, pendiente de aprobación (P3) |
| **D-08** | ¿Fail-closed en fronteras (P3) y scope estricto en P5? | PROPUESTA, pendiente de aprobación (P3/P5) |
| **D-11** | ¿Auditoría append-only con `AuditLogger` como excepción? | PROPUESTA, pendiente de aprobación (P3) |
```
**Estado:** CONFIRMADO (líneas 277-279 antes de editar).

### 3.4 `07-contradicciones-y-decisiones-pendientes.md` — §2, fila D-06 (nota Sanctum)

**ANTERIOR:**
```
| **D-06** | ¿Sin revocación masiva de tokens en M1.5? | **Sí** |
```
**NUEVO:**
```
| **D-06** | ¿Sin revocación masiva de tokens en M1.5? | **Sí**. [Nota 2026-10-28] el token de Sanctum no lleva organización (verificado en P1). |
```
**Estado:** CONFIRMADO (línea 280 antes de editar).

### 3.5 `07-contradicciones-y-decisiones-pendientes.md` — §2, fila D-16 (nota recomendación vigente)

**ANTERIOR:**
```
| **D-16** | ¿OpenAPI 1.4.0 aditiva o 2.0.0 con ruptura? | 1.4.0 si no hay consumidores; 2.0.0 si los hay |
```
**NUEVO:**
```
| **D-16** | ¿OpenAPI 1.4.0 aditiva o 2.0.0 con ruptura? | 1.4.0 si no hay consumidores; 2.0.0 si los hay. [Nota 2026-10-28] recomendación vigente: 2.0.0 con ruptura, porque panel y PWA aún no consumen `AuthUser`. |
```
**Estado:** CONFIRMADO (línea 286 antes de editar).

### 3.6 `07-contradicciones-y-decisiones-pendientes.md` — §2, añadidas filas D-19, D-20, D-21, D-22, D-23

**ANTERIOR:** (no existían filas para estos IDs en la tabla de "Puede posponerse")
**NUEVO:** filas añadidas con la redacción de la lista oficial y sus respectivos estados.
**Estado:** CONFIRMADO.

### 3.7 `01-plan-m1.5-revisado.md` — §4, sección D-07

**ANTERIOR:** (sin nota de estado en la sección D-07)
**NUEVO:** añadida `[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P3).` al inicio de la sección.
**Estado:** CONFIRMADO.

### 3.8 `01-plan-m1.5-revisado.md` — §4, sección D-08

**ANTERIOR:** (sin nota de estado en la sección D-08)
**NUEVO:** añadida `[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P3/P5).` al inicio de la sección.
**Estado:** CONFIRMADO.

### 3.9 `01-plan-m1.5-revisado.md` — §4, sección D-11

**ANTERIOR:** (sin nota de estado en la sección D-11)
**NUEVO:** añadida `[Nota 2026-10-28] Estado: PROPUESTA, pendiente de aprobación (P3).` al inicio de la sección.
**Estado:** CONFIRMADO.

### 3.10 `01-plan-m1.5-revisado.md` — §4, sección D-06 (nota Sanctum)

**ANTERIOR:**
```
al revocar la última membresía queda como endurecimiento opcional posterior.
```
**NUEVO:**
```
al revocar la última membresía queda como endurecimiento opcional posterior. [Nota 2026-10-28] el
token de Sanctum no lleva organización (verificado en P1).
```
**Estado:** CONFIRMADO.

### 3.11 `01-plan-m1.5-revisado.md` — §4, añadidas secciones D-16, D-19, D-20, D-21, D-22, D-23

**ANTERIOR:** (no existían secciones para estos IDs en §4)
**NUEVO:** secciones añadidas con la redacción de la lista oficial.
**Estado:** CONFIRMADO.

### 3.12 `03-ciclo-de-vida-usuarios-membresias.md` — fila E15

**ANTERIOR:**
```
| E15 | Re-crear membresía existente | Se reactiva la misma fila; sin duplicado | 200/201 |
```
**NUEVO:**
```
| E15 | Re-crear membresía existente | Se reactiva la misma fila; sin duplicado | 200/201 | [Nota 2026-10-28] reactivar re-creando es una operación explícita que se implementa en P3; el hook de P1 no la realiza |
```
**Estado:** CONFIRMADO (línea 242 antes de editar).

### 3.13 `avance/ESTADO.md` — línea 3 (encabezado)

**ANTERIOR:**
```
# Estado del avance — P1 de M1.5

**Fecha de la sesión:** 2026-10-28
```
**NUEVO:**
```
# Estado del avance — P1 de M1.5

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1 y con la lista oficial de decisiones)

**Fecha de la sesión:** 2026-10-28
```
**Estado:** CONFIRMADO (líneas 1-3 antes de editar).

---

## 4. Contradicciones adicionales (punto h)

No se hallaron pasajes en los documentos 01 a 06 que afirmen que una decisión listada como PENDIENTE ya esté resuelta, ni que una APROBADA esté pendiente, más allá de las correcciones ya aplicadas.

---

## 5. NO VERIFICADO y por qué

- No se ejecutaron pruebas ni comandos (solo lectura).
- No se verificó contra MariaDB ni se accedió a ninguna base de datos.
- No se revisó `openapi.yaml`, `config/`, ni `.env` (fuera de `docs/`).
- Los problemas de formato buscados (`created_ates`, `status = trueydeleted_at`, `` `ESTADO.md:**` ``) no arrojaron coincidencias.

---

## 6. Conformidad con las restricciones

- Solo se editaron archivos dentro de `docs/M1.5` y `docs/M1.5/avance/`.
- Se creó un único archivo nuevo: `docs/M1.5/avance/sesiones/2026-10-28-documentacion-2.md`.
- Se creó copia de respaldo de `docs/` en `/tmp/docs-backup-20261028b` (fuera del repositorio).
- No se modificó código, migraciones, pruebas, `config/`, `openapi.yaml`, `.env` ni ningún archivo fuera de `docs/`.
- No se hizo commit, push, stash, reset, checkout, amend ni cambio de rama.
- No se ejecutaron migraciones, seeders, Docker, artisan ni PHPUnit; no se conectó a ninguna base de datos; no se leyó ni imprimió `.env`.
