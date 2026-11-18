<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

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
            $this->rebuildMariaDb($db, $up);

            return;
        }

        if ($db->getDriverName() !== 'sqlite') {
            throw new RuntimeException('CAT-03 solo admite SQLite o MariaDB 11.8; no se ejecutó DDL.');
        }

        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('CAT-03 requiere tablas sin prefijo y ninguna transacción externa.');
        }

        if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('CAT-03 requiere foreign_keys activado.');
        }

        try {
            $db->statement('PRAGMA foreign_keys = OFF');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 0) {
                throw new RuntimeException('No se pudo desactivar foreign_keys.');
            }

            $db->transaction(function () use ($db, $up): void {
                [$sql, $indexes] = $this->preflight($db, $up);

                $old = $up
                    ? 'foreign key("area_id") references "areas"("id") on delete restrict on update cascade'
                    : 'constraint "prod_lines_area_org_fk" foreign key("area_id", "organization_id") references "areas"("id", "organization_id") on delete restrict on update restrict';
                $new = $up
                    ? 'constraint "prod_lines_area_org_fk" foreign key("area_id", "organization_id") references "areas"("id", "organization_id") on delete restrict on update restrict'
                    : 'foreign key("area_id") references "areas"("id") on delete restrict on update cascade';

                // Permitir identificadores entrecomillados o sin comillas en SQLite.
                $pattern = $up
                    ? '/foreign\s+key\s*\(\s*"area_id"\s*\)\s+references\s+"?areas"?\s*\(\s*"id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+cascade/i'
                    : '/constraint\s+"prod_lines_area_org_fk"\s+foreign\s+key\s*\(\s*"area_id"\s*,\s*"organization_id"\s*\)\s+references\s+"?areas"?\s*\(\s*"id"\s*,\s*"organization_id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+restrict/i';

                $sql = preg_replace($pattern, $new, $sql, 1, $replacements);

                if ($sql === null || $replacements !== 1) {
                    throw new RuntimeException(
                        'La definición de FK de production_lines no coincide con el esquema esperado.'
                    );
                }

                $sql = preg_replace('/^create table\s+"production_lines"\s*\(/i', 'create table "cat03_lines_rebuild" (', $sql, 1, $renamed);
                if ($renamed !== 1) {
                    throw new RuntimeException('La definición de production_lines no se puede reconstruir de forma segura.');
                }

                $db->statement($sql);
                $columns = '"id", "area_id", "name", "code", "description", "status", "created_at", "updated_at", "organization_id", "deleted_at"';
                $db->statement("INSERT INTO \"cat03_lines_rebuild\" ($columns) SELECT $columns FROM \"production_lines\"");
                $db->statement('DROP TABLE "production_lines"');
                $db->statement('ALTER TABLE "cat03_lines_rebuild" RENAME TO "production_lines"');
                foreach ($indexes as $index) {
                    if ($index->name !== 'prod_lines_area_org_idx') {
                        $db->statement($index->sql);
                    }
                }
                if ($up) {
                    $db->statement('CREATE INDEX "prod_lines_area_org_idx" ON "production_lines" ("area_id", "organization_id")');
                }
                $this->checkIntegrity($db);
            });
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
                throw new RuntimeException('No se pudo restaurar foreign_keys.');
            }
        }
    }

    private function rebuildMariaDb(Connection $db, bool $up): void
    {
        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('CAT-03 requiere tablas sin prefijo y ninguna transacción externa.');
        }

        $server = $db->selectOne('SELECT VERSION() AS version, DATABASE() AS schema_name, @@SESSION.foreign_key_checks AS fk_checks', [], false);
        if (
            !$server || !preg_match('/^(?:5\.5\.5-)?11\.8\.\d+.*MariaDB/i', $server->version)
            || !$server->schema_name || (int) $server->fk_checks !== 1
        ) {
            throw new RuntimeException('CAT-03 requiere MariaDB 11.8, base seleccionada y foreign_key_checks activo.');
        }

        $schema = $server->schema_name;
        $tables = $db->select("SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('production_lines', 'areas', 'organizations')", [$schema], false);
        if (count($tables) !== 3) {
            throw new RuntimeException('Faltan tablas requeridas para CAT-03.');
        }
        foreach ($tables as $table) {
            if ($table->type !== 'BASE TABLE' || strtoupper((string) $table->engine) !== 'INNODB') {
                throw new RuntimeException('CAT-03 requiere tablas InnoDB.');
            }
        }

        $columns = $db->select("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation_name, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND ((TABLE_NAME = 'production_lines' AND COLUMN_NAME IN ('id', 'area_id', 'organization_id')) OR (TABLE_NAME = 'areas' AND COLUMN_NAME IN ('id', 'organization_id')) OR (TABLE_NAME = 'organizations' AND COLUMN_NAME = 'id'))", [$schema], false);
        $keys = [];
        foreach ($columns as $column) {
            $keys[$column->table_name . '.' . $column->name] = $column;
        }
        $pairs = [
            ['production_lines.area_id', 'areas.id'],
            ['production_lines.organization_id', 'areas.organization_id'],
            ['production_lines.organization_id', 'organizations.id'],
        ];
        foreach ($pairs as [$child, $parent]) {
            if (!isset($keys[$child], $keys[$parent])) {
                throw new RuntimeException('Faltan columnas de clave requeridas para CAT-03.');
            }
            foreach ([$keys[$child], $keys[$parent]] as $column) {
                if (
                    strtolower($column->column_type) !== 'char(36)' || $column->nullable !== 'NO'
                    || !$column->charset || !$column->collation_name || stripos($column->extra, 'GENERATED') !== false
                ) {
                    throw new RuntimeException('Las columnas de clave CAT-03 no tienen la definición esperada.');
                }
            }
            if ($keys[$child]->charset !== $keys[$parent]->charset || $keys[$child]->collation_name !== $keys[$parent]->collation_name) {
                throw new RuntimeException('Las columnas de clave CAT-03 tienen colaciones incompatibles.');
            }
        }

        $rows = $db->select("SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name, SUB_PART AS sub_part, INDEX_TYPE AS index_type FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('production_lines', 'areas') ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", [$schema], false);
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->table_name][$row->index_name][] = $row;
        }
        $required = [
            ['production_lines', 'PRIMARY', ['id'], 0],
            ['production_lines', 'prod_lines_area_code_unique', ['area_id', 'code'], 0],
            ['production_lines', 'production_lines_organization_idx', ['organization_id'], 1],
            ['areas', 'PRIMARY', ['id'], 0],
            ['areas', 'areas_id_org_unique', ['id', 'organization_id'], 0],
        ];
        if ($up && isset($indexes['production_lines']['prod_lines_area_org_idx'])) {
            throw new RuntimeException('Ya existe el índice CAT-03.');
        }
        if (!$up) {
            $required[] = ['production_lines', 'prod_lines_area_org_idx', ['area_id', 'organization_id'], 1];
        }
        foreach ($required as [$table, $name, $expectedColumns, $nonUnique]) {
            $index = $indexes[$table][$name] ?? [];
            if (count($index) !== count($expectedColumns)) {
                throw new RuntimeException('Falta un índice requerido o su definición es inesperada.');
            }
            foreach ($index as $position => $part) {
                if (
                    $part->column_name !== $expectedColumns[$position] || (int) $part->sequence_number !== $position + 1
                    || (int) $part->non_unique !== $nonUnique || $part->sub_part !== null || strtoupper($part->index_type) !== 'BTREE'
                ) {
                    throw new RuntimeException('Definición de índice CAT-03 inesperada.');
                }
            }
        }

        $rows = $db->select("SELECT k.CONSTRAINT_NAME AS constraint_name, k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_SCHEMA AS parent_schema, k.REFERENCED_TABLE_NAME AS parent_table, k.REFERENCED_COLUMN_NAME AS parent_column, k.ORDINAL_POSITION AS position_number, r.UPDATE_RULE AS update_rule, r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = 'production_lines' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION", [$schema], false);
        $foreignKeys = [];
        foreach ($rows as $row) {
            $foreignKeys[$row->constraint_name][] = [$row->column_name, $row->parent_schema, $row->parent_table, $row->parent_column, (int) $row->position_number, $row->update_rule, $row->delete_rule];
        }
        $expected = [
            'production_lines_organization_id_foreign' => [['organization_id', $schema, 'organizations', 'id', 1, 'CASCADE', 'RESTRICT']],
            $up ? 'production_lines_area_id_foreign' : 'prod_lines_area_org_fk' => $up
                ? [['area_id', $schema, 'areas', 'id', 1, 'CASCADE', 'RESTRICT']]
                : [['area_id', $schema, 'areas', 'id', 1, 'RESTRICT', 'RESTRICT'], ['organization_id', $schema, 'areas', 'organization_id', 2, 'RESTRICT', 'RESTRICT']],
        ];
        ksort($foreignKeys);
        ksort($expected);
        if ($foreignKeys !== $expected) {
            throw new RuntimeException('Las FK de production_lines no coinciden con el esquema esperado.');
        }
        $newName = $up ? 'prod_lines_area_org_fk' : 'production_lines_area_id_foreign';
        if ($db->selectOne("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ? LIMIT 1", [$schema, $newName], false)) {
            throw new RuntimeException('El nombre de la FK CAT-03 ya está en uso.');
        }

        if ($db->selectOne('SELECT 1 FROM `production_lines` AS l LEFT JOIN `areas` AS a ON a.`id` = l.`area_id` AND a.`organization_id` = l.`organization_id` LEFT JOIN `organizations` AS o ON o.`id` = l.`organization_id` WHERE l.`area_id` IS NULL OR l.`organization_id` IS NULL OR a.`id` IS NULL OR o.`id` IS NULL LIMIT 1', [], false)) {
            throw new RuntimeException('Existen líneas con área u organización inconsistente.');
        }

        $db->statement($up
            ? 'ALTER TABLE `production_lines` DROP FOREIGN KEY `production_lines_area_id_foreign`, ADD INDEX `prod_lines_area_org_idx` (`area_id`, `organization_id`), ADD CONSTRAINT `prod_lines_area_org_fk` FOREIGN KEY (`area_id`, `organization_id`) REFERENCES `areas` (`id`, `organization_id`) ON DELETE RESTRICT ON UPDATE RESTRICT'
            : 'ALTER TABLE `production_lines` DROP FOREIGN KEY `prod_lines_area_org_fk`, DROP INDEX `prod_lines_area_org_idx`, ADD CONSTRAINT `production_lines_area_id_foreign` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    private function preflight(Connection $db, bool $up): array
    {
        $this->checkIntegrity($db);

        if ($db->select("SELECT name FROM sqlite_master WHERE type IN ('view', 'trigger')")) {
            throw new RuntimeException('CAT-03 rechaza vistas o triggers desconocidos.');
        }
        if ($db->select("SELECT name FROM sqlite_master WHERE name = 'cat03_lines_rebuild'")) {
            throw new RuntimeException('Ya existe el objeto temporal de CAT-03.');
        }
        if ($up && $db->select("SELECT name FROM sqlite_master WHERE name = 'prod_lines_area_org_idx'")) {
            throw new RuntimeException('Ya existe un objeto con el nombre del índice CAT-03.');
        }

        $columns = $db->select('PRAGMA table_xinfo("production_lines")');
        $names = array_map(fn($column) => $column->name, $columns);
        $expected = ['id', 'area_id', 'name', 'code', 'description', 'status', 'created_at', 'updated_at', 'organization_id', 'deleted_at'];
        if ($names !== $expected || array_filter($columns, fn($column) => $column->hidden !== 0)) {
            throw new RuntimeException('Las columnas de production_lines no coinciden con el esquema esperado.');
        }

        $table = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'production_lines'");
        if (!$table || !$table->sql) {
            throw new RuntimeException('No se encontró el DDL de production_lines.');
        }

        $indexes = $db->select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'production_lines' AND sql IS NOT NULL ORDER BY name");
        $actual = array_map(fn($index) => $index->name, $indexes);
        $expectedIndexes = ['prod_lines_area_code_unique', 'production_lines_organization_idx'];
        if (!$up) {
            $expectedIndexes[] = 'prod_lines_area_org_idx';
            sort($expectedIndexes);
        }
        if ($actual !== $expectedIndexes) {
            throw new RuntimeException('Los índices de production_lines no coinciden con el esquema esperado.');
        }
        $definitions = [
            'prod_lines_area_code_unique' => ['area_id', 'code'],
            'production_lines_organization_idx' => ['organization_id'],
            'prod_lines_area_org_idx' => ['area_id', 'organization_id'],
        ];
        $indexList = [];
        foreach ($db->select('PRAGMA index_list("production_lines")') as $index) {
            $indexList[$index->name] = $index;
        }
        foreach ($indexes as $index) {
            $info = $db->select('PRAGMA index_info("' . $index->name . '")');
            $unique = in_array($index->name, ['prod_lines_area_code_unique'], true);
            if (
                array_map(fn($column) => $column->name, $info) !== $definitions[$index->name]
                || (int) $indexList[$index->name]->unique !== (int) $unique
                || (int) $indexList[$index->name]->partial !== 0
            ) {
                throw new RuntimeException('Definición de índice de production_lines inesperada.');
            }
        }

        $parentIndex = false;
        foreach ($db->select('PRAGMA index_list("areas")') as $index) {
            if ($index->name === 'areas_id_org_unique' && (int) $index->unique === 1 && (int) $index->partial === 0) {
                $info = $db->select('PRAGMA index_info("areas_id_org_unique")');
                $parentIndex = array_map(fn($column) => $column->name, $info) === ['id', 'organization_id'];
            }
        }
        if (!$parentIndex) {
            throw new RuntimeException('Falta el índice único esperado de areas.');
        }

        if ($db->selectOne('SELECT 1 FROM production_lines AS l LEFT JOIN areas AS a ON a.id = l.area_id AND a.organization_id = l.organization_id WHERE l.area_id IS NULL OR l.organization_id IS NULL OR a.id IS NULL LIMIT 1')) {
            throw new RuntimeException('Existen líneas con área u organización inconsistente.');
        }

        $foreignKeys = $db->select('PRAGMA foreign_key_list("production_lines")');
        $groups = [];
        foreach ($foreignKeys as $fk) {
            $groups[$fk->id][] = [$fk->from, $fk->table, $fk->to, strtoupper($fk->on_update), strtoupper($fk->on_delete)];
        }
        $expectedForeignKeys = [
            [['organization_id', 'organizations', 'id', 'CASCADE', 'RESTRICT']],
            $up
            ? [['area_id', 'areas', 'id', 'CASCADE', 'RESTRICT']]
            : [['area_id', 'areas', 'id', 'RESTRICT', 'RESTRICT'], ['organization_id', 'areas', 'organization_id', 'RESTRICT', 'RESTRICT']],
        ];
        $actualForeignKeys = array_values($groups);
        sort($actualForeignKeys);
        sort($expectedForeignKeys);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException('Las FK de production_lines no coinciden con el esquema esperado.');
        }

        return [$table->sql, $indexes];
    }

    private function checkIntegrity(Connection $db): void
    {
        if ($db->select('PRAGMA foreign_key_check')) {
            throw new RuntimeException('foreign_key_check detectó inconsistencias.');
        }
        $result = $db->select('PRAGMA integrity_check');
        if (count($result) !== 1 || array_values((array) $result[0])[0] !== 'ok') {
            throw new RuntimeException('integrity_check detectó inconsistencias.');
        }
    }
};