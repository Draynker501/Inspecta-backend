<?php

use App\Support\Ehs\SqliteOrderRebuild;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->change('add');
    }

    public function down(): void
    {
        $this->change('drop');
    }

    private function change(string $action): void
    {
        $db = DB::connection($this->getConnection());

        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            if ($db->getTablePrefix() !== '' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
                throw new RuntimeException('EHS CHECK requiere tablas sin prefijo ni transacción externa.');
            }
            $server = $db->selectOne('SELECT VERSION() AS version, DATABASE() AS schema_name, @@SESSION.foreign_key_checks AS fk_checks', [], false);
            if (! $server || ! preg_match('/^(?:5\.5\.5-)?11\.8\.\d+.*MariaDB/i', $server->version)
                || ! $server->schema_name || (int) $server->fk_checks !== 1) {
                throw new RuntimeException('EHS CHECK requiere MariaDB 11.8, base seleccionada y foreign_key_checks activo.');
            }
            $constraints = [
                'checklist_sections' => 'sections_display_order_positive',
                'questions' => 'questions_display_order_positive',
            ];
            foreach ($constraints as $table => $name) {
                if ($action === 'add' && $db->table($table)->where('display_order', '<', 1)->exists()) {
                    throw new RuntimeException('Existen órdenes menores que 1.');
                }
                $exists = $db->table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', $server->schema_name)
                    ->where('TABLE_NAME', $table)
                    ->where('CONSTRAINT_NAME', $name)
                    ->where('CONSTRAINT_TYPE', 'CHECK')
                    ->exists();
                if ($exists !== ($action === 'drop')) {
                    throw new RuntimeException('Estado de CHECK EHS inesperado; no se ejecutó DDL.');
                }
            }
            // MariaDB confirma cada ALTER independientemente: si el segundo
            // falla, el estado parcial requiere intervención explícita.
            foreach ($constraints as $table => $name) {
                $sql = $action === 'add'
                    ? "ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK (`display_order` >= 1)"
                    : "ALTER TABLE `$table` DROP CONSTRAINT `$name`";
                $db->statement($sql);
            }

            return;
        }

        if ($db->getDriverName() !== 'sqlite') {
            throw new RuntimeException('EHS CHECK solo admite SQLite o MariaDB 11.8.');
        }

        SqliteOrderRebuild::run($db, [
            ['table' => 'checklist_sections', 'check' => $action],
            ['table' => 'questions', 'check' => $action],
        ]);
    }
};
