<?php

namespace App\Support\Ehs;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Reconstrucción SQLite de las dos tablas EHS. Nunca dentro de una transacción
 * externa: DROP TABLE con foreign_keys activo puede borrar filas hijas.
 */
final class SqliteOrderRebuild
{
    private const TABLES = [
        'checklist_sections' => [
            'columns' => ['id', 'checklist_version_id', 'name', 'description', 'display_order', 'created_at', 'updated_at', 'organization_id'],
            'parent' => 'checklist_version_id',
            'index' => 'sections_version_order_unique',
            'check' => 'sections_display_order_positive',
        ],
        'questions' => [
            'columns' => ['id', 'checklist_section_id', 'question_text', 'description', 'answer_type', 'display_order', 'required', 'allows_evidence', 'status', 'created_at', 'updated_at', 'organization_id', 'options'],
            'parent' => 'checklist_section_id',
            'index' => 'questions_section_order_unique',
            'check' => 'questions_display_order_positive',
        ],
    ];

    /**
     * Cada cambio: table; opcionalmente default=[anterior,nuevo],
     * index=add|drop y check=add|drop.
     */
    public static function run(Connection $db, array $changes): void
    {
        if ($db->getDriverName() !== 'sqlite' || $db->getTablePrefix() !== ''
            || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('EHS requiere SQLite sin prefijo ni transacción externa.');
        }
        if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('EHS requiere foreign_keys activo.');
        }

