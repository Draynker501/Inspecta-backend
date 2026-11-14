# CAT-02 — S0: alta de áreas e integridad

**Estado:** decisiones funcionales registradas como aprobadas por el responsable del proyecto. Este documento se reconstruyó el 2026-11-14 a partir del encargo vigente y del historial de trabajo disponible; no se recuperó el archivo original. No se atribuye aprobación a todo el equipo ni se afirma que el endpoint esté implementado.

## 1. Alcance

CAT-02 es el corte de alta de áreas bajo una planta. No amplía CAT-01, que corresponde a `POST /api/plants`. No autoriza CRUD general de áreas, líneas, inspecciones ni incidencias; tampoco autoriza modificar OpenAPI o desplegar cambios.

## 2. Decisiones funcionales aprobadas

| ID | Decisión |
|---|---|
| CAT-02-DEC-01 | La creación de áreas es el único corte funcional de CAT-02. |
| CAT-02-DEC-02 | La ruta propuesta es `POST /api/plants/{plantId}/areas`. |
| CAT-02-DEC-03 | Exigir `plants.manage` y membresía activa. No exigir además `plants.view` ni una asignación adicional a la planta. |
| CAT-02-DEC-04 | La planta padre debe pertenecer al tenant del contexto, existir, estar activa y no estar eliminada. |
| CAT-02-DEC-05 | Normalizar `code` con `trim` y mayúsculas; aceptar solo ASCII `A–Z`, `0–9` y `-`, máximo 50 caracteres. El código es único por planta, también frente a áreas soft-deleted. |
| CAT-02-DEC-06 | `type` es opcional y nullable, máximo 50 caracteres; no se exige un enum. |
| CAT-02-DEC-07 | Aceptar exclusivamente JSON; generar UUIDv7 en servidor; `status=true`; usar DTO explícito de ocho campos y rechazar cualquier campo adicional. |
| CAT-02-DEC-08 | Emitir `area.created` de forma transaccional, con `old_values=[]` y `new_values` limitado a `plant_id`, `name`, `code` y `status`. |

La aprobación original de CAT-02-DEC-07 especifica que la respuesta HTTP `201` se encapsula en `data` y contiene exactamente los ocho campos `id`, `organization_id`, `plant_id`, `name`, `code`, `type`, `description` y `status`. No incluye timestamps, `deleted_at` ni relaciones anidadas. Esta precisión restituye la especificación original comunicada por el responsable; no constituye una decisión nueva.

## 3. Precisiones HTTP aprobadas

- `415` para contenido no JSON, incluido `multipart`.
- `422 validation_failed` para JSON inválido, propiedades adicionales y parámetros query no permitidos.
- `422` con `errors.plantId` si la planta propia está inactiva.
- `404` genérico para planta ajena, inexistente o eliminada.
- `201` para alta válida.

No se documenta un esquema OpenAPI nuevo en esta fase.

## 4. Integridad jerárquica

La operación debe asegurar que el área y su planta pertenezcan a la misma organización. La regla de aplicación complementa, no reemplaza, las restricciones de base de datos. La decisión de integridad referencial aprobada está especificada en [CAT-02-INT-01 y CAT-02-INT-02](08-cat-02-s0-1-diseno-fk-area-planta.md).

## 5. Estado y límites

En el cierre histórico de S0, las decisiones anteriores no acreditaban endpoint, validación MariaDB ni despliegue. Las pruebas posteriores SQLite y MariaDB se documentan en [S1.1](09-cat-02-s1-1-integridad-referencial.md); no acreditan despliegue. El tratamiento de concurrencia HTTP y el catálogo exhaustivo de errores distintos de los expresamente aprobados quedan pendientes si no se precisan en las fuentes recuperadas.
