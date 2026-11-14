# CAT-02 — S0.1/S0.2: FK compuesta Área → Planta

**Estado:** diseño de CAT-02-INT-01 aprobado por el responsable del proyecto el 2026-11-13, según el registro de trabajo disponible. Documento reconstruido el 2026-11-14; el original no pudo recuperarse desde el filesystem ni desde el historial Git inspeccionado.

## CAT-02-INT-01 — FK compuesta aprobada

En una migración futura, establecer:

```text
areas_plant_org_fk
areas(plant_id, organization_id)
    → plants(id, organization_id)
ON DELETE RESTRICT
ON UPDATE RESTRICT
```

Crear el índice hijo `areas_plant_org_idx (plant_id, organization_id)`. La aprobación define el diseño, no demuestra que la migración sea reversible en ambos motores ni autoriza aplicar DDL a bases persistentes.

## Hallazgo sobre la FK histórica

La migración `2026_10_10_000002_create_areas_table.php` define `areas.plant_id → plants.id`, `ON DELETE RESTRICT ON UPDATE CASCADE`. El nombre convencional de Laravel es `areas_plant_id_foreign`. La tabla también tiene su FK independiente de organización hacia `organizations` y el único `areas_plant_code_unique`; esas restricciones no forman parte del diseño de la FK compuesta y deben conservarse.

Al investigar inicialmente S1.1-A se observó en una prueba SQLite reducida que mantener ambas FK puede aceptar o rechazar el cambio de ID de planta según el orden de declaración. Por ello, el diseño inicial indicó conservar la histórica durante la investigación y detener la implementación si no se podía garantizar la regla aprobada. El resultado y la decisión posterior de sustitución están en [S1.1-A/A2](09-cat-02-s1-1-integridad-referencial.md).

## Datos y reversibilidad requeridos

Antes de añadir la restricción, una futura migración debe comprobar que todas las relaciones existentes corresponden al par planta-organización. Si encuentra inconsistencias, debe fallar sin reparar ni borrar datos automáticamente. Debe preservar filas activas y soft-deleted, índices y FK independientes, así como referencias entrantes. `down` debe restaurar el esquema anterior de manera explícita y comprobable.

SQLite y MariaDB requieren validación separada. El prototipo SQLite no representa una ejecución ni una garantía de comportamiento de MariaDB 11.8.
