# INSPECTA — Corrección de compatibilidad de migraciones con SQLite

## Contexto

Durante la integración de `master` en la rama local `integracion-api`, las pruebas automatizadas se detenían durante `RefreshDatabase`, antes de ejecutar las aserciones. Las migraciones CAT-02, CAT-03 y DEC-16 reconstruyen tablas de SQLite a partir del SQL almacenado en `sqlite_master` y utilizan comparaciones textuales de definiciones de claves foráneas.

El SQL esperado incluía comillas dobles en el nombre de la tabla referenciada (`references "plants"("id")`), pero SQLite almacenaba algunas referencias sin esas comillas (`references plants("id")`). Ambas formas son válidas; `str_ireplace()` no las considera iguales. Se sustituyó la comparación literal por una expresión regular que admite ambas representaciones, manteniendo la exigencia de una única coincidencia.

**Alcance:** únicamente el tratamiento de DDL de SQLite; no se pretendió cambiar las columnas, las relaciones ni las reglas `ON DELETE` / `ON UPDATE`. Las tres migraciones consultadas en `master` tienen una rama separada para `mysql`/`mariadb` antes de la lógica SQLite.

## 1. CAT-02 — `areas` → `plants`

**Archivo:** `database/migrations/2026_11_14_000001_replace_areas_plant_foreign_key.php`

**Ubicación:** dentro de `rebuild(bool $up)`, en el callback de `$db->transaction()`, inmediatamente después de definir `$old` y `$new` y antes del `preg_replace()` que renombra `areas` a `cat02_areas_rebuild`.

**Bloque original (restaurar para deshacer el cambio):**

```php
$sql = str_ireplace($old, $new, $sql, $replacements);
if ($replacements !== 1) {
    throw new RuntimeException('La definición de FK de areas no coincide con el esquema esperado.');
}
```

**Bloque corregido (sustituye al anterior):**

```php
// Permitir identificadores entrecomillados o sin comillas en SQLite.
$pattern = $up
    ? '/foreign\s+key\s*\(\s*"plant_id"\s*\)\s+references\s+"?plants"?\s*\(\s*"id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+cascade/i'
    : '/constraint\s+"areas_plant_org_fk"\s+foreign\s+key\s*\(\s*"plant_id"\s*,\s*"organization_id"\s*\)\s+references\s+"?plants"?\s*\(\s*"id"\s*,\s*"organization_id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+restrict/i';

$sql = preg_replace($pattern, $new, $sql, 1, $replacements);
if ($sql === null || $replacements !== 1) {
    throw new RuntimeException('La definición de FK de areas no coincide con el esquema esperado.');
}
```

## 2. CAT-03 — `production_lines` → `areas`

**Archivo:** `database/migrations/2026_11_15_000001_replace_production_lines_area_foreign_key.php`

**Ubicación:** dentro de `rebuild(bool $up)`, en el callback de `$db->transaction()`, después de definir `$old` y `$new` y antes de renombrar la tabla a `cat03_lines_rebuild`.

**Bloque original (restaurar para deshacer el cambio):**

```php
$sql = str_ireplace($old, $new, $sql, $replacements);
if ($replacements !== 1) {
    throw new RuntimeException('La definición de FK de production_lines no coincide con el esquema esperado.');
}
```

**Bloque corregido recomendado:**

```php
// Permitir identificadores entrecomillados o sin comillas en SQLite.
$pattern = $up
    ? '/foreign\s+key\s*\(\s*"area_id"\s*\)\s+references\s+"?areas"?\s*\(\s*"id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+cascade/i'
    : '/constraint\s+"prod_lines_area_org_fk"\s+foreign\s+key\s*\(\s*"area_id"\s*,\s*"organization_id"\s*\)\s+references\s+"?areas"?\s*\(\s*"id"\s*,\s*"organization_id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+restrict/i';

$sql = preg_replace($pattern, $new, $sql, 1, $replacements);
if ($sql === null || $replacements !== 1) {
    throw new RuntimeException('La definición de FK de production_lines no coincide con el esquema esperado.');
}
```

**Atención:** el nombre real de la restricción en `master` es `prod_lines_area_org_fk`. En una propuesta preliminar se mencionó `lines_area_org_fk`; si ese texto aparece en tu copia local, corrígelo al nombre real antes de probar `down()`.

## 3. DEC-16 — Claves foráneas de `inspections`

**Archivo:** `database/migrations/2026_11_18_000001_enforce_inspection_location_tenant_foreign_keys.php`

**Ubicación:** dentro de `rebuild(bool $up)`, en el callback transaccional y dentro del `foreach (self::RELATIONS as [...])`, inmediatamente después de construir `$old` y `$new`.

**Bloque original (restaurar para deshacer el cambio):**

```php
$sql = str_ireplace($up ? $old : $new, $up ? $new : $old, $sql, $count);
if ($count !== 1) {
    throw new RuntimeException("DEC-16: FK inesperada para $column.");
}
```

**Bloque corregido (sustituye al anterior):**

```php
// Aceptar el nombre de la tabla referenciada con o sin comillas dobles.
$expected = $up ? $old : $new;
$replacement = $up ? $new : $old;

$pattern = preg_quote($expected, '/');
$quotedParent = preg_quote('"'.$parent.'"', '/');
$pattern = str_replace(
    $quotedParent,
    '"?'.preg_quote($parent, '/').'"?',
    $pattern
);

$sql = preg_replace('/'.$pattern.'/i', $replacement, $sql, 1, $count);
if ($sql === null || $count !== 1) {
    throw new RuntimeException("DEC-16: FK inesperada para $column.");
}
```

La misma sustitución se aplica a las relaciones de `checklist_version_id`, `plant_id`, `area_id` y `production_line_id`, según `self::RELATIONS`.

## Cómo revertir **solo los cambios de código**

1. Cambiar a la rama `integracion-api` y revisar `git status`.
2. Abrir cada uno de los tres archivos anteriores.
3. Localizar **el bloque corregido** mostrado en su sección, sin eliminar las declaraciones de `$old` y `$new`.
4. Reemplazar ese bloque por el **bloque original** de la misma sección, respetando la indentación del método y el lugar indicado.
5. Eliminar cualquier `dump()` temporal de diagnóstico.
6. Revisar `git diff -- database/migrations` y comprobar que no se modificaron otras instrucciones.

Alternativa con Git, **solo si esos archivos no contienen otros cambios locales que deban conservarse**:

```bash
git diff -- database/migrations/2026_11_14_000001_replace_areas_plant_foreign_key.php database/migrations/2026_11_15_000001_replace_production_lines_area_foreign_key.php database/migrations/2026_11_18_000001_enforce_inspection_location_tenant_foreign_keys.php
```

No ejecutar `git restore` indiscriminadamente: descartaría todas las modificaciones sin confirmar de esos archivos, no solo estas correcciones.

**No confundir:** restaurar código no ejecuta `down()` ni modifica una base ya migrada. Para probar un rollback real, utilizar exclusivamente una base de datos desechable, con respaldo si contiene datos.

## Validación y estado

- Suite de Checklists: **78/78 aprobadas**.
- Suite general: **686 aprobadas, 28 fallidas y 93 omitidas**; las omitidas corresponden al entorno aislado de MariaDB, según la ejecución reportada.
- No se ha establecido que las 28 fallidas estén relacionadas con estas modificaciones.
- **Pendiente:** comprobar explícitamente los métodos `down()` en SQLite y las migraciones en una instancia aislada de MariaDB 11.8.

## Criterio para conservar los cambios

Se recomienda mantenerlos porque resuelven una diferencia de serialización del DDL de SQLite sin relajar la exigencia de una coincidencia única. La ruta de MariaDB utiliza una implementación separada, aunque su funcionamiento debe validarse con pruebas específicas.

**Nota:** los bloques originales se contrastaron con la rama remota `master`; los bloques corregidos corresponden a los cambios discutidos durante la integración. Antes de copiar un bloque corregido sobre un archivo local, comparar con su contenido actual para no sobrescribir ajustes adicionales.