        try {
            $db->statement('PRAGMA foreign_keys = OFF');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 0) {
                throw new RuntimeException('No se pudo desactivar foreign_keys.');
            }
            $db->transaction(function () use ($db, $changes): void {
                self::integrity($db);
                // Las vistas pueden referenciar la tabla durante ALTER TABLE.
                if ($db->select("SELECT name FROM sqlite_master WHERE type = 'view'")) {
                    throw new RuntimeException('EHS no reconstruye esquemas con vistas.');
                }
                $plans = [];
                foreach ($changes as $change) {
                    $table = $change['table'] ?? '';
                    if (! isset(self::TABLES[$table]) || isset($plans[$table])
                        || array_diff(array_keys($change), ['table', 'default', 'index', 'check'])) {
                        throw new RuntimeException('Cambio de esquema EHS no reconocido.');
                    }
                    $plans[$table] = self::plan($db, $table, $change);
                }
                if ($plans === []) {
                    throw new RuntimeException('No se especificaron cambios EHS.');
                }
                foreach ($plans as $plan) {
                    [$table, $temporary, $sql, $columns, $objects, $indexSql] = $plan;
                    $db->statement($sql);
                    $db->statement("INSERT INTO \"$temporary\" ($columns) SELECT $columns FROM \"$table\"");
                    $db->statement("DROP TABLE \"$table\"");
                    $db->statement("ALTER TABLE \"$temporary\" RENAME TO \"$table\"");
                    foreach ($objects as $object) {
                        $db->statement($object->sql);
                    }
                    if ($indexSql !== null) {
                        $db->statement($indexSql);
                    }
                }
                self::integrity($db);
            });
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
                throw new RuntimeException('No se pudo restaurar foreign_keys.');
            }
        }
    }

    private static function plan(Connection $db, string $table, array $change): array
    {
        $spec = self::TABLES[$table];
        $temporary = 'ehs_rebuild_'.$table;
        if ($db->selectOne('SELECT 1 FROM sqlite_master WHERE name = ?', [$temporary])) {
            throw new RuntimeException('Ya existe el objeto temporal EHS.');
        }
        $columns = $db->select("PRAGMA table_xinfo(\"$table\")");
        $names = array_map(fn ($column) => $column->name, $columns);
        $expected = $spec['columns'];
        sort($names);
        sort($expected);
        if ($names !== $expected || array_filter($columns, fn ($column) => (int) $column->hidden !== 0)) {
            throw new RuntimeException('Columnas EHS inesperadas.');
        }
        $foreignKeys = $db->select("PRAGMA foreign_key_list(\"$table\")");
        $actualForeignKeys = array_map(
            fn ($fk) => [$fk->from, $fk->table, $fk->to, strtoupper($fk->on_update), strtoupper($fk->on_delete), (int) $fk->seq],
            $foreignKeys
        );
        $expectedForeignKeys = [
            ['organization_id', 'organizations', 'id', 'CASCADE', 'RESTRICT', 0],
            [$spec['parent'], $table === 'questions' ? 'checklist_sections' : 'checklist_versions', 'id', 'CASCADE', 'RESTRICT', 0],
        ];
        sort($actualForeignKeys);
        sort($expectedForeignKeys);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException('Claves foráneas EHS inesperadas.');
        }
        $definition = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        if (! $definition || ! $definition->sql) {
            throw new RuntimeException('Falta la definición de tabla EHS.');
        }
        $sql = $definition->sql;
        $pattern = '/("display_order"\s+integer\s+not\s+null\s+default\s+)(?:\(\s*\'([01])\'\s*\)|\'([01])\'|([01]))(?=\s*[,)]|\\s+constraint\\b)/i';
        if (! preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER) || count($matches) !== 1) {
            throw new RuntimeException('Definición display_order EHS inesperada.');
        }
        if (isset($change['default'])) {
            if (! is_array($change['default']) || count($change['default']) !== 2
                || ! in_array($change['default'], [[0, 1], [1, 0]], true)) {
                throw new RuntimeException('Cambio de default EHS inesperado.');
            }
            [$from, $to] = $change['default'];
            $actual = collect(array_slice($matches[0], 2))->first(fn ($value) => $value !== '');
            if ((string) $from !== $actual) {
                throw new RuntimeException('Default display_order EHS inesperado.');
            }
            $sql = preg_replace_callback($pattern, fn ($m) => $m[1]."'$to'", $sql, 1, $count);
            if ($count !== 1) {
                throw new RuntimeException('No se pudo cambiar el default EHS.');
            }
        }
        $check = ', constraint "'.$spec['check'].'" check ("display_order" >= 1)';
        $hasCheck = str_contains(strtolower($sql), strtolower($check));
        if (isset($change['check'])) {
            if (! in_array($change['check'], ['add', 'drop'], true) || $hasCheck !== ($change['check'] === 'drop')) {
                throw new RuntimeException('CHECK EHS inesperado.');
            }
            if ($change['check'] === 'add') {
                if ($db->table($table)->where('display_order', '<', 1)->exists()) {
                    throw new RuntimeException('Existen órdenes menores que 1.');
                }
                $sql = preg_replace('/\)\s*$/', $check.')', $sql, 1, $count);
            } else {
                $sql = str_ireplace($check, '', $sql, $count);
            }
            if ($count !== 1) {
                throw new RuntimeException('No se pudo modificar el CHECK EHS.');
            }
        }
        $objects = $db->select("SELECT type, name, sql FROM sqlite_master WHERE tbl_name = ? AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name", [$table]);
        $indexSql = null;
        if (isset($change['index'])) {
            $action = $change['index'];
            if (! in_array($action, ['add', 'drop'], true)) {
                throw new RuntimeException('Índice EHS inesperado.');
            }
            $matchesIndex = array_values(array_filter($objects, fn ($object) => $object->name === $spec['index'] && $object->type === 'index'));
            if (count($matchesIndex) !== ($action === 'drop' ? 1 : 0)
                || ($action === 'add' && $db->selectOne('SELECT 1 FROM sqlite_master WHERE name = ?', [$spec['index']]))) {
                throw new RuntimeException('Estado del índice EHS inesperado.');
            }
            if ($action === 'drop') {
                $indexInfo = $db->select('PRAGMA index_info("'.$spec['index'].'")');
                $indexList = $db->select("PRAGMA index_list(\"$table\")");
                $indexMeta = array_values(array_filter($indexList, fn ($index) => $index->name === $spec['index']));
                if (array_map(fn ($column) => $column->name, $indexInfo) !== [$spec['parent'], 'display_order']
                    || count($indexMeta) !== 1 || (int) $indexMeta[0]->unique !== 1 || (int) $indexMeta[0]->partial !== 0) {
                    throw new RuntimeException('Definición de índice EHS inesperada.');
                }
                $objects = array_values(array_filter($objects, fn ($object) => $object->name !== $spec['index']));
            } else {
                $indexSql = 'CREATE UNIQUE INDEX "'.$spec['index'].'" ON "'.$table.'" ("'.$spec['parent'].'", "display_order")';
            }
        }
        $sql = preg_replace('/^create\s+table\s+"'.preg_quote($table, '/').'"\s*\(/i', 'CREATE TABLE "'.$temporary.'" (', $sql, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('DDL de tabla EHS inesperado.');
        }
        $columnSql = implode(', ', array_map(fn ($column) => '"'.$column->name.'"', $columns));

        return [$table, $temporary, $sql, $columnSql, $objects, $indexSql];
    }

    private static function integrity(Connection $db): void
    {
        if ($db->select('PRAGMA foreign_key_check') || $db->scalar('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('Integridad SQLite EHS inválida.');
        }
    }
}
