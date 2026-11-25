<?php

namespace App\Support\Bootstrap;

use App\Authorization\ClientRoleProvisioner;
use App\Authorization\ClientRoleTemplate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Aprovisiona una organizacion cliente completa desde consola.
 *
 * Toda la validacion de entrada vive en el servicio (no solo en el comando),
 * de modo que una invocacion directa aplica las mismas reglas. El actor
 * administrativo es obligatorio y debe ser un administrador de plataforma
 * activo; nunca se atribuyen acciones a identificadores arbitrarios.
 */
final class ClientBootstrap
{
    /** Codigo reservado para la organizacion operadora. */
    public const RESERVED_OPERATOR_CODE = OrganizationCode::OPERATOR;

    public const CODE_PATTERN = OrganizationCode::PATTERN;

    public function __construct(
        private TenantContext $context,
        private ClientRoleProvisioner $roleProvisioner,
        private AdministrativeActor $actors,
    ) {}

    /**
     * Normaliza un codigo antes de validarlo o consultarlo.
     */
    public static function normalizeCode(string $code): string
    {
        return OrganizationCode::normalize($code);
    }

    /**
     * Valida el codigo normalizado: formato y reserva de la operadora.
     *
     * @throws InvalidArgumentException
     */
    public static function validateCode(string $code): void
    {
        OrganizationCode::assertClientCode($code);
    }

    /**
     * @param  array{code: string, name: string, tax_id?: ?string, address?: ?string, phone?: ?string, email?: ?string}  $organization
     * @param  array{email: string, name?: ?string, password?: ?string, link_existing?: bool}  $admin
     * @return array{organization: Organization, user: User, membership: OrganizationMembership, role: Role, user_created: bool}
     */
    public function provision(array $organization, array $admin, ?string $actorEmail = null): array
    {
        $code = self::normalizeCode((string) ($organization['code'] ?? ''));
        self::validateCode($code);

        $name = BootstrapInput::organizationName($organization['name'] ?? null);
        $taxId = BootstrapInput::optionalString($organization['tax_id'] ?? null, BootstrapInput::MAX_SHORT_TEXT, 'La identificacion fiscal');
        $address = BootstrapInput::optionalString($organization['address'] ?? null, null, 'La direccion');
        $phone = BootstrapInput::optionalString($organization['phone'] ?? null, BootstrapInput::MAX_SHORT_TEXT, 'El telefono');
        $email = BootstrapInput::email($organization['email'] ?? null, false, 'El email de la organizacion');

        $linkExisting = (bool) ($admin['link_existing'] ?? false);
        $adminEmail = BootstrapInput::email($admin['email'] ?? null, true, 'El email del administrador');
        $adminName = $linkExisting ? null : BootstrapInput::personName($admin['name'] ?? null);
        $password = $linkExisting ? null : BootstrapInput::password($admin['password'] ?? null);

        $actorId = $this->actors->required($actorEmail);

        return DB::transaction(function () use ($code, $name, $taxId, $address, $phone, $email, $adminEmail, $adminName, $password, $linkExisting, $actorId): array {
            if (Organization::withTrashed()->where('code', $code)->exists()) {
                throw new RuntimeException("Ya existe una organizacion con el codigo {$code}.");
            }

            $organizationId = (string) Str::uuid7();

            $org = new Organization;
            $org->id = $organizationId;
            $org->code = $code;
            $org->name = $name;
            $org->tax_id = $taxId;
            $org->address = $address;
            $org->phone = $phone;
            $org->email = $email;
            $org->type = Organization::TYPE_CLIENT;
            $org->status = true;
            $org->save();

            return $this->context->runAs($organizationId, function () use ($org, $adminEmail, $adminName, $password, $linkExisting, $actorId, $organizationId): array {
                $roles = $this->roleProvisioner->provision($org);
                $adminRole = $roles[ClientRoleTemplate::ADMIN];

                [$user, $userCreated] = $this->resolveAdmin($org, (string) $adminEmail, $adminName, $password, $linkExisting);

                $membership = OrganizationMembership::query()
                    ->forUser($user->id)
                    ->forOrganization($organizationId)
                    ->first();

                if ($membership !== null) {
                    throw new RuntimeException('El administrador ya tiene una membresia en esta organizacion.');
                }

                $membership = OrganizationMembership::create([
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'status' => OrganizationMembership::STATUS_ACTIVE,
                ]);

                $this->context->withAuditActor($actorId, function () use ($org, $user, $membership, $adminRole, $userCreated): void {
                    $user->assignRole($adminRole);

                    $logger = app(AuditLogger::class);

                    $logger->record('client_organization.created', $org, [], [
                        'code' => $org->code,
                        'name' => $org->name,
                        'type' => $org->type,
                        'admin_email' => $user->email,
                    ]);

                    if ($userCreated) {
                        $logger->record('client_user.created', $user, [], [
                            'user_id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'organization_id' => $org->id,
                        ]);
                    }

                    $logger->record('client_membership.created', $membership, [], [
                        'user_id' => $user->id,
                        'organization_id' => $org->id,
                        'status' => $membership->status,
                    ]);
                });

                return [
                    'organization' => $org,
                    'user' => $user,
                    'membership' => $membership,
                    'role' => $adminRole,
                    'user_created' => $userCreated,
                ];
            });
        });
    }

    /**
     * @return array{0: User, 1: bool}
     */
    private function resolveAdmin(
        Organization $organization,
        string $email,
        ?string $name,
        ?string $password,
        bool $linkExisting,
    ): array {
        if ($linkExisting) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                throw new RuntimeException("No existe un usuario con el email {$email}.");
            }

            if (! $user->status) {
                throw new RuntimeException('El usuario existente esta inactivo.');
            }

            return [$user, false];
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException(
                "Ya existe un usuario con el email {$email}. Use --existing-user para vincularlo sin modificarlo."
            );
        }

        if ($name === null || $password === null) {
            throw new InvalidArgumentException('Faltan datos del administrador.');
        }

        $user = new User;
        $user->id = (string) Str::uuid7();
        $user->name = $name;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->organization_id = $organization->id;
        $user->email_verified_at = now();
        $user->status = true;
        $user->save();

        return [$user, true];
    }
}
