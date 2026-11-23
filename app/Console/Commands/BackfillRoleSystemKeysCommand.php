<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

class BackfillRoleSystemKeysCommand extends Command
{
    private const BASE_ROLES = [
        'admin' => 'Administrador',
        'supervisor' => 'Supervisor',
        'inspector' => 'Inspector',
    ];

    private const SYSTEM_KEYS = ['admin', 'supervisor', 'inspector'];

    protected $signature = 'roles:backfill-system-key
        {--source= : Fuente de referencia: role-seeder o mapping-file}
        {--mapping= : Ruta del JSON para --source=mapping-file}
        {--organization= : UUID de organización; obligatorio con --apply}
        {--manifest= : Ruta del manifiesto de cambios}
        {--reconcile= : Ruta de manifiesto para reconciliación de solo lectura}
        {--apply : Aplica los cambios; sin esta opción solo simula}';

    protected $description = 'Reconstruye roles.system_key desde una referencia técnica verificable, con dry-run por defecto.';

    public function handle(): int
    {
        try {
            if (! Schema::hasColumn('roles', 'system_key')) {
                $this->error('Falta la migración roles.system_key. No se realizaron cambios.');

                return self::FAILURE;
            }

            $source = $this->option('source');
            $organization = $this->option('organization');
            $apply = (bool) $this->option('apply');
            $reconcile = $this->option('reconcile');

            if (is_string($reconcile) && $reconcile !== '') {
                if ($apply || $source !== null || $this->option('mapping') !== null
                    || $organization !== null || $this->option('manifest') !== null) {
                    $this->error('--reconcile es de solo lectura y no admite opciones de ejecución.');

                    return self::FAILURE;
                }

                return $this->reconcile($reconcile);
            }

            if (! in_array($source, ['role-seeder', 'mapping-file'], true)) {
                $this->error('Debes indicar --source=role-seeder o --source=mapping-file.');

                return self::FAILURE;
            }

            if ($organization !== null && ! Str::isUuid($organization)) {
                $this->error('--organization debe ser un UUID válido.');

                return self::FAILURE;
            }

            if ($apply && $organization === null) {
                $this->error('--apply exige --organization; no se permite una aplicación global.');

                return self::FAILURE;
            }

            if ($source === 'role-seeder' && $this->option('mapping') !== null) {
                $this->error('--mapping solo es válido con --source=mapping-file.');

                return self::FAILURE;
            }

            if ($source === 'mapping-file' && ! is_string($this->option('mapping'))) {
                $this->error('--source=mapping-file exige --mapping=RUTA.');

                return self::FAILURE;
            }

            $result = $source === 'role-seeder'
                ? $this->roleSeederCandidates($organization)
                : $this->mappingCandidates((string) $this->option('mapping'), $organization);

            $runId = (string) Str::uuid7();
            $manifestPath = $this->manifestPath($runId);
            $state = ! $apply
                ? 'dry_run'
                : ($this->hasProblems($result) ? 'rejected' : 'prepared');
            $manifest = $this->manifest($source, $organization, $state, $runId, $result);
            $this->createManifest($manifestPath, $manifest);

            if ($this->hasProblems($result)) {
                $this->renderProblems($result);
                $this->warn('Manifiesto de diagnóstico: '.$manifestPath);

                return self::FAILURE;
            }

            if (! $apply) {
                $this->info(sprintf(
                    'Dry-run: %d cambios propuestos, %d sin cambios. Manifiesto: %s',
                    count($result['changes']),
                    count($result['noops']),
                    $manifestPath
                ));

                return self::SUCCESS;
            }

            try {
                $this->applyChanges($result['changes'], (string) $organization);
            } catch (Throwable $exception) {
                $this->transitionManifest($manifestPath, $manifest, 'uncertain', $exception->getMessage());
                $this->error(
                    'No se confirmó el commit; la BD podría estar modificada. '
                    .'Ejecuta --reconcile='.$manifestPath.' antes de reintentar.'
                );

                return self::FAILURE;
            }

            $manifest['state'] = 'applied';

            try {
                $this->replaceManifest($manifestPath, $manifest);
            } catch (Throwable $exception) {
                $this->error(
                    'Los cambios se confirmaron en BD, pero el manifiesto no pudo actualizarse. '
                    .'La BD puede estar modificada; ejecuta --reconcile='.$manifestPath.'.'
                );

                return self::FAILURE;
            }

            $this->info(sprintf(
                'Aplicados %d cambios en %s. Manifiesto: %s',
                count($result['changes']),
                $organization,
                $manifestPath
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Backfill abortado antes de un commit confirmado: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{changes: list<array>, noops: list<array>, conflicts: list<array>, missing: list<array>, errors: list<string>}
     */
    private function roleSeederCandidates(?string $organization): array
    {
        $organizations = DB::table('organizations')
            ->where('type', 'client')
            ->whereNull('deleted_at')
            ->when($organization !== null, fn ($query) => $query->where('id', $organization))
            ->pluck('id')
            ->all();

        if ($organizations === []) {
            return $this->emptyResult(['No se encontró la organización cliente solicitada.']);
        }

        $result = $this->emptyResult();

        foreach ($organizations as $organizationId) {
            foreach (self::BASE_ROLES as $systemKey => $roleName) {
                $expectedId = (string) Uuid::uuid5(
                    Uuid::NAMESPACE_DNS,
                    $organizationId.':'.$roleName
                );

                $role = DB::table('roles')
                    ->where('organization_id', $organizationId)
                    ->where('id', $expectedId)
                    ->first(['id', 'system_key']);

                if ($role === null) {
                    $result['missing'][] = [
                        'organization_id' => $organizationId,
                        'role_id' => $expectedId,
                        'system_key' => $systemKey,
                        'reason' => 'No existe el rol con el UUID determinista del RoleSeeder.',
                    ];

                    continue;
                }

                $this->classify($result, $organizationId, $role, $systemKey);
            }
        }

        return $result;
    }

    /**
     * @return array{changes: list<array>, noops: list<array>, conflicts: list<array>, missing: list<array>, errors: list<string>}
     */
    private function mappingCandidates(string $path, ?string $organization): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return $this->emptyResult(['No se puede leer el archivo de mapeo.']);
        }

        try {
            $mapping = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return $this->emptyResult(['El archivo de mapeo no contiene JSON válido.']);
        }

        if (! is_array($mapping) || ($mapping['version'] ?? null) !== 1 || ! is_array($mapping['roles'] ?? null)) {
            return $this->emptyResult(['El mapeo requiere version=1 y una lista roles.']);
        }

        $seenPairs = [];
        $seenKeys = [];
        $result = $this->emptyResult();

        foreach ($mapping['roles'] as $index => $record) {
            if (! is_array($record)
                || array_diff(array_keys($record), ['organization_id', 'role_id', 'system_key']) !== []) {
                $result['errors'][] = "Registro {$index}: claves no permitidas o formato inválido.";

                continue;
            }

            $organizationId = $record['organization_id'] ?? null;
            $roleId = $record['role_id'] ?? null;
            $systemKey = $record['system_key'] ?? null;

            if (! is_string($organizationId) || ! Str::isUuid($organizationId)
                || ! is_string($roleId) || ! Str::isUuid($roleId)
                || ! in_array($systemKey, self::SYSTEM_KEYS, true)) {
                $result['errors'][] = "Registro {$index}: UUID o system_key inválido.";

                continue;
            }

            $pair = $organizationId.':'.$roleId;
            if (isset($seenPairs[$pair])) {
                $result['errors'][] = "Registro {$index}: role_id duplicado en la organización.";
            }
            $seenPairs[$pair] = true;

            $keyPair = $organizationId.':'.$systemKey;
            if (isset($seenKeys[$keyPair])) {
                $result['conflicts'][] = [
                    'organization_id' => $organizationId,
                    'role_id' => $roleId,
                    'system_key' => $systemKey,
                    'reason' => 'La misma system_key aparece para más de un rol en la organización.',
                ];
            }
            $seenKeys[$keyPair] = true;

            if ($organization !== null && $organizationId !== $organization) {
                continue;
            }

            $organizationExists = DB::table('organizations')
                ->where('id', $organizationId)
                ->where('type', 'client')
                ->whereNull('deleted_at')
                ->exists();

            if (! $organizationExists) {
                $result['errors'][] = "Registro {$index}: organización cliente inexistente o no elegible.";

                continue;
            }

            $role = DB::table('roles')
                ->where('organization_id', $organizationId)
                ->where('id', $roleId)
                ->first(['id', 'system_key']);

            if ($role === null) {
                $result['missing'][] = [
                    'organization_id' => $organizationId,
                    'role_id' => $roleId,
                    'system_key' => $systemKey,
                    'reason' => 'El role_id no existe con esa organización.',
                ];

                continue;
            }

            $this->classify($result, $organizationId, $role, $systemKey);
        }

        if ($result['changes'] === [] && $result['noops'] === [] && $result['missing'] === [] && $result['errors'] === []) {
            $result['errors'][] = 'El mapeo no contiene roles para la organización solicitada.';
        }

        return $result;
    }

    /**
     * @param  array{changes: list<array>, noops: list<array>, conflicts: list<array>, missing: list<array>, errors: list<string>}  $result
     */
    private function classify(array &$result, string $organizationId, object $role, string $systemKey): void
    {
        $current = $role->system_key;

        if ($current === $systemKey) {
            $result['noops'][] = [
                'organization_id' => $organizationId,
                'role_id' => $role->id,
                'system_key' => $systemKey,
            ];

            return;
        }

        if ($current !== null) {
            $result['conflicts'][] = [
                'organization_id' => $organizationId,
                'role_id' => $role->id,
                'system_key' => $systemKey,
                'reason' => "El rol ya tiene system_key={$current}.",
            ];

            return;
        }

        $taken = DB::table('roles')
            ->where('organization_id', $organizationId)
            ->where('system_key', $systemKey)
            ->exists();

        if ($taken) {
            $result['conflicts'][] = [
                'organization_id' => $organizationId,
                'role_id' => $role->id,
                'system_key' => $systemKey,
                'reason' => 'La system_key ya está asignada a otro rol de la organización.',
            ];

            return;
        }

        $result['changes'][] = [
            'organization_id' => $organizationId,
            'role_id' => $role->id,
            'system_key' => $systemKey,
            'previous_system_key' => null,
        ];
    }

    /**
     * @param  list<array>  $changes
     */
    private function applyChanges(array $changes, string $organizationId): void
    {
        DB::transaction(function () use ($changes, $organizationId): void {
            $organization = DB::table('organizations')
                ->where('id', $organizationId)
                ->where('type', 'client')
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($organization === null) {
                throw new RuntimeException('La organización dejó de ser elegible.');
            }

            foreach ($changes as $change) {
                $role = DB::table('roles')
                    ->where('id', $change['role_id'])
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->first(['id', 'system_key']);

                if ($role === null || $role->system_key !== null) {
                    throw new RuntimeException('Un rol cambió durante la aplicación; no se aplicó nada.');
                }

                $updated = DB::table('roles')
                    ->where('id', $change['role_id'])
                    ->where('organization_id', $organizationId)
                    ->whereNull('system_key')
                    ->update(['system_key' => $change['system_key']]);

                if ($updated !== 1) {
                    throw new RuntimeException('No se pudo actualizar un rol de forma segura.');
                }
            }
        });
    }

    /**
     * @param  array{changes: list<array>, noops: list<array>, conflicts: list<array>, missing: list<array>, errors: list<string>}  $result
     */
    private function renderProblems(array $result): void
    {
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        foreach ($result['conflicts'] as $conflict) {
            $this->error(sprintf('Conflicto %s/%s -> %s: %s', $conflict['organization_id'], $conflict['role_id'], $conflict['system_key'], $conflict['reason']));
        }

        foreach ($result['missing'] as $missing) {
            $this->error(sprintf('Faltante %s/%s -> %s: %s', $missing['organization_id'], $missing['role_id'], $missing['system_key'], $missing['reason']));
        }

        $this->error('No se aplicaron cambios.');
    }

    /**
     * @param  array{changes: list<array>, noops: list<array>, conflicts: list<array>, missing: list<array>, errors: list<string>}  $result
     */
    private function hasProblems(array $result): bool
    {
        return $result['errors'] !== []
            || $result['conflicts'] !== []
            || $result['missing'] !== [];
    }

    /**
     * Reconciliación de solo lectura entre un manifiesto y el estado real.
     */
    private function reconcile(string $path): int
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No se puede leer el manifiesto de reconciliación.');

            return self::FAILURE;
        }

        try {
            $manifest = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $this->error('El manifiesto no contiene JSON válido.');

            return self::FAILURE;
        }

        $state = is_array($manifest) ? ($manifest['state'] ?? null) : null;
        $changes = is_array($manifest) ? ($manifest['changes'] ?? null) : null;

        if (! in_array($state, ['dry_run', 'prepared', 'applied', 'uncertain', 'rejected'], true)
            || ! is_array($changes)) {
            $this->error('El manifiesto no tiene un estado o lista de cambios válida.');

            return self::FAILURE;
        }

        $applied = 0;
        $notApplied = 0;
        $ambiguous = [];

        foreach ($changes as $change) {
            if (! is_array($change)
                || array_diff(array_keys($change), ['organization_id', 'role_id', 'system_key', 'previous_system_key']) !== []
                || ! is_string($change['organization_id'] ?? null)
                || ! Str::isUuid($change['organization_id'])
                || ! is_string($change['role_id'] ?? null)
                || ! Str::isUuid($change['role_id'])
                || ! in_array($change['system_key'] ?? null, self::SYSTEM_KEYS, true)
                || ! (is_null($change['previous_system_key'] ?? null)
                    || in_array($change['previous_system_key'], self::SYSTEM_KEYS, true))) {
                $ambiguous[] = 'Registro de manifiesto inválido.';

                continue;
            }

            $current = DB::table('roles')
                ->where('id', $change['role_id'])
                ->where('organization_id', $change['organization_id'])
                ->value('system_key');

            if ($current === $change['system_key']) {
                $applied++;
            } elseif ($current === ($change['previous_system_key'] ?? null)) {
                $notApplied++;
            } else {
                $ambiguous[] = sprintf(
                    'Cambio posterior o ambiguo en %s/%s.',
                    $change['organization_id'],
                    $change['role_id']
                );
            }
        }

        if ($ambiguous !== []) {
            foreach ($ambiguous as $message) {
                $this->error($message);
            }
            $this->error('Reconciliación detenida: no se modificó ningún dato.');

            return self::FAILURE;
        }

        $mixed = $applied > 0 && $notApplied > 0;
        $inconsistent = $mixed
            || ($state === 'applied' && $notApplied > 0)
            || (in_array($state, ['dry_run', 'rejected'], true) && $applied > 0)
            || ($state === 'prepared' && $applied > 0)
            || $state === 'uncertain';

        if ($inconsistent) {
            $this->error(sprintf(
                'Reconciliación inconsistente: estado=%s, aplicados=%d, no aplicados=%d. No se modificó ningún dato.',
                $state,
                $applied,
                $notApplied
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reconciliación consistente: estado=%s, aplicados=%d, no aplicados=%d. Sin escrituras.',
            $state,
            $applied,
            $notApplied
        ));

        return self::SUCCESS;
    }

    private function manifestPath(string $runId): string
    {
        $path = $this->option('manifest');

        return is_string($path) && $path !== ''
            ? $path
            : storage_path('app/role-system-key-backfill-'.$runId.'.json');
    }

    /**
     * @param  array{changes: list<array>, noops: list<array>, conflicts: list<array>, missing: list<array>, errors: list<string>}  $result
     * @return array<string, mixed>
     */
    private function manifest(
        string $source,
        ?string $organization,
        string $state,
        string $runId,
        array $result
    ): array {
        return [
            'version' => 1,
            'run_id' => $runId,
            'source' => $source,
            'state' => $state,
            'organization_id' => $organization,
            'generated_at' => now()->toIso8601String(),
            'changes' => $result['changes'],
            'noops' => $result['noops'],
            'conflicts' => $result['conflicts'],
            'missing' => $result['missing'],
            'errors' => $result['errors'],
        ];
    }

    private function createManifest(string $path, array $manifest): void
    {
        $this->ensureManifestDirectory($path);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el manifiesto.');
        }

        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('El manifiesto ya existe o la ruta no es escribible.');
        }

