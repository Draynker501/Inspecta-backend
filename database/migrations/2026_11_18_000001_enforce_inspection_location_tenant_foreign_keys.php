<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

    private const PARENTS = [
        ['checklist_versions', 'ins_cv_id_org_unique', ['id', 'organization_id']],
        ['areas', 'ins_areas_id_plant_org_unique', ['id', 'plant_id', 'organization_id']],
        ['production_lines', 'ins_lines_id_area_org_unique', ['id', 'area_id', 'organization_id']],
    ];

    private const RELATIONS = [
        ['checklist_version_id', 'checklist_versions', ['checklist_version_id', 'organization_id'], ['id', 'organization_id'], 'ins_cv_org_fk'],
        ['plant_id', 'plants', ['plant_id', 'organization_id'], ['id', 'organization_id'], 'ins_plant_org_fk'],
        ['area_id', 'areas', ['area_id', 'plant_id', 'organization_id'], ['id', 'plant_id', 'organization_id'], 'ins_area_plant_org_fk'],
        ['production_line_id', 'production_lines', ['production_line_id', 'area_id', 'organization_id'], ['id', 'area_id', 'organization_id'], 'ins_line_area_org_fk'],
    ];

    public function up(): void
    {
        $this->rebuild(true);
    }

    public function down(): void
    {
        $this->rebuild(false);
    }

    private function rebuild(bool $up): void
    {
        $db = DB::connection($this->getConnection());
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->mariaDb($db, $up);

            return;
        }
        if ($db->getDriverName() !== 'sqlite') {
            throw new RuntimeException('DEC-16 solo admite SQLite y MariaDB 11.8.');
        }
        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('DEC-16 requiere tablas sin prefijo y ninguna transacción externa.');
        }
        if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('DEC-16 requiere foreign_keys activado.');
        }

        try {
            $db->statement('PRAGMA foreign_keys = OFF');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 0) {
                throw new RuntimeException('DEC-16 no pudo desactivar foreign_keys.');
            }
            $db->transaction(function () use ($db, $up): void {
                $this->integrity($db);
                if ($db->select("SELECT name FROM sqlite_master WHERE type IN ('view', 'trigger') OR name = 'dec16_inspections_rebuild'")) {
                    throw new RuntimeException('DEC-16 rechaza vistas, triggers u objeto temporal existente.');
                }
                $columns = $db->select('PRAGMA table_xinfo("inspections")');
                $names = array_map(fn($column) => $column->name, $columns);
                $expected = ['id', 'user_id', 'supervisor_id', 'checklist_version_id', 'plant_id', 'area_id', 'production_line_id', 'status', 'started_at', 'completed_at', 'supervisor_confirmed_at', 'rejected_at', 'supervisor_observations', 'latitude', 'longitude', 'location_description', 'observations', 'created_at', 'updated_at', 'organization_id'];
                if ($names !== $expected || array_filter($columns, fn($column) => (int) $column->hidden !== 0)) {
                    throw new RuntimeException('DEC-16: columnas inesperadas en inspections.');
                }
                $table = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'inspections'");
                if (!$table || !$table->sql) {
                    throw new RuntimeException('DEC-16: falta DDL de inspections.');
                }
                $sql = $table->sql;
                $indexes = $db->select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'inspections' AND sql IS NOT NULL ORDER BY name");
                foreach (self::PARENTS as [$parent, $index, $fields]) {
                    $existing = $db->selectOne('SELECT name FROM sqlite_master WHERE name = ?', [$index]);
                    if ((bool) $existing !== !$up) {
                        throw new RuntimeException("DEC-16: estado inesperado del índice $index.");
                    }
                    if (!$up) {
                        $info = $db->select('PRAGMA index_info("' . $index . '")');
                        $list = $db->select('PRAGMA index_list("' . $parent . '")');
                        $match = array_values(array_filter($list, fn($item) => $item->name === $index && (int) $item->unique === 1 && (int) $item->partial === 0));
                        if (count($match) !== 1 || array_map(fn($item) => $item->name, $info) !== $fields) {
                            throw new RuntimeException("DEC-16: definición inesperada del índice $index.");
                        }
                    }
                }
                if ($up && $db->selectOne('SELECT 1 FROM inspections i LEFT JOIN checklist_versions v ON v.id = i.checklist_version_id AND v.organization_id = i.organization_id LEFT JOIN plants p ON p.id = i.plant_id AND p.organization_id = i.organization_id LEFT JOIN areas a ON a.id = i.area_id AND a.plant_id = i.plant_id AND a.organization_id = i.organization_id LEFT JOIN production_lines l ON l.id = i.production_line_id AND l.area_id = i.area_id AND l.organization_id = i.organization_id WHERE v.id IS NULL OR p.id IS NULL OR a.id IS NULL OR (i.production_line_id IS NOT NULL AND l.id IS NULL) LIMIT 1')) {
                    throw new RuntimeException('DEC-16: inspecciones históricas con ubicación o versión inconsistente.');
                }
                foreach (self::RELATIONS as [$column, $parent, $childFields, $parentFields, $name]) {
                    $quote = fn($fields) => implode(', ', array_map(fn($field) => '"' . $field . '"', $fields));
                    $old = 'foreign key("' . $column . '") references "' . $parent . '"("id") on delete restrict on update cascade';
                    $new = 'constraint "' . $name . '" foreign key(' . $quote($childFields) . ') references "' . $parent . '"(' . $quote($parentFields) . ') on delete restrict on update restrict';
                    // Aceptar el nombre de la tabla referenciada con o sin comillas
// dobles, conservando el resto de la definición de la FK.
                    $expected = $up ? $old : $new;
                    $replacement = $up ? $new : $old;

                    // Construir una expresión regular a partir de la definición esperada.
                    $pattern = preg_quote($expected, '/');

                    // SQLite puede generar REFERENCES tabla("id") o REFERENCES "tabla"("id").
                    $quotedParent = preg_quote('"' . $parent . '"', '/');
                    $pattern = str_replace(
                        $quotedParent,
                        '"?' . preg_quote($parent, '/') . '"?',
                        $pattern
                    );

                    $sql = preg_replace(
                        '/' . $pattern . '/i',
                        $replacement,
                        $sql,
                        1,
                        $count
                    );

                    if ($sql === null || $count !== 1) {
                        throw new RuntimeException("DEC-16: FK inesperada para $column.");
                    }
                }
                if ($up) {
                    foreach (self::PARENTS as [$parent, $index, $fields]) {
                        $quoted = implode(', ', array_map(fn($field) => '"' . $field . '"', $fields));
                        $db->statement("CREATE UNIQUE INDEX \"$index\" ON \"$parent\" ($quoted)");
                    }
                }
                $sql = preg_replace('/^create table\s+"inspections"\s*\(/i', 'create table "dec16_inspections_rebuild" (', $sql, 1, $count);
                if ($count !== 1) {
                    throw new RuntimeException('DEC-16: DDL de inspections inesperado.');
                }
                $quoted = implode(', ', array_map(fn($column) => '"' . $column . '"', $names));
                $db->statement($sql);
                $db->statement("INSERT INTO \"dec16_inspections_rebuild\" ($quoted) SELECT $quoted FROM \"inspections\"");
                $db->statement('DROP TABLE "inspections"');
                $db->statement('ALTER TABLE "dec16_inspections_rebuild" RENAME TO "inspections"');
                foreach ($indexes as $index) {
                    $db->statement($index->sql);
                }
                if (!$up) {
                    foreach (self::PARENTS as [, $index]) {
                        $db->statement("DROP INDEX \"$index\"");
                    }
                }
                $this->integrity($db);
            });
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
                throw new RuntimeException('DEC-16 no pudo restaurar foreign_keys.');
            }
        }
    }

    private function mariaDb(Connection $db, bool $up): void
    {
        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('DEC-16 requiere tablas sin prefijo y ninguna transacción externa.');
        }
        $server = $db->selectOne('SELECT VERSION() version, DATABASE() schema_name, @@SESSION.foreign_key_checks fk_checks', [], false);
        if (
            !$server || !preg_match('/^(?:5\.5\.5-)?11\.8\.\d+.*MariaDB/i', $server->version)
            || !$server->schema_name || (int) $server->fk_checks !== 1
        ) {
            throw new RuntimeException('DEC-16 requiere MariaDB 11.8 y foreign_key_checks activado.');
        }
        $schema = $server->schema_name;
        foreach (['inspections', 'checklist_versions', 'plants', 'areas', 'production_lines'] as $table) {
            $row = $db->selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = ?', [$schema, $table, 'BASE TABLE'], false);
            if (!$row || strtoupper($row->ENGINE) !== 'INNODB') {
                throw new RuntimeException("DEC-16 requiere tabla InnoDB $table.");
            }
        }
        foreach (self::PARENTS as [$table, $name, $fields]) {
            $rows = $db->select('SELECT COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$schema, $table, $name], false);
            if (
                $up ? $rows !== [] : ($rows === [] || array_column($rows, 'COLUMN_NAME') !== $fields
                    || array_filter($rows, fn($row) => (int) $row->NON_UNIQUE !== 0 || $row->SUB_PART !== null))
            ) {
                throw new RuntimeException("DEC-16: estado parcial o índice inesperado $name; no se ejecutó DDL.");
            }
        }
        $plantIndex = $db->select("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'plants' ORDER BY INDEX_NAME, SEQ_IN_INDEX", [$schema], false);
        $unique = [];
        foreach ($plantIndex as $row) {
            if ((int) $row->NON_UNIQUE === 0 && $row->SUB_PART === null) {
                $unique[$row->INDEX_NAME][] = $row->COLUMN_NAME;
            }
        }
        if (!in_array(['id', 'organization_id'], $unique, true)) {
            throw new RuntimeException('DEC-16 requiere índice único plants(id, organization_id).');
        }
        foreach (self::RELATIONS as [$column, $parent, $childFields, $parentFields, $name]) {
            $rows = $db->select('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION', [$schema, 'inspections', $name], false);
            if (
                $up ? $rows !== [] : ($rows === [] || array_column($rows, 'COLUMN_NAME') !== $childFields
                    || array_column($rows, 'REFERENCED_COLUMN_NAME') !== $parentFields
                    || array_filter($rows, fn($row) => $row->REFERENCED_TABLE_NAME !== $parent || $row->UPDATE_RULE !== 'RESTRICT' || $row->DELETE_RULE !== 'RESTRICT'))
            ) {
                throw new RuntimeException("DEC-16: estado parcial o FK inesperada $name; no se ejecutó DDL.");
            }
            $old = $db->select('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION', [$schema, 'inspections', 'inspections_' . $column . '_foreign'], false);
            if (
                count($old) !== 1 || $old[0]->COLUMN_NAME !== $column || $old[0]->REFERENCED_TABLE_SCHEMA !== $schema
                || $old[0]->REFERENCED_TABLE_NAME !== $parent || $old[0]->REFERENCED_COLUMN_NAME !== 'id'
                || $old[0]->UPDATE_RULE !== 'CASCADE' || $old[0]->DELETE_RULE !== 'RESTRICT'
            ) {
                throw new RuntimeException("DEC-16: FK histórica inesperada para $column.");
            }
            foreach ($childFields as $i => $child) {
                $a = $db->selectOne('SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$schema, 'inspections', $child], false);
                $b = $db->selectOne('SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$schema, $parent, $parentFields[$i]], false);
                if (
                    !$a || !$b || $a->COLUMN_TYPE !== $b->COLUMN_TYPE || $a->CHARACTER_SET_NAME !== $b->CHARACTER_SET_NAME || $a->COLLATION_NAME !== $b->COLLATION_NAME
                    || ($a->IS_NULLABLE === 'YES') !== ($child === 'production_line_id')
                ) {
                    throw new RuntimeException("DEC-16: columna incompatible inspections.$child.");
                }
            }
        }
        if ($up && $db->selectOne('SELECT 1 FROM inspections i LEFT JOIN checklist_versions v ON v.id = i.checklist_version_id AND v.organization_id = i.organization_id LEFT JOIN plants p ON p.id = i.plant_id AND p.organization_id = i.organization_id LEFT JOIN areas a ON a.id = i.area_id AND a.plant_id = i.plant_id AND a.organization_id = i.organization_id LEFT JOIN production_lines l ON l.id = i.production_line_id AND l.area_id = i.area_id AND l.organization_id = i.organization_id WHERE v.id IS NULL OR p.id IS NULL OR a.id IS NULL OR (i.production_line_id IS NOT NULL AND l.id IS NULL) LIMIT 1')) {
            throw new RuntimeException('DEC-16: inspecciones históricas con ubicación o versión inconsistente.');
        }
        $quote = fn($fields) => implode(', ', array_map(fn($field) => '`' . $field . '`', $fields));
        if ($up) {
            foreach (self::PARENTS as [$table, $name, $fields]) {
                $db->statement("ALTER TABLE `$table` ADD UNIQUE KEY `$name` (" . $quote($fields) . ')');
            }
            $clauses = [];
            foreach (self::RELATIONS as [, $parent, $child, $fields, $name]) {
                $clauses[] = "ADD CONSTRAINT `$name` FOREIGN KEY (" . $quote($child) . ") REFERENCES `$parent` (" . $quote($fields) . ') ON DELETE RESTRICT ON UPDATE RESTRICT';
            }
            $db->statement('ALTER TABLE `inspections` ' . implode(', ', $clauses));
        } else {
            $clauses = array_map(fn($relation) => 'DROP FOREIGN KEY `' . $relation[4] . '`', self::RELATIONS);
            $db->statement('ALTER TABLE `inspections` ' . implode(', ', $clauses));
            foreach (self::PARENTS as [$table, $name]) {
                $db->statement("ALTER TABLE `$table` DROP INDEX `$name`");
            }
        }
    }

    private function integrity(Connection $db): void
    {
        if ($db->select('PRAGMA foreign_key_check') || $db->scalar('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('DEC-16: verificación de integridad fallida.');
        }
    }
};
