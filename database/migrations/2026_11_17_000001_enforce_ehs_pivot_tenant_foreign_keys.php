<?php

use App\Support\Ehs\QuestionNormRequirementPreflight;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const PARENTS = [
        'questions' => 'ehs_questions_id_org_unique',
        'norm_requirements' => 'ehs_requirements_id_org_unique',
    ];

    private const FOREIGN_KEYS = [
        'question_id' => ['questions', 'ehs_qnr_question_org_fk'],
        'norm_requirement_id' => ['norm_requirements', 'ehs_qnr_requirement_org_fk'],
    ];

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        $this->change(false);
    }

    private function change(bool $up): void
    {
        $db = DB::connection($this->getConnection());
        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('DEC-17 requiere tablas sin prefijo ni transacción externa.');
        }
        if ($up) {
            QuestionNormRequirementPreflight::assertValid($db);
        }
        if ($db->getDriverName() === 'sqlite') {
            $this->sqlite($db, $up);

            return;
        }
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->mariaDb($db, $up);

            return;
        }
        throw new RuntimeException('DEC-17 solo admite SQLite y MariaDB 11.8.');
    }

    private function sqlite(Connection $db, bool $up): void
    {
        if ((int) $db->scalar('PRAGMA foreign_keys') !== 1
            || $db->select('PRAGMA foreign_key_check')
            || $db->scalar('PRAGMA integrity_check') !== 'ok'
            || $db->select("SELECT name FROM sqlite_master WHERE type = 'view'")) {
            throw new RuntimeException('DEC-17 requiere integridad SQLite y foreign_keys activado, sin vistas.');
        }
        $old = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'question_norm_requirement'");
        if (! $old || ! $old->sql || ! preg_match('/^create\s+table\s+"question_norm_requirement"\s*\(/i', $old->sql)) {
            throw new RuntimeException('Esquema del pivote DEC-17 inesperado.');
        }
        $clauses = $this->sqliteClauses();
        $has = str_contains($old->sql, $clauses);
        if ($has !== ! $up) {
            throw new RuntimeException('Estado parcial DEC-17; no se ejecutó DDL.');
        }
        foreach (self::PARENTS as $table => $index) {
            $row = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?", [$index]);
            if (($row !== null) !== ! $up) {
                throw new RuntimeException('Estado parcial de índices DEC-17; no se ejecutó DDL.');
            }
        }
        $columns = $db->select('PRAGMA table_xinfo("question_norm_requirement")');
        $names = array_map(fn ($column) => $column->name, $columns);
        $expected = ['id', 'organization_id', 'question_id', 'norm_requirement_id', 'created_at', 'updated_at'];
        sort($names);
        sort($expected);
        if ($names !== $expected || array_filter($columns, fn ($column) => (int) $column->hidden !== 0)
            || $db->selectOne("SELECT 1 FROM sqlite_master WHERE name = 'ehs_qnr_rebuild'")) {
            throw new RuntimeException('Columnas u objetos temporales DEC-17 inesperados.');
        }
        $objects = $db->select("SELECT sql FROM sqlite_master WHERE tbl_name = 'question_norm_requirement' AND type IN ('index','trigger') AND sql IS NOT NULL ORDER BY type,name");
        $sql = $up
            ? preg_replace('/\)\s*$/', $clauses.')', $old->sql, 1, $count)
            : str_replace($clauses, '', $old->sql, $count);
        if ($count !== 1) {
            throw new RuntimeException('No se pudo reconstruir el pivote DEC-17.');
        }
        $sql = preg_replace('/^create\s+table\s+"question_norm_requirement"\s*\(/i', 'CREATE TABLE "ehs_qnr_rebuild" (', $sql, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Definición DEC-17 inesperada.');
        }
        $quoted = implode(', ', array_map(fn ($column) => '"'.$column->name.'"', $columns));

        try {
            $db->statement('PRAGMA foreign_keys = OFF');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 0) {
                throw new RuntimeException('No se pudo desactivar foreign_keys.');
            }
            $db->transaction(function () use ($db, $up, $sql, $quoted, $objects): void {
                if ($up) {
                    foreach (self::PARENTS as $table => $index) {
                        $db->statement("CREATE UNIQUE INDEX \"$index\" ON \"$table\" (\"id\", \"organization_id\")");
                    }
                }
                $db->statement($sql);
                $db->statement("INSERT INTO \"ehs_qnr_rebuild\" ($quoted) SELECT $quoted FROM \"question_norm_requirement\"");
                $db->statement('DROP TABLE "question_norm_requirement"');
                $db->statement('ALTER TABLE "ehs_qnr_rebuild" RENAME TO "question_norm_requirement"');
                foreach ($objects as $object) {
                    $db->statement($object->sql);
                }
                if (! $up) {
                    foreach (self::PARENTS as $index) {
                        $db->statement("DROP INDEX \"$index\"");
                    }
                }
                if ($db->select('PRAGMA foreign_key_check') || $db->scalar('PRAGMA integrity_check') !== 'ok') {
                    throw new RuntimeException('DEC-17 detectó pérdida de integridad.');
                }
            });
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
                throw new RuntimeException('No se pudo restaurar foreign_keys.');
            }
        }
    }

    private function sqliteClauses(): string
    {
        return ', constraint "ehs_qnr_question_org_fk" foreign key ("question_id", "organization_id") references "questions" ("id", "organization_id") on delete restrict on update cascade'
            .', constraint "ehs_qnr_requirement_org_fk" foreign key ("norm_requirement_id", "organization_id") references "norm_requirements" ("id", "organization_id") on delete restrict on update cascade';
    }

    private function mariaDb(Connection $db, bool $up): void
    {
        $server = $db->selectOne('SELECT VERSION() version, DATABASE() schema_name, @@SESSION.foreign_key_checks fk_checks', [], false);
        if (! $server || ! preg_match('/^(?:5\.5\.5-)?11\.8\.\d+.*MariaDB/i', $server->version)
            || ! $server->schema_name || (int) $server->fk_checks !== 1) {
            throw new RuntimeException('DEC-17 requiere MariaDB 11.8 y foreign_key_checks activado.');
        }
        foreach (self::PARENTS as $table => $index) {
            $exists = $db->table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $server->schema_name)->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $index)->exists();
            if ($exists !== ! $up) {
                throw new RuntimeException('Estado parcial de índices DEC-17; intervención manual necesaria.');
            }
        }
        foreach (self::FOREIGN_KEYS as [, $name]) {
            $exists = $db->table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $server->schema_name)
                ->where('TABLE_NAME', 'question_norm_requirement')->where('CONSTRAINT_NAME', $name)
                ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')->exists();
            if ($exists !== ! $up) {
                throw new RuntimeException('Estado parcial de FK DEC-17; intervención manual necesaria.');
            }
        }
        // Cada ALTER confirma por separado; un fallo deja un estado parcial
        // intencionalmente bloqueado para inspección y recuperación supervisada.
        if ($up) {
            foreach (self::PARENTS as $table => $index) {
                $db->statement("ALTER TABLE `$table` ADD UNIQUE KEY `$index` (`id`, `organization_id`)");
            }
            foreach (self::FOREIGN_KEYS as $column => [$table, $name]) {
                $db->statement("ALTER TABLE `question_norm_requirement` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`, `organization_id`) REFERENCES `$table` (`id`, `organization_id`) ON DELETE RESTRICT ON UPDATE CASCADE");
            }
        } else {
            foreach (self::FOREIGN_KEYS as [, $name]) {
                $db->statement("ALTER TABLE `question_norm_requirement` DROP FOREIGN KEY `$name`");
            }
            foreach (self::PARENTS as $table => $index) {
                $db->statement("ALTER TABLE `$table` DROP INDEX `$index`");
            }
        }
    }
};