        if (! @chmod($path, 0600)) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException('No se pudieron aplicar permisos restrictivos al manifiesto.');
        }

        $payload = $json.PHP_EOL;
        $written = @fwrite($handle, $payload);
        if ($written === false || $written < strlen($payload) || ! @fclose($handle)) {
            @unlink($path);
            throw new RuntimeException('No se pudo escribir el manifiesto.');
        }
    }

    private function replaceManifest(string $path, array $manifest): void
    {
        $this->ensureManifestDirectory($path);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar el manifiesto.');
        }

        $temporary = $path.'.tmp-'.Str::uuid7();
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('No se pudo crear el manifiesto temporal.');
        }

        if (! @chmod($temporary, 0600)) {
            fclose($handle);
            @unlink($temporary);
            throw new RuntimeException('No se pudieron aplicar permisos restrictivos al manifiesto temporal.');
        }

        $payload = $json.PHP_EOL;
        $written = @fwrite($handle, $payload);
        if ($written === false || $written < strlen($payload)) {
            fclose($handle);
            @unlink($temporary);
            throw new RuntimeException('No se pudo escribir el manifiesto temporal.');
        }
        fclose($handle);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo publicar el manifiesto de forma atómica.');
        }

        @chmod($path, 0600);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function transitionManifest(string $path, array $manifest, string $state, string $error): void
    {
        $manifest['state'] = $state;
        $manifest['updated_at'] = now()->toIso8601String();
        $manifest['errors'][] = $error;

        try {
            $this->replaceManifest($path, $manifest);
        } catch (Throwable $exception) {
            $this->warn('No se pudo actualizar el manifiesto a '.$state.': '.$exception->getMessage());
        }
    }

    private function ensureManifestDirectory(string $path): void
    {
        if ($path === '') {
            throw new RuntimeException('La ruta del manifiesto está vacía.');
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio del manifiesto.');
        }
    }

    /**
     * @param  list<string>  $errors
     * @return array{changes: list<array>, noops: list<array>, conflicts: list<array>, missing: list<array>, errors: list<string>}
     */
    private function emptyResult(array $errors = []): array
    {
        return [
            'changes' => [],
            'noops' => [],
            'conflicts' => [],
            'missing' => [],
            'errors' => $errors,
        ];
    }
}
