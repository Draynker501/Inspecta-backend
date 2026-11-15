# CAT-03 — S0: diseño de creación de líneas de producción

**Estado:** decisiones CAT-03-DEC-01–08 y CAT-03-INT-01 aprobadas por el responsable del proyecto el 14 de noviembre de 2026. Documento creado durante S1. No se atribuye aprobación formal a todo el equipo. Base: `origin/master` `46ca8a937ef73e778edabbad3acdbd7565b8ccb2`. Reutiliza los patrones y aprendizajes de CAT-01 y CAT-02 sin repetir sus auditorías generales.

## 1. Alcance

CAT-03 es el corte de alta de líneas de producción bajo un área. No amplía CAT-01 (`POST /api/plants`) ni CAT-02 (`POST /api/plants/{plantId}/areas`). No autoriza CRUD general de líneas, inspecciones ni incidencias; tampoco autoriza modificar OpenAPI ni desplegar cambios.

## 2. Decisiones aprobadas

| ID | Decisión |
|---|---|
| CAT-03-DEC-01 | La creación de líneas es el único corte funcional de CAT-03. |
| CAT-03-DEC-02 | La ruta es `POST /api/areas/{areaId}/lines`. |
| CAT-03-DEC-03 | Exigir `plants.manage` y membresía activa. No exigir además `plants.view` ni asignación adicional al área o a la planta. |
| CAT-03-DEC-04 | El área padre y su planta deben pertenecer al tenant, existir, no estar eliminadas y estar activas. |
| CAT-03-DEC-05 | Normalizar `code` con `trim` y mayúsculas; aceptar solo ASCII `A–Z`, `0–9` y `-`, máximo 50 caracteres. El código es único por área, también frente a líneas soft-deleted. |
| CAT-03-DEC-06 | `description` es opcional y nullable, string sin límite adicional al esquema. No hay campo `type`. |
| CAT-03-DEC-07 | Aceptar exclusivamente JSON; generar UUIDv7 en servidor; `status=true`; usar DTO explícito de siete campos y rechazar cualquier campo adicional. |
| CAT-03-DEC-08 | Emitir `production_line.created` de forma transaccional, con `old_values=[]` y `new_values` limitado a `area_id`, `name`, `code` y `status`. |
| CAT-03-INT-01 | Sustituir la FK simple `production_lines.area_id -> areas.id` por la compuesta `prod_lines_area_org_fk` sobre `(area_id, organization_id) -> areas(id, organization_id)`, `ON DELETE RESTRICT`, `ON UPDATE RESTRICT`, con índice `prod_lines_area_org_idx`. |

La respuesta HTTP `201` se encapsula en `data` y contiene exactamente los siete campos `id`, `organization_id`, `area_id`, `name`, `code`, `description` y `status`. No incluye timestamps, `deleted_at` ni relaciones anidadas.

## 3. Precisiones HTTP aprobadas

- `415` para contenido no JSON, incluido `multipart`.
- `422 validation_failed` para JSON inválido, propiedades adicionales y parámetros query no permitidos.
- `422` con `errors.areaId` si el área propia está inactiva.
- `422` con `errors.plantId` si la planta propia está inactiva.
- `404` genérico para área o planta ajena, inexistente o eliminada.
- `201` para alta válida.

## 4. Integridad jerárquica

La operación asegura que la línea, su área y su planta pertenezcan a la misma organización. La regla de aplicación complementa las restricciones de base de datos. CAT-03-INT-01 define la FK compuesta análoga a CAT-02-INT-01/02.

## 5. Componentes reutilizables de CAT-02

Controlador dedicado, FormRequest estricto, pruebas HTTP, clasificador SQL, concurrencia con procesos independientes, contrato OpenAPI y migración compuesta para SQLite y MariaDB.

## 6. Plan

- **S0:** diseño y aprobación (este documento).
- **S1:** implementación y pruebas SQLite, con preparación MariaDB.
- **S2:** auditoría independiente, validación MariaDB y conciliación OpenAPI.
- **S3:** integración técnica.
## 7. S2 — auditoría y validación integral (resultados)

**Veredicto:** CAT-03 validada en SQLite y MariaDB, incluidos nueve escenarios concurrentes. Sin hallazgos P0/P1. Hallazgos P2 (concurrencia no acredita ausencia general de deadlocks; logs de Actions no enumeran escenarios por nombre) y P3 (OpenAPI no validado con un validador formal externo) documentados.

Evidencia:
- SQLite `:memory:`: **532 pruebas, 511 aprobadas, 21 omitidas, 2182 aserciones** (referencia S1: 532/511/21/2182).
- MariaDB 11.8.9 efímera: **541 pruebas, 523 aprobadas, 18 omitidas, 2424 aserciones**, sin fallos.
- Pruebas focalizadas SQLite: **21 aprobadas, 210 aserciones**.
- Clasificador SQL simulado: **1 aprobada, 28 aserciones**.
- Concurrencia: **9 escenarios aprobados, 158 aserciones** (CONC-01 a CONC-09).
- Contrato OpenAPI: **1 aprobada**.
- Migración up/down/up verificada en SQLite.
- PHP lint, Pint y `git diff --check`: aprobados.

Escenarios concurrentes:
- CONC-01 dos altas mismo código: 201 + 422 errors.code; una línea y un evento.
- CONC-02 área desactivada primero: 422 errors.areaId; cero líneas/eventos.
- CONC-03 alta antes de desactivar área: 201; luego área inactiva.
- CONC-04 soft delete área primero: 404; cero líneas/eventos.
- CONC-05 alta antes de soft delete área: 201; luego área eliminada.
- CONC-06 planta desactivada primero: 422 errors.plantId; cero líneas/eventos.
- CONC-07 alta antes de desactivar planta: 201; luego planta inactiva.
- CONC-08 soft delete planta primero: 404; cero líneas/eventos.
- CONC-09 alta antes de soft delete planta: 201; luego planta eliminada.

Integridad: FK compuesta `prod_lines_area_org_fk` (RESTRICT/RESTRICT), índice `prod_lines_area_org_idx`, traducción exclusiva de `prod_lines_area_code_unique`, preservación de `inspections`/`incidents` (RESTRICT).

Contractual: OpenAPI permanece en `2.3.0`; POST conciliado, GET vecino sigue `planned`. Compatibilidad con panel/PWA no verificada.

CI: se reutilizó el workflow endurecido; MariaDB 11.8.9, PROCESS exclusivo en la instancia efímera, APP_KEY temporal. Ejecución efectiva en Actions pendiente para este commit.

Limitaciones: no se probaron deadlocks generales, tráfico HTTP por red ni recuperación real ante caída durante DDL. La credencial histórica de CI quedó en logs anteriores y no se eliminaron. No se autoriza despliegue ni publicación contractual.
