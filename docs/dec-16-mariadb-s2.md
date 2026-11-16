# DEC-16: preparación MariaDB S2

**Estado:** implementado y probado de forma focalizada en MariaDB 11.8.9
efímera; pendiente de auditoría independiente S2. No autoriza despliegue.
Se conservan las FK simples históricas y se añaden cuatro FK compuestas
`RESTRICT`; `down` retira únicamente los objetos nuevos.

En MariaDB 11.8, `ALTER TABLE` InnoDB es atómico para una tabla, pero confirma
implícitamente y no convierte en atómica una secuencia de modificaciones de
varias tablas. Crear índices candidatos en tres tablas y modificar las FK de
`inspections` puede dejar un estado parcial si falla un paso o termina el
proceso. Una transacción de Laravel no soluciona esto.

Antes del primer DDL:

- Exigir MariaDB 11.8, InnoDB, esquema seleccionado, ausencia de transacción
  externa, `foreign_key_checks=1` y escritura de aplicación detenida durante
  todo el preflight y el DDL; un preflight sin exclusión de escritura tiene una
  carrera entre la comprobación y la creación de restricciones.
- Verificar tablas, columnas, nulabilidad, tipos y colaciones compatibles,
  índices, nombres y definición exacta de las FK originales o nuevas, y
  colisiones en el esquema. Verificar también dependencias entrantes e índices
  necesarios para no eliminarlos en `down`.
- Comprobar sin reparar las cuatro relaciones y, para línea nullable, comprobar
  la línea únicamente cuando no sea nula. Rechazar datos inválidos y estados
  parciales en lugar de continuar o inferir que una migración terminó.
- Definir una secuencia de DDL con restricciones y sus índices en un único
  `ALTER TABLE inspections`, tras preparar las claves candidatas, manteniendo
  activadas las comprobaciones de FK. Probar también `down` y fallos en cada
  frontera entre sentencias.

## Recuperación ante un DDL parcialmente aplicado

Este procedimiento requiere supervisión y no es una reparación automática.
Mantener detenidas escrituras y
despliegues, no reintentar `migrate` ni `rollback`, no desactivar
`foreign_key_checks` y preservar logs y respaldo verificable. Consultar
`information_schema.STATISTICS`, `KEY_COLUMN_USAGE` y
`REFERENTIAL_CONSTRAINTS` para las cuatro tablas padre y `inspections`, y
comparar columnas, orden, unicidad, nombres y reglas con el esquema anterior y
el objetivo. Comprobar la tabla `migrations` por separado: su registro no
demuestra por sí solo que todos los DDL se completaron.

Si hay mezcla de estados, identificar el último paso efectivamente aplicado y
la dependencia de cada índice antes de decidir una recuperación. Una persona
autorizada debe elegir entre restaurar un respaldo consistente o ejecutar una
secuencia específica de reversión/continuación previamente ensayada sobre una
copia aislada equivalente; nunca eliminar índices o restricciones por nombre
sin comprobar sus consumidores. Revalidar datos, restricciones, índices y
`foreign_key_checks` antes de reanudar escrituras. Se ensayó una colisión de
índice tras el preflight: el segundo ALTER falló con error 1061, se detectó el
estado parcial y se retiraron supervisadamente los dos índices presentes antes
de reintentar. Se conservaron inspección e incidencia relacionada. No se han
ensayado todas las posibles fronteras de fallo ni se debe extrapolar una
reparación automática a otros estados.

En SQLite se mantiene el rechazo conservador de vistas y triggers de cualquier
tabla: al reconstruir `inspections` se elimina temporalmente la tabla original
y SQLite puede validar referencias durante el renombrado; recrear o ignorar
objetos arbitrarios sin analizar sus dependencias no garantiza preservación.
Las pruebas comprueban que el rechazo conserva esos objetos y restaura el pragma.

## Preparación S1: diagnóstico reproducible de solo lectura

La ruta MariaDB y su preflight están implementados. Las siguientes
consultas ayudan a inspeccionar un estado, pero no sustituyen el preflight
automático ni autorizan ejecutar DDL. Ejecutarlas únicamente en el esquema
aislado seleccionado y con escrituras y despliegues detenidos. No incluyen
credenciales ni modifican datos.

```sql
SELECT VERSION(), DATABASE(), @@SESSION.foreign_key_checks;
SELECT TABLE_NAME, TABLE_TYPE, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('inspections','checklist_versions','plants','areas','production_lines');
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
       CHARACTER_SET_NAME, COLLATION_NAME, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('inspections','checklist_versions','plants','areas','production_lines')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
       SUB_PART, INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('inspections','checklist_versions','plants','areas','production_lines')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
SELECT k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION,
       k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME,
       k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.REFERENTIAL_CONSTRAINTS r
  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME
 AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
WHERE k.REFERENCED_TABLE_NAME IS NOT NULL
  AND ((k.TABLE_SCHEMA = DATABASE()
        AND k.TABLE_NAME IN ('inspections','checklist_versions','plants','areas','production_lines'))
       OR (k.REFERENCED_TABLE_SCHEMA = DATABASE()
           AND k.REFERENCED_TABLE_NAME IN ('inspections','checklist_versions','plants','areas','production_lines')))
ORDER BY k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION;
SELECT COUNT(*) AS inconsistentes
FROM inspections i
LEFT JOIN checklist_versions v ON v.id = i.checklist_version_id AND v.organization_id = i.organization_id
LEFT JOIN plants p ON p.id = i.plant_id AND p.organization_id = i.organization_id
LEFT JOIN areas a ON a.id = i.area_id AND a.plant_id = i.plant_id AND a.organization_id = i.organization_id
LEFT JOIN production_lines l ON l.id = i.production_line_id AND l.area_id = i.area_id AND l.organization_id = i.organization_id
WHERE v.id IS NULL OR p.id IS NULL OR a.id IS NULL
   OR (i.production_line_id IS NOT NULL AND l.id IS NULL);
SELECT migration, batch FROM migrations
WHERE migration = '2026_11_18_000001_enforce_inspection_location_tenant_foreign_keys';
```

Registrar resultados completos antes y después de cada ensayo. El índice de
`plants(id, organization_id)` ya existe por una migración anterior. Los tres
índices nuevos previstos son `ins_cv_id_org_unique` sobre
`checklist_versions(id, organization_id)`, `ins_areas_id_plant_org_unique`
sobre `areas(id, plant_id, organization_id)` e
`ins_lines_id_area_org_unique` sobre `production_lines(id, area_id,
organization_id)`. Las FK previstas son `ins_cv_org_fk`, `ins_plant_org_fk`,
`ins_area_plant_org_fk` e `ins_line_area_org_fk`; la migración SQLite existente
define sus columnas y reglas. La línea de producción es nullable; las demás
columnas participantes de la inspección son obligatorias.

En S2 preparar una instancia MariaDB 11.8 efímera y aislada, comprobar
explícitamente su identidad antes de cualquier `migrate:fresh`, y probar:
up/down/up con datos y dependencias entrantes; inserciones y actualizaciones
cruzadas; línea nula; las cuatro clases de datos históricos inconsistentes sin
reparación; colisiones y definiciones incompatibles; dependencias de índices
en down; interrupción tras cada índice y tras cada ALTER de `inspections`, en
ambos sentidos. Guardar instantáneas de metadatos y filas para demostrar
rechazo sin DDL ante preflight fallido. El procedimiento de recuperación
definitivo requiere implementar y ensayar esas fronteras; no se infiere de
esta lista.
