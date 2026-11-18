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
            throw new RuntimeException('CAT-02 solo admite SQLite o MariaDB 11.8; no se ejecutó DDL.');
        }

        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('CAT-02 requiere tablas sin prefijo y ninguna transacción externa.');
        }

        if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('CAT-02 requiere foreign_keys activado.');
        }

        try {
            $db->statement('PRAGMA foreign_keys = OFF');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 0) {
                throw new RuntimeException('No se pudo desactivar foreign_keys.');
            }

            $db->transaction(function () use ($db, $up): void {
                [$sql, $indexes] = $this->preflight($db, $up);

                $old = $up
                    ? 'foreign key("plant_id") references "plants"("id") on delete restrict on update cascade'
                    : 'constraint "areas_plant_org_fk" foreign key("plant_id", "organization_id") references "plants"("id", "organization_id") on delete restrict on update restrict';
                $new = $up
                    ? 'constraint "areas_plant_org_fk" foreign key("plant_id", "organization_id") references "plants"("id", "organization_id") on delete restrict on update restrict'
                    : 'foreign key("plant_id") references "plants"("id") on delete restrict on update cascade';

                // Permitir identificadores entrecomillados o sin comillas en SQLite.
                $pattern = $up
                    ? '/foreign\s+key\s*\(\s*"plant_id"\s*\)\s+references\s+"?plants"?\s*\(\s*"id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+cascade/i'
                    : '/constraint\s+"areas_plant_org_fk"\s+foreign\s+key\s*\(\s*"plant_id"\s*,\s*"organization_id"\s*\)\s+references\s+"?plants"?\s*\(\s*"id"\s*,\s*"organization_id"\s*\)\s+on\s+delete\s+restrict\s+on\s+update\s+restrict/i';

                $sql = preg_replace($pattern, $new, $sql, 1, $replacements);

                if ($sql === null || $replacements !== 1) {
                    throw new RuntimeException(
                        'La definición de FK de areas no coincide con el esquema esperado.'
                    );
                }

                $sql = preg_replace('/^create table\s+"areas"\s*\(/i', 'create table "cat02_areas_rebuild" (', $sql, 1, $renamed);
                if ($renamed !== 1) {
                    throw new RuntimeException('La definición de areas no se puede reconstruir de forma segura.');
                }

                $db->statement($sql);
                $columns = '"id", "plant_id", "name", "code", "type", "description", "status", "created_at", "updated_at", "organization_id", "deleted_at"';
                $db->statement("INSERT INTO \"cat02_areas_rebuild\" ($columns) SELECT $columns FROM \"areas\"");
                $db->statement('DROP TABLE "areas"');
                $db->statement('ALTER TABLE "cat02_areas_rebuild" RENAME TO "areas"');
                foreach ($indexes as $index) {
                    if ($index->name !== 'areas_plant_org_idx') {
                        $db->statement($index->sql);
                    }
                }
                if ($up) {
                    $db->statement('CREATE INDEX "areas_plant_org_idx" ON "areas" ("plant_id", "organization_id")');
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
            throw new RuntimeException('CAT-02 requiere tablas sin prefijo y ninguna transacción externa.');
        }

        $server = $db->selectOne('SELECT VERSION() AS version, DATABASE() AS schema_name, @@SESSION.foreign_key_checks AS fk_checks', [], false);
        if (
            !$server || !preg_match('/^(?:5\.5\.5-)?11\.8\.\d+.*MariaDB/i', $server->version)
            || !$server->schema_name || (int) $server->fk_checks !== 1
        ) {
            throw new RuntimeException('CAT-02 requiere MariaDB 11.8, base seleccionada y foreign_key_checks activo.');
        }

        $schema = $server->schema_name;
        $tables = $db->select("SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('areas', 'plants', 'organizations')", [$schema], false);
        if (count($tables) !== 3) {
            throw new RuntimeException('Faltan tablas requeridas para CAT-02.');
        }
        foreach ($tables as $table) {
            if ($table->type !== 'BASE TABLE' || strtoupper((string) $table->engine) !== 'INNODB') {
                throw new RuntimeException('CAT-02 requiere tablas InnoDB.');
            }
        }

        $columns = $db->select("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation_name, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND ((TABLE_NAME = 'areas' AND COLUMN_NAME IN ('id', 'plant_id', 'organization_id')) OR (TABLE_NAME = 'plants' AND COLUMN_NAME IN ('id', 'organization_id')) OR (TABLE_NAME = 'organizations' AND COLUMN_NAME = 'id'))", [$schema], false);
        $keys = [];
        foreach ($columns as $column) {
            $keys[$column->table_name . '.' . $column->name] = $column;
        }
        $pairs = [
            ['areas.plant_id', 'plants.id'],
            ['areas.organization_id', 'plants.organization_id'],
            ['areas.organization_id', 'organizations.id'],
        ];
        foreach ($pairs as [$child, $parent]) {
            if (!isset($keys[$child], $keys[$parent])) {
                throw new RuntimeException('Faltan columnas de clave requeridas para CAT-02.');
            }
            foreach ([$keys[$child], $keys[$parent]] as $column) {
                if (
                    strtolower($column->column_type) !== 'char(36)' || $column->nullable !== 'NO'
                    || !$column->charset || !$column->collation_name || stripos($column->extra, 'GENERATED') !== false
                ) {
                    throw new RuntimeException('Las columnas de clave CAT-02 no tienen la definición esperada.');
                }
            }
            if ($keys[$child]->charset !== $keys[$parent]->charset || $keys[$child]->collation_name !== $keys[$parent]->collation_name) {
                throw new RuntimeException('Las columnas de clave CAT-02 tienen colaciones incompatibles.');
            }
        }

        $rows = $db->select("SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name, SUB_PART AS sub_part, INDEX_TYPE AS index_type FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('areas', 'plants') ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", [$schema], false);
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->table_name][$row->index_name][] = $row;
        }
        $required = [
            ['areas', 'PRIMARY', ['id'], 0],
            ['areas', 'areas_id_org_unique', ['id', 'organization_id'], 0],
            ['areas', 'areas_plant_code_unique', ['plant_id', 'code'], 0],
            ['areas', 'areas_organization_idx', ['organization_id'], 1],
            ['plants', 'PRIMARY', ['id'], 0],
            ['plants', 'plants_id_org_unique', ['id', 'organization_id'], 0],
        ];
        if ($up && isset($indexes['areas']['areas_plant_org_idx'])) {
            throw new RuntimeException('Ya existe el índice CAT-02.');
        }
        if (!$up) {
            $required[] = ['areas', 'areas_plant_org_idx', ['plant_id', 'organization_id'], 1];
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
                    throw new RuntimeException('Definición de índice CAT-02 inesperada.');
                }
            }
        }

        $rows = $db->select("SELECT k.CONSTRAINT_NAME AS constraint_name, k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_SCHEMA AS parent_schema, k.REFERENCED_TABLE_NAME AS parent_table, k.REFERENCED_COLUMN_NAME AS parent_column, k.ORDINAL_POSITION AS position_number, r.UPDATE_RULE AS update_rule, r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = 'areas' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION", [$schema], false);
        $foreignKeys = [];
        foreach ($rows as $row) {
            $foreignKeys[$row->constraint_name][] = [$row->column_name, $row->parent_schema, $row->parent_table, $row->parent_column, (int) $row->position_number, $row->update_rule, $row->delete_rule];
        }
        $expected = [
            'areas_organization_id_foreign' => [['organization_id', $schema, 'organizations', 'id', 1, 'CASCADE', 'RESTRICT']],
            $up ? 'areas_plant_id_foreign' : 'areas_plant_org_fk' => $up
                ? [['plant_id', $schema, 'plants', 'id', 1, 'CASCADE', 'RESTRICT']]
                : [['plant_id', $schema, 'plants', 'id', 1, 'RESTRICT', 'RESTRICT'], ['organization_id', $schema, 'plants', 'organization_id', 2, 'RESTRICT', 'RESTRICT']],
        ];
        ksort($foreignKeys);
        ksort($expected);
        if ($foreignKeys !== $expected) {
            throw new RuntimeException('Las FK de areas no coinciden con el esquema esperado.');
        }
        $newName = $up ? 'areas_plant_org_fk' : 'areas_plant_id_foreign';
        if ($db->selectOne("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ? LIMIT 1", [$schema, $newName], false)) {
            throw new RuntimeException('El nombre de la FK CAT-02 ya está en uso.');
        }

        if ($db->selectOne('SELECT 1 FROM `areas` AS a LEFT JOIN `plants` AS p ON p.`id` = a.`plant_id` AND p.`organization_id` = a.`organization_id` LEFT JOIN `organizations` AS o ON o.`id` = a.`organization_id` WHERE a.`plant_id` IS NULL OR a.`organization_id` IS NULL OR p.`id` IS NULL OR o.`id` IS NULL LIMIT 1', [], false)) {
            throw new RuntimeException('Existen áreas con planta u organización inconsistente.');
        }

        // Mantener checks activos permite que InnoDB valide las filas al agregar la FK.
        $db->statement($up
            ? 'ALTER TABLE `areas` DROP FOREIGN KEY `areas_plant_id_foreign`, ADD INDEX `areas_plant_org_idx` (`plant_id`, `organization_id`), ADD CONSTRAINT `areas_plant_org_fk` FOREIGN KEY (`plant_id`, `organization_id`) REFERENCES `plants` (`id`, `organization_id`) ON DELETE RESTRICT ON UPDATE RESTRICT'
            : 'ALTER TABLE `areas` DROP FOREIGN KEY `areas_plant_org_fk`, DROP INDEX `areas_plant_org_idx`, ADD CONSTRAINT `areas_plant_id_foreign` FOREIGN KEY (`plant_id`) REFERENCES `plants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    private function preflight(Connection $db, bool $up): array
    {
        $this->checkIntegrity($db);

        if ($db->select("SELECT name FROM sqlite_master WHERE type IN ('view', 'trigger')")) {
            throw new RuntimeException('CAT-02 rechaza vistas o triggers desconocidos.');
        }
        if ($db->select("SELECT name FROM sqlite_master WHERE name = 'cat02_areas_rebuild'")) {
            throw new RuntimeException('Ya existe el objeto temporal de CAT-02.');
        }
        if ($up && $db->select("SELECT name FROM sqlite_master WHERE name = 'areas_plant_org_idx'")) {
            throw new RuntimeException('Ya existe un objeto con el nombre del índice CAT-02.');
        }

        $columns = $db->select('PRAGMA table_xinfo("areas")');
        $names = array_map(fn($column) => $column->name, $columns);
        $expected = ['id', 'plant_id', 'name', 'code', 'type', 'description', 'status', 'created_at', 'updated_at', 'organization_id', 'deleted_at'];
        if ($names !== $expected || array_filter($columns, fn($column) => $column->hidden !== 0)) {
            throw new RuntimeException('Las columnas de areas no coinciden con el esquema esperado.');
        }

        $table = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'areas'");
        if (!$table || !$table->sql) {
            throw new RuntimeException('No se encontró el DDL de areas.');
        }

        $indexes = $db->select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'areas' AND sql IS NOT NULL ORDER BY name");
        $actual = array_map(fn($index) => $index->name, $indexes);
        $expectedIndexes = ['areas_id_org_unique', 'areas_organization_idx', 'areas_plant_code_unique'];
        if (!$up) {
            $expectedIndexes[] = 'areas_plant_org_idx';
            sort($expectedIndexes);
        }
        if ($actual !== $expectedIndexes) {
            throw new RuntimeException('Los índices de areas no coinciden con el esquema esperado.');
        }
        $definitions = [
            'areas_id_org_unique' => ['id', 'organization_id'],
            'areas_organization_idx' => ['organization_id'],
            'areas_plant_code_unique' => ['plant_id', 'code'],
            'areas_plant_org_idx' => ['plant_id', 'organization_id'],
        ];
        $indexList = [];
        foreach ($db->select('PRAGMA index_list("areas")') as $index) {
            $indexList[$index->name] = $index;
        }
        foreach ($indexes as $index) {
            $info = $db->select('PRAGMA index_info("' . $index->name . '")');
            $unique = in_array($index->name, ['areas_id_org_unique', 'areas_plant_code_unique'], true);
            if (
                array_map(fn($column) => $column->name, $info) !== $definitions[$index->name]
                || (int) $indexList[$index->name]->unique !== (int) $unique
                || (int) $indexList[$index->name]->partial !== 0
            ) {
                throw new RuntimeException('Definición de índice de areas inesperada.');
            }
        }

        $parentIndex = false;
        foreach ($db->select('PRAGMA index_list("plants")') as $index) {
            if ($index->name === 'plants_id_org_unique' && (int) $index->unique === 1 && (int) $index->partial === 0) {
                $info = $db->select('PRAGMA index_info("plants_id_org_unique")');
                $parentIndex = array_map(fn($column) => $column->name, $info) === ['id', 'organization_id'];
            }
        }
        if (!$parentIndex) {
            throw new RuntimeException('Falta el índice único esperado de plants.');
        }

        if ($db->selectOne('SELECT 1 FROM areas AS a LEFT JOIN plants AS p ON p.id = a.plant_id AND p.organization_id = a.organization_id WHERE a.plant_id IS NULL OR a.organization_id IS NULL OR p.id IS NULL LIMIT 1')) {
            throw new RuntimeException('Existen áreas con planta u organización inconsistente.');
        }

        $foreignKeys = $db->select('PRAGMA foreign_key_list("areas")');
        $groups = [];
        foreach ($foreignKeys as $fk) {
            $groups[$fk->id][] = [$fk->from, $fk->table, $fk->to, strtoupper($fk->on_update), strtoupper($fk->on_delete)];
        }
        $expectedForeignKeys = [
            [['organization_id', 'organizations', 'id', 'CASCADE', 'RESTRICT']],
            $up
            ? [['plant_id', 'plants', 'id', 'CASCADE', 'RESTRICT']]
            : [['plant_id', 'plants', 'id', 'RESTRICT', 'RESTRICT'], ['organization_id', 'plants', 'organization_id', 'RESTRICT', 'RESTRICT']],
        ];
        $actualForeignKeys = array_values($groups);
        sort($actualForeignKeys);
        sort($expectedForeignKeys);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException('Las FK de areas no coinciden con el esquema esperado.');
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
