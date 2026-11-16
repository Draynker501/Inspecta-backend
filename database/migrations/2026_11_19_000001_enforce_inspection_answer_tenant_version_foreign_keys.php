<?php

use App\Support\Inspections\InspectionAnswerPreflight;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INS-02 — integridad de `inspection_answers`.
 *
 * Añade la versión y la sección de la pregunta a cada respuesta y sustituye
 * las FK simples por tres FK compuestas que garantizan, de forma estructural:
 *
 * 1. La respuesta y su inspección comparten organización y versión.
 * 2. La sección pertenece a la versión de la inspección.
 * 3. La pregunta pertenece a esa sección y organización.
 *
 * Preserva el unique (inspection_id, question_id), la FK entrante de
 * `evidences`, la FK a `organizations`, los datos y las acciones
 * referenciales históricas: inspección CASCADE en eliminación/actualización;
 * pregunta y sección RESTRICT en eliminación y CASCADE en actualización.
 *
 * No repara datos: si el preflight detecta respuestas huérfanas, de otra
 * organización o de una versión distinta, aborta antes de ejecutar DDL.
 * Un estado parcial de una ejecución anterior se rechaza y requiere
 * recuperación supervisada.
 *
 * La conmutación de FK (`DROP` de las simples y `ADD` de las compuestas) se
 * ejecuta en un único `ALTER TABLE`: MariaDB 11.8 aplica DDL atómico, por lo
 * que no existe una ventana sin FK entre ambas operaciones. La reversión usa
 * el mismo patrón agrupado.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Orden de columnas de SQLite y MariaDB antes de INS-02.
     *
     * @var list<string>
     */
    private const OLD_COLUMNS = [
        'id', 'inspection_id', 'question_id', 'answer', 'observation',
        'created_at', 'updated_at', 'organization_id',
    ];

    /** @var list<string> */
    private const NEW_COLUMNS = [
        'id', 'inspection_id', 'question_id', 'answer', 'observation',
        'created_at', 'updated_at', 'organization_id',
        'checklist_version_id', 'checklist_section_id',
    ];

    /** @var list<string> */
    private const OLD_MARIADB_ORDER = [
        'id', 'organization_id', 'inspection_id', 'question_id', 'answer',
        'observation', 'created_at', 'updated_at',
    ];

    /**
     * Índices candidatos en las tablas padre. Son necesarios para que el
     * motor acepte la FK compuesta (izquierda: id).
     *
     * @var array<int, array{0: string, 1: string, 2: array<int, string>}>
     */
    private const PARENT_INDEXES = [
        ['inspections', 'ins_answers_insp_version_org_unique', ['id', 'checklist_version_id', 'organization_id']],
        ['checklist_sections', 'ins_answers_sect_version_org_unique', ['id', 'checklist_version_id', 'organization_id']],
        ['questions', 'ins_answers_quest_sect_org_unique', ['id', 'checklist_section_id', 'organization_id']],
    ];

    /**
     * Índices de la tabla hija para MariaDB (SQLite no los exige).
     *
     * @var array<int, array{0: string, 1: string, 2: array<int, string>}>
     */
    private const CHILD_INDEXES = [
        ['inspection_answers', 'ins_answers_insp_version_org_idx', ['inspection_id', 'checklist_version_id', 'organization_id']],
        ['inspection_answers', 'ins_answers_sect_version_org_idx', ['checklist_section_id', 'checklist_version_id', 'organization_id']],
        ['inspection_answers', 'ins_answers_quest_sect_org_idx', ['question_id', 'checklist_section_id', 'organization_id']],
    ];

    /**
     * @var array<int, array{0: string, 1: array<int, string>, 2: string, 3: array<int, string>, 4: string, 5: string}>
     */
    private const COMPOSITE_FOREIGN_KEYS = [
        ['inspection_id', ['inspection_id', 'checklist_version_id', 'organization_id'], 'inspections', ['id', 'checklist_version_id', 'organization_id'], 'ins_answers_insp_version_org_fk', 'CASCADE'],
        ['checklist_section_id', ['checklist_section_id', 'checklist_version_id', 'organization_id'], 'checklist_sections', ['id', 'checklist_version_id', 'organization_id'], 'ins_answers_sect_version_org_fk', 'RESTRICT'],
        ['question_id', ['question_id', 'checklist_section_id', 'organization_id'], 'questions', ['id', 'checklist_section_id', 'organization_id'], 'ins_answers_quest_sect_org_fk', 'RESTRICT'],
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

        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->mariaDb($db, $up);

            return;
        }

        if ($db->getDriverName() !== 'sqlite') {
            throw new RuntimeException('INS-02 solo admite SQLite o MariaDB 11.8; no se ejecutó DDL.');
        }

        $this->sqlite($db, $up);
    }

    // -----------------------------------------------------------------
    // SQLite
    // -----------------------------------------------------------------

    private function sqlite(Connection $db, bool $up): void
    {
        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('INS-02 requiere tablas sin prefijo y ninguna transacción externa.');
        }

        if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('INS-02 requiere foreign_keys activado.');
        }

        $this->assertSqliteState($db, ! $up);

        if ($up) {
            InspectionAnswerPreflight::assertValid($db);
        }

        try {
            $db->statement('PRAGMA foreign_keys = OFF');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 0) {
                throw new RuntimeException('INS-02 no pudo desactivar foreign_keys.');
            }

            $db->transaction(function () use ($db, $up): void {
                if ($db->select("SELECT name FROM sqlite_master WHERE type IN ('view', 'trigger') OR name = 'ins_answers_rebuild'")) {
                    throw new RuntimeException('INS-02 rechaza vistas, triggers u objeto temporal existente.');
                }

                $before = (int) $db->scalar('SELECT COUNT(*) FROM "inspection_answers"');
                // Se preservan los índices ajenos a INS-02. Los dos índices
                // históricos se recrean explícitamente después del renombre.
                $foreignIndexes = $db->select(
                    "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'inspection_answers' "
                    ."AND sql IS NOT NULL AND name NOT IN ('inspection_answers_inspection_question_unique', 'inspection_answers_organization_idx') "
                    .'ORDER BY name'
                );

                if ($up) {
                    foreach (self::PARENT_INDEXES as [$table, $index, $fields]) {
                        $quoted = implode(', ', array_map(fn ($field) => '"'.$field.'"', $fields));
                        $db->statement("CREATE UNIQUE INDEX \"$index\" ON \"$table\" ($quoted)");
                    }
                }

                $db->statement($up ? $this->sqliteNewDdl() : $this->sqliteOldDdl());

                if ($up) {
                    $db->statement(
                        'INSERT INTO "ins_answers_rebuild" ("id", "inspection_id", "question_id", "answer", "observation", "created_at", "updated_at", "organization_id", "checklist_version_id", "checklist_section_id") '
                        .'SELECT a."id", a."inspection_id", a."question_id", a."answer", a."observation", a."created_at", a."updated_at", a."organization_id", s."checklist_version_id", q."checklist_section_id" '
                        .'FROM "inspection_answers" a '
                        .'JOIN "questions" q ON q."id" = a."question_id" AND q."organization_id" = a."organization_id" '
                        .'JOIN "checklist_sections" s ON s."id" = q."checklist_section_id" AND s."organization_id" = q."organization_id" '
                        .'JOIN "inspections" i ON i."id" = a."inspection_id" AND i."organization_id" = a."organization_id" '
                        .'AND i."checklist_version_id" = s."checklist_version_id"'
                    );
                } else {
                    $db->statement(
                        'INSERT INTO "ins_answers_rebuild" ("id", "inspection_id", "question_id", "answer", "observation", "created_at", "updated_at", "organization_id") '
                        .'SELECT "id", "inspection_id", "question_id", "answer", "observation", "created_at", "updated_at", "organization_id" FROM "inspection_answers"'
                    );
                }

                $after = (int) $db->scalar('SELECT COUNT(*) FROM "ins_answers_rebuild"');
                if ($before !== $after) {
                    throw new RuntimeException('INS-02 detectó pérdida de filas durante la reconstrucción; se revierte.');
                }

                $db->statement('DROP TABLE "inspection_answers"');
                $db->statement('ALTER TABLE "ins_answers_rebuild" RENAME TO "inspection_answers"');

                $db->statement('CREATE UNIQUE INDEX "inspection_answers_inspection_question_unique" ON "inspection_answers" ("inspection_id", "question_id")');
                $db->statement('CREATE INDEX "inspection_answers_organization_idx" ON "inspection_answers" ("organization_id")');
                foreach ($foreignIndexes as $index) {
                    $db->statement($index->sql);
                }

                if (! $up) {
                    foreach (self::PARENT_INDEXES as [, $index]) {
                        $db->statement("DROP INDEX \"$index\"");
                    }
                }

                if ($db->select('PRAGMA foreign_key_check') || $db->scalar('PRAGMA integrity_check') !== 'ok') {
                    throw new RuntimeException('INS-02 detectó pérdida de integridad.');
                }
            });
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
            if ((int) $db->scalar('PRAGMA foreign_keys') !== 1) {
                throw new RuntimeException('INS-02 no pudo restaurar foreign_keys.');
            }
        }

        $this->assertSqliteState($db, $up);
    }

    private function assertSqliteState(Connection $db, bool $up): void
    {
        $expectedColumns = $up ? self::NEW_COLUMNS : self::OLD_COLUMNS;
        $columns = $db->select('PRAGMA table_xinfo("inspection_answers")');
        $names = array_map(fn ($column) => $column->name, $columns);
        if ($names !== $expectedColumns
            || array_filter($columns, fn ($column) => (int) $column->hidden !== 0)) {
            throw new RuntimeException('INS-02: columnas inesperadas o estado parcial en inspection_answers.');
        }

        if ($this->sqliteForeignKeySignatures($db, 'inspection_answers') !== $this->sqliteExpectedForeignKeys($up)) {
            throw new RuntimeException('INS-02: FK inesperadas o estado parcial en inspection_answers.');
        }

        $indexNames = array_map(
            fn ($index) => $index->name,
            $db->select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'inspection_answers' AND sql IS NOT NULL")
        );
        foreach (['inspection_answers_inspection_question_unique', 'inspection_answers_organization_idx'] as $required) {
            if (! in_array($required, $indexNames, true)) {
                throw new RuntimeException('INS-02: índices requeridos ausentes en inspection_answers.');
            }
        }
        if (! $this->sqliteIndexMatches($db, 'inspection_answers', 'inspection_answers_inspection_question_unique', ['inspection_id', 'question_id'], true)
            || ! $this->sqliteIndexMatches($db, 'inspection_answers', 'inspection_answers_organization_idx', ['organization_id'], false)) {
            throw new RuntimeException('INS-02: definición de índices inesperada en inspection_answers.');
        }

        foreach (self::PARENT_INDEXES as [$table, $index, $fields]) {
            $present = $this->sqliteIndexMatches($db, $table, $index, $fields, true);
            if ($present !== $up) {
                throw new RuntimeException("INS-02: estado inesperado del índice $index.");
            }
        }

        if (! in_array(
            'inspection_answers:inspection_answer_id->id:RESTRICT/CASCADE',
            $this->sqliteForeignKeySignatures($db, 'evidences'),
            true,
        )) {
            throw new RuntimeException('INS-02 requiere la FK entrante de evidences hacia inspection_answers.');
        }
    }

    /**
     * @return list<string>
     */
    private function sqliteExpectedForeignKeys(bool $up): array
    {
        $keys = ['organizations:organization_id->id:RESTRICT/CASCADE'];

        if ($up) {
            foreach (self::COMPOSITE_FOREIGN_KEYS as [, $child, $parent, $parentFields, , $delete]) {
                $keys[] = $parent.':'.implode(',', $child).'->'.implode(',', $parentFields).':'.$delete.'/CASCADE';
            }
        } else {
            $keys[] = 'inspections:inspection_id->id:CASCADE/CASCADE';
            $keys[] = 'questions:question_id->id:RESTRICT/CASCADE';
        }

        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function sqliteForeignKeySignatures(Connection $db, string $table): array
    {
        $rows = $db->select('PRAGMA foreign_key_list("'.$table.'")');
        usort($rows, fn ($a, $b) => [$a->id, $a->seq] <=> [$b->id, $b->seq]);

        $grouped = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $grouped[$id]['table'] = $row->table;
            $grouped[$id]['from'][] = $row->from;
            $grouped[$id]['to'][] = $row->to;
            $grouped[$id]['on_update'] = $row->on_update;
            $grouped[$id]['on_delete'] = $row->on_delete;
        }

        $signatures = [];
        foreach ($grouped as $group) {
            $signatures[] = sprintf(
                '%s:%s->%s:%s/%s',
                $group['table'],
                implode(',', $group['from']),
                implode(',', $group['to']),
                $group['on_delete'],
                $group['on_update'],
            );
        }
        sort($signatures);

        return $signatures;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function sqliteIndexMatches(Connection $db, string $table, string $name, array $fields, bool $unique): bool
    {
        $list = $db->select('PRAGMA index_list("'.$table.'")');
        $match = array_values(array_filter(
            $list,
            fn ($index) => $index->name === $name && (int) $index->unique === ($unique ? 1 : 0) && (int) $index->partial === 0,
        ));

        if (count($match) !== 1) {
            return false;
        }

        $info = $db->select('PRAGMA index_info("'.$name.'")');
        usort($info, fn ($a, $b) => (int) $a->seqno <=> (int) $b->seqno);

        return array_map(fn ($column) => $column->name, $info) === $fields;
    }

    private function sqliteOldDdl(): string
    {
        return 'CREATE TABLE "ins_answers_rebuild" ('
            .'"id" varchar not null, "inspection_id" varchar not null, "question_id" varchar not null, '
            .'"answer" text, "observation" text, "created_at" datetime, "updated_at" datetime, '
            .'"organization_id" varchar not null, primary key ("id"), '
            .'foreign key("question_id") references "questions"("id") on delete restrict on update cascade, '
            .'foreign key("inspection_id") references "inspections"("id") on delete cascade on update cascade, '
            .'foreign key("organization_id") references "organizations"("id") on delete restrict on update cascade)';
    }

    private function sqliteNewDdl(): string
    {
        return 'CREATE TABLE "ins_answers_rebuild" ('
            .'"id" varchar not null, "inspection_id" varchar not null, "question_id" varchar not null, '
            .'"answer" text, "observation" text, "created_at" datetime, "updated_at" datetime, '
            .'"organization_id" varchar not null, "checklist_version_id" varchar not null, '
            .'"checklist_section_id" varchar not null, primary key ("id"), '
            .'constraint "ins_answers_insp_version_org_fk" foreign key("inspection_id", "checklist_version_id", "organization_id") '
            .'references "inspections"("id", "checklist_version_id", "organization_id") on delete cascade on update cascade, '
            .'constraint "ins_answers_sect_version_org_fk" foreign key("checklist_section_id", "checklist_version_id", "organization_id") '
            .'references "checklist_sections"("id", "checklist_version_id", "organization_id") on delete restrict on update cascade, '
            .'constraint "ins_answers_quest_sect_org_fk" foreign key("question_id", "checklist_section_id", "organization_id") '
            .'references "questions"("id", "checklist_section_id", "organization_id") on delete restrict on update cascade, '
            .'foreign key("organization_id") references "organizations"("id") on delete restrict on update cascade)';
    }

    // -----------------------------------------------------------------
    // MariaDB
    // -----------------------------------------------------------------

    private function mariaDb(Connection $db, bool $up): void
    {
        if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('INS-02 requiere tablas sin prefijo y ninguna transacción externa.');
        }

        $server = $db->selectOne('SELECT VERSION() version, DATABASE() schema_name, @@SESSION.foreign_key_checks fk_checks', [], false);
        if (! $server || ! preg_match('/^(?:5\.5\.5-)?11\.8\.\d+.*MariaDB/i', $server->version)
            || ! $server->schema_name || (int) $server->fk_checks !== 1) {
            throw new RuntimeException('INS-02 requiere MariaDB 11.8 y foreign_key_checks activado.');
        }

        $schema = $server->schema_name;
        foreach (['inspection_answers', 'inspections', 'checklist_sections', 'questions', 'evidences', 'organizations'] as $table) {
            $row = $db->selectOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = ?',
                [$schema, $table, 'BASE TABLE'],
                false,
            );
            if (! $row || strtoupper($row->ENGINE) !== 'INNODB') {
                throw new RuntimeException("INS-02 requiere tabla InnoDB $table.");
            }
        }

        $this->assertMariaDbState($db, $schema, ! $up);

        if ($up) {
            InspectionAnswerPreflight::assertValid($db);
        }

        $quote = fn (array $fields): string => implode(', ', array_map(fn ($field) => '`'.$field.'`', $fields));

        if ($up) {
            $db->statement('ALTER TABLE `inspection_answers` ADD COLUMN `checklist_version_id` CHAR(36) NULL, ADD COLUMN `checklist_section_id` CHAR(36) NULL');
            $db->statement(
                'UPDATE `inspection_answers` AS a '
                .'JOIN `inspections` AS i ON i.`id` = a.`inspection_id` AND i.`organization_id` = a.`organization_id` '
                .'JOIN `questions` AS q ON q.`id` = a.`question_id` AND q.`organization_id` = a.`organization_id` '
                .'JOIN `checklist_sections` AS s ON s.`id` = q.`checklist_section_id` AND s.`organization_id` = q.`organization_id` '
                .'SET a.`checklist_version_id` = s.`checklist_version_id`, a.`checklist_section_id` = q.`checklist_section_id` '
                .'WHERE i.`checklist_version_id` = s.`checklist_version_id`'
            );

            if ((int) $db->scalar('SELECT COUNT(*) FROM `inspection_answers` WHERE `checklist_version_id` IS NULL OR `checklist_section_id` IS NULL') !== 0) {
                throw new RuntimeException('INS-02 no pudo completar versión y sección en todas las respuestas.');
            }

            $db->statement('ALTER TABLE `inspection_answers` MODIFY `checklist_version_id` CHAR(36) NOT NULL, MODIFY `checklist_section_id` CHAR(36) NOT NULL');

            foreach (self::PARENT_INDEXES as [$table, $name, $fields]) {
                $db->statement("ALTER TABLE `$table` ADD UNIQUE KEY `$name` (".$quote($fields).')');
            }
            foreach (self::CHILD_INDEXES as [$table, $name, $fields]) {
                $db->statement("ALTER TABLE `$table` ADD INDEX `$name` (".$quote($fields).')');
            }

            // Conmutación de FK en un único ALTER TABLE. MariaDB 11.8 aplica
            // DDL atómico: si alguna cláusula falla se revierte la sentencia
            // completa y no queda una ventana sin FK entre el DROP y el ADD.
            $addComposite = [];
            foreach (self::COMPOSITE_FOREIGN_KEYS as [, $child, $parent, $parentFields, $name, $delete]) {
                $addComposite[] = "ADD CONSTRAINT `$name` FOREIGN KEY (".$quote($child).') '
                    ."REFERENCES `$parent` (".$quote($parentFields).') '
                    ."ON DELETE $delete ON UPDATE CASCADE";
            }

            $db->statement(
                'ALTER TABLE `inspection_answers` '
                .'DROP FOREIGN KEY `inspection_answers_inspection_id_foreign`, '
                .'DROP FOREIGN KEY `inspection_answers_question_id_foreign`, '
                .implode(', ', $addComposite)
            );
        } else {
            // Reversión en un único ALTER TABLE: se retiran las FK compuestas y
            // los índices hijos, y se restauran las FK simples de forma atómica.
            // InnoDB vuelve a crear los índices históricos que necesiten las FK
            // simples cuando no exista uno equivalente.
            $dropComposite = [];
            foreach (self::COMPOSITE_FOREIGN_KEYS as [, , , , $name]) {
                $dropComposite[] = "DROP FOREIGN KEY `$name`";
            }

            $dropChild = [];
            foreach (self::CHILD_INDEXES as [$table, $name]) {
                $dropChild[] = "DROP INDEX `$name`";
            }

            $db->statement(
                'ALTER TABLE `inspection_answers` '
                .implode(', ', array_merge($dropComposite, $dropChild)).', '
                .'ADD CONSTRAINT `inspection_answers_inspection_id_foreign` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE, '
                .'ADD CONSTRAINT `inspection_answers_question_id_foreign` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE'
            );

            foreach (self::PARENT_INDEXES as [$table, $name]) {
                $db->statement("ALTER TABLE `$table` DROP INDEX `$name`");
            }

            $db->statement('ALTER TABLE `inspection_answers` DROP COLUMN `checklist_version_id`, DROP COLUMN `checklist_section_id`');
        }

        $this->assertMariaDbState($db, $schema, $up);
    }

    private function assertMariaDbState(Connection $db, string $schema, bool $up): void
    {
        $columns = $db->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$schema, 'inspection_answers'],
            false,
        );
        $names = array_map(fn ($column) => $column->COLUMN_NAME, $columns);
        $expected = $up
            ? array_merge(self::OLD_MARIADB_ORDER, ['checklist_version_id', 'checklist_section_id'])
            : self::OLD_MARIADB_ORDER;

        if ($names !== $expected) {
            throw new RuntimeException('INS-02: columnas inesperadas o estado parcial en inspection_answers.');
        }

        foreach ($columns as $column) {
            if ($column->COLUMN_NAME === 'checklist_version_id' || $column->COLUMN_NAME === 'checklist_section_id') {
                if ($column->COLUMN_TYPE !== 'char(36)' || $column->IS_NULLABLE !== 'NO') {
                    throw new RuntimeException('INS-02: columna de integridad incompatible en inspection_answers.');
                }

                continue;
            }

            if (in_array($column->COLUMN_NAME, ['id', 'organization_id', 'inspection_id', 'question_id'], true)) {
                if ($column->COLUMN_TYPE !== 'char(36)' || $column->IS_NULLABLE !== 'NO') {
                    throw new RuntimeException("INS-02: columna incompatible {$column->COLUMN_NAME}.");
                }
            }
        }

        foreach (self::PARENT_INDEXES as [$table, $name, $fields]) {
            if ($this->mariaDbIndexMatches($this->mariaDbIndex($db, $schema, $table, $name), $fields, true) !== $up) {
                throw new RuntimeException("INS-02: estado inesperado del índice $name.");
            }
        }

        foreach (self::CHILD_INDEXES as [$table, $name, $fields]) {
            if ($this->mariaDbIndexMatches($this->mariaDbIndex($db, $schema, $table, $name), $fields, false) !== $up) {
                throw new RuntimeException("INS-02: estado inesperado del índice $name.");
            }
        }

        $expectedForeignKeys = ['inspection_answers_organization_id_foreign'];
        if ($up) {
            foreach (self::COMPOSITE_FOREIGN_KEYS as [, $child, $parent, $parentFields, $name, $delete]) {
                $this->assertMariaDbForeignKey(
                    $this->mariaDbForeignKey($db, $schema, 'inspection_answers', $name),
                    $child,
                    $parent,
                    $parentFields,
                    $delete,
                    $name,
                );
                $expectedForeignKeys[] = $name;
            }
        }

        $simple = [
            'inspection_answers_inspection_id_foreign' => ['inspection_id', 'inspections', 'CASCADE'],
            'inspection_answers_question_id_foreign' => ['question_id', 'questions', 'RESTRICT'],
        ];
        foreach ($simple as $name => [$column, $parent, $delete]) {
            $rows = $this->mariaDbForeignKey($db, $schema, 'inspection_answers', $name);
            if (! $up) {
                $this->assertMariaDbForeignKey($rows, [$column], $parent, ['id'], $delete, $name);
                $expectedForeignKeys[] = $name;

                continue;
            }

            if ($rows !== []) {
                throw new RuntimeException("INS-02: estado inesperado de la FK $name.");
            }
        }

        $actualForeignKeys = array_map(
            fn ($row) => $row->CONSTRAINT_NAME,
            $db->select(
                'SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
                .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$schema, 'inspection_answers'],
                false,
            ),
        );
        sort($actualForeignKeys, SORT_STRING);
        sort($expectedForeignKeys, SORT_STRING);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException('INS-02: FK inesperadas en inspection_answers.');
        }

        $this->assertMariaDbForeignKey(
            $this->mariaDbForeignKey($db, $schema, 'inspection_answers', 'inspection_answers_organization_id_foreign'),
            ['organization_id'],
            'organizations',
            ['id'],
            'RESTRICT',
            'inspection_answers_organization_id_foreign',
        );
        $this->assertMariaDbForeignKey(
            $this->mariaDbForeignKey($db, $schema, 'evidences', 'evidences_inspection_answer_id_foreign'),
            ['inspection_answer_id'],
            'inspection_answers',
            ['id'],
            'RESTRICT',
            'evidences_inspection_answer_id_foreign',
        );
    }

    /**
     * @return array<int, object>|null
     */
    private function mariaDbIndex(Connection $db, string $schema, string $table, string $name): ?array
    {
        $rows = $db->select(
            'SELECT COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$schema, $table, $name],
            false,
        );

        return $rows === [] ? null : $rows;
    }

    /**
     * @param  array<int, object>|null  $rows
     * @param  array<int, string>  $fields
     */
    private function mariaDbIndexMatches(?array $rows, array $fields, bool $unique): bool
    {
        if ($rows === null) {
            return false;
        }

        $expected = $unique ? 0 : 1;
        foreach ($rows as $row) {
            if ((int) $row->NON_UNIQUE !== $expected || $row->SUB_PART !== null) {
                return false;
            }
        }

        return array_map(fn ($row) => $row->COLUMN_NAME, $rows) === $fields;
    }

    /**
     * @return array<int, object>
     */
    private function mariaDbForeignKey(Connection $db, string $schema, string $table, string $name): array
    {
        return $db->select(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE '
            .'FROM information_schema.KEY_COLUMN_USAGE k '
            .'JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
            .'AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            .'WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION',
            [$schema, $table, $name],
            false,
        );
    }

    /**
     * @param  array<int, object>  $rows
     * @param  array<int, string>  $child
     * @param  array<int, string>  $parentFields
     */
    private function assertMariaDbForeignKey(array $rows, array $child, string $parent, array $parentFields, string $delete, string $name): void
    {
        if ($rows === []
            || array_map(fn ($row) => $row->COLUMN_NAME, $rows) !== $child
            || array_map(fn ($row) => $row->REFERENCED_COLUMN_NAME, $rows) !== $parentFields
            || array_filter($rows, fn ($row) => $row->REFERENCED_TABLE_NAME !== $parent
                || $row->UPDATE_RULE !== 'CASCADE' || $row->DELETE_RULE !== $delete) !== []) {
            throw new RuntimeException("INS-02: estado parcial o FK inesperada $name; no se ejecutó DDL.");
        }
    }
};
