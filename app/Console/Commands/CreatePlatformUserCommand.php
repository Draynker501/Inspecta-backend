<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreatePlatformUserCommand extends Command
{
    /**
     * Longitud mínima exigida a la contraseña del usuario de plataforma.
     */
    public const MIN_PASSWORD_LENGTH = 12;

    protected $signature = 'inspecta:create-platform-user
        {--email= : Email del usuario de plataforma}
        {--name= : Nombre del usuario de plataforma}
        {--password-stdin : Leer la contraseña desde la entrada estándar (requiere pipe; puede bloquearse si no hay entrada)}';

    protected $description = 'Crea el primer usuario de plataforma (operadora INSPECTA-OP) con membresía y rol activos, auditado. '
        .'--password-stdin requiere una entrada por pipe y puede bloquearse si no la hay.';

    public function handle(): int
    {
        $email = $this->option('email');
        if (! is_string($email) || trim($email) === '') {
            if (! $this->isInteractive()) {
                $this->error('Falta la opción --email.');

                return self::FAILURE;
            }

            $email = (string) $this->ask('Email del usuario');
        }
        $email = trim((string) $email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('El email no es válido.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        if (! is_string($name) || trim($name) === '') {
            if (! $this->isInteractive()) {
                $this->error('Falta la opción --name.');

                return self::FAILURE;
            }

            $name = (string) $this->ask('Nombre del usuario');
        }
        $name = trim((string) $name);

        if ($name === '') {
            $this->error('El nombre no puede estar vacío.');

            return self::FAILURE;
        }

        if ((bool) $this->option('password-stdin')) {
            $password = $this->readPasswordFromStdin();
            if ($password === null) {
                return self::FAILURE;
            }
        } else {
            $password = $this->readPasswordInteractively();
            if ($password === null) {
                return self::FAILURE;
            }
        }

        $operator = Organization::query()
            ->where('code', 'INSPECTA-OP')
            ->where('status', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $operator) {
            $this->error('La operadora INSPECTA-OP no existe. Ejecute el seeder de arranque (OrganizationSeeder) antes de crear el usuario de plataforma.');

            return self::FAILURE;
        }

        $platformRoleName = (string) config('tenancy.roles.platform_admin');

        try {
            DB::transaction(function () use ($email, $name, $password, $operator, $platformRoleName): void {
                // Bloqueo de la fila de la operadora para serializar ejecuciones
                // concurrentes y evitar la carrera en la comprobación de unicidad.
                $lockedOperator = Organization::query()
                    ->whereKey($operator->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOperator) {
                    throw new \RuntimeException('La operadora INSPECTA-OP no existe.');
                }

                $platformUserExists = User::query()
                    ->whereHas('memberships', function ($query) use ($operator): void {
                        $query->where('organization_id', $operator->id)
                            ->where('status', OrganizationMembership::STATUS_ACTIVE);
                    })
                    ->whereHas('roles', function ($query) use ($operator, $platformRoleName): void {
                        $query->where('roles.organization_id', $operator->id)
                            ->where('roles.name', $platformRoleName);
                    })
                    ->exists();

                if ($platformUserExists) {
                    throw new \RuntimeException('Ya existe un usuario de plataforma. No se realizaron cambios.');
                }

                if (User::query()->where('email', $email)->exists()) {
                    throw new \RuntimeException('Ya existe un usuario con ese email. No se realizaron cambios.');
                }

                $user = new User;
                $user->id = (string) Str::uuid7();
                $user->name = $name;
                $user->email = $email;
                $user->password = Hash::make($password);
                $user->organization_id = $operator->id;
                $user->email_verified_at = now();
                $user->status = true;
                $user->save();

                OrganizationMembership::firstOrCreate(
                    ['user_id' => $user->id, 'organization_id' => $operator->id],
                    [
                        'id' => (string) Str::uuid7(),
                        'status' => OrganizationMembership::STATUS_ACTIVE,
                    ],
                );

                app(TenantContext::class)->runAs($operator->id, function () use ($user, $operator, $platformRoleName): void {
                    $role = Role::firstOrCreate(
                        ['organization_id' => $operator->id, 'name' => $platformRoleName],
                        [
                            'id' => (string) Str::uuid7(),
                            'organization_id' => $operator->id,
                            'name' => $platformRoleName,
                            'description' => 'Administración multiempresa de la consultora',
                            'status' => true,
                        ],
                    );

                    $user->assignRole($role);
                });

                app(TenantContext::class)->runAs($operator->id, function () use ($user, $operator, $email, $name): void {
                    app(AuditLogger::class)->record('platform_user.created', $user, [], [
                        'user_id' => $user->id,
                        'name' => $name,
                        'email' => $email,
                        'organization_id' => $operator->id,
                    ]);
                });
            });
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();

            if (str_contains($message, 'Ya existe')) {
                $this->error($message);
            } else {
                $this->error('No se pudo crear el usuario de plataforma: '.$message);
            }

            return self::FAILURE;
        }

        $this->info("Usuario de plataforma creado: {$email}");

        return self::SUCCESS;
    }

    private function isInteractive(): bool
    {
        return (bool) ($this->input?->isInteractive() ?? false);
    }

    private function readPasswordInteractively(): ?string
    {
        $maxAttempts = 3;
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $password = $this->secret('Contraseña');
            $confirmation = $this->secret('Confirmar contraseña');

            if (! is_string($password) || trim($password) === '') {
                $this->error('La contraseña no puede estar vacía ni contener solo espacios.');

                continue;
            }

            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $this->error('La contraseña debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.');

                continue;
            }

            if ($password !== $confirmation) {
                $this->error('Las contraseñas no coinciden.');

                continue;
            }

            return $password;
        }

        $this->error('Se agotaron los intentos para ingresar la contraseña.');

        return null;
    }

    private function readPasswordFromStdin(): ?string
    {
        $raw = stream_get_contents(STDIN);

        if ($raw === false) {
            $this->error('No se pudo leer la contraseña desde la entrada estándar.');

            return null;
        }

        $raw = rtrim((string) $raw, "\r\n");

        if ($raw === '') {
            $this->error('La contraseña no puede estar vacía.');

            return null;
        }

        if (str_contains($raw, "\n")) {
            $parts = explode("\n", $raw, 2);
            $password = rtrim($parts[0], "\r");
            $confirmation = rtrim($parts[1] ?? '', "\r");

            if (trim($password) === '') {
                $this->error('La contraseña no puede estar vacía ni contener solo espacios.');

                return null;
            }

            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $this->error('La contraseña debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.');

                return null;
            }

            if ($password !== $confirmation) {
                $this->error('Las contraseñas no coinciden.');

                return null;
            }

            return $password;
        }

        $password = $raw;

        if (trim($password) === '') {
            $this->error('La contraseña no puede estar vacía ni contener solo espacios.');

            return null;
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('La contraseña debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.');

            return null;
        }

        return $password;
    }
}
