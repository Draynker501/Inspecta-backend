<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill idempotente de membresías (M1.5, P1.3).
 *
 * Para cada usuario con users.organization_id no nulo que aún no tenga una
 * membresía en esa organización, inserta una membresía activa.
 *
 * Usa DB::table (no modelos con scope) para no depender de OrganizationScope
 * ni de BelongsToOrganization. Es idempotente: re-ejecutarla no duplica ni
 * modifica membresías existentes.
 */
class MembershipBackfill
{
    /**
     * Ejecuta el backfill y devuelve cuántas membresías creó.
     *
     * @param  int  $chunk  Tamaño del lote de usuarios leídos.
     * @return array{created: int, already: int, skipped: int, users: int}
     */
    public static function run(int $chunk = 500): array
    {
        $created = 0;
        $already = 0;
        $skipped = 0;
        $users = 0;

        DB::table('users')
            ->whereNotNull('organization_id')
            ->where('organization_id', '!=', '')
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$created, &$already, &$skipped, &$users) {
                foreach ($rows as $user) {
                    $users++;

                    $exists = DB::table('organization_memberships')
                        ->where('user_id', $user->id)
                        ->where('organization_id', $user->organization_id)
                        ->exists();

                    if ($exists) {
                        $already++;
                        continue;
                    }

                    DB::table('organization_memberships')->insert([
                        'id' => (string) Str::uuid7(),
                        'user_id' => $user->id,
                        'organization_id' => $user->organization_id,
                        'status' => 'active',
                        'revoked_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $created++;
                }
            });

        return [
            'created' => $created,
            'already' => $already,
            'skipped' => $skipped,
            'users' => $users,
        ];
    }

    /**
     * Ejecuta un dry-run: calcula cuántas membresías se crearían sin escribir.
     *
     * @param  int  $chunk
     * @return array{would_create: int, already: int, skipped: int, users: int}
     */
    public static function dryRun(int $chunk = 500): array
    {
        $wouldCreate = 0;
        $already = 0;
        $skipped = 0;
        $users = 0;

        DB::table('users')
            ->whereNotNull('organization_id')
            ->where('organization_id', '!=', '')
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$wouldCreate, &$already, &$skipped, &$users) {
                foreach ($rows as $user) {
                    $users++;

                    $exists = DB::table('organization_memberships')
                        ->where('user_id', $user->id)
                        ->where('organization_id', $user->organization_id)
                        ->exists();

                    if ($exists) {
                        $already++;
                        continue;
                    }

                    $wouldCreate++;
                }
            });

        return [
            'would_create' => $wouldCreate,
            'already' => $already,
            'skipped' => $skipped,
            'users' => $users,
        ];
    }
}
