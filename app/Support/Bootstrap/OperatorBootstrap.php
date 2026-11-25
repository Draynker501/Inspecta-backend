<?php

namespace App\Support\Bootstrap;

use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Aprovisiona la organizacion operadora desde consola.
 *
 * Crea una unica organizacion TYPE_OPERATOR activa, sin membresias, sin
 * usuarios y sin roles asociados. Es idempotente: si ya existe una operadora
 * valida, no se modifica. No reactiva organizaciones inactivas ni reutiliza
 * codigos de organizaciones cliente. La validacion de entrada vive aqui, no
 * solo en el comando.
 */
final class OperatorBootstrap
{
    public function __construct(
        private TenantContext $context,
        private AdministrativeActor $actors,
    ) {}

    /**
     * @param  array{code?: string, name?: string, tax_id?: ?string, address?: ?string, phone?: ?string, email?: ?string}  $data
     * @return array{organization: Organization, created: bool}
     */
    public function provision(array $data, ?string $actorEmail = null): array
    {
        $code = OrganizationCode::normalize((string) ($data['code'] ?? OrganizationCode::OPERATOR));
        OrganizationCode::assertOperatorCode($code);

        $actorId = $this->actors->optional($actorEmail);

        try {
            return DB::transaction(fn (): array => $this->createOrReuse($code, $data, $actorId));
        } catch (QueryException $exception) {
            // Dos ejecuciones simultaneas: si la otra creo una operadora valida,
            // el resultado es idempotente; cualquier otro conflicto se propaga.
            $operator = Organization::query()->where('code', $code)->first();

            if ($operator !== null
                && $operator->type === Organization::TYPE_OPERATOR
                && $operator->status) {
                return ['organization' => $operator, 'created' => false];
            }

            throw $exception;
        }
    }

    /**
     * @param  array{name?: string, tax_id?: ?string, address?: ?string, phone?: ?string, email?: ?string}  $data
     * @return array{organization: Organization, created: bool}
     */
    private function createOrReuse(string $code, array $data, ?string $actorId): array
    {
        $existing = Organization::withTrashed()->where('code', $code)->first();

        if ($existing !== null) {
            if ($existing->type !== Organization::TYPE_OPERATOR) {
                throw new RuntimeException('Ya existe una organizacion con el codigo INSPECTA-OP y no es la operadora.');
            }

            if ($existing->trashed() || ! $existing->status) {
                throw new RuntimeException('La organizacion operadora existe pero esta inactiva o eliminada; no se reactiva automaticamente.');
            }

            return ['organization' => $existing, 'created' => false];
        }

        $activeOperator = Organization::query()
            ->where('type', Organization::TYPE_OPERATOR)
            ->where('status', true)
            ->whereNull('deleted_at')
            ->first();

        if ($activeOperator !== null) {
            throw new RuntimeException("Ya existe una organizacion operadora activa con codigo {$activeOperator->code}.");
        }

        $name = BootstrapInput::organizationName($data['name'] ?? null);
        $taxId = BootstrapInput::optionalString($data['tax_id'] ?? null, BootstrapInput::MAX_SHORT_TEXT, 'La identificacion fiscal');
        $address = BootstrapInput::optionalString($data['address'] ?? null, null, 'La direccion');
        $phone = BootstrapInput::optionalString($data['phone'] ?? null, BootstrapInput::MAX_SHORT_TEXT, 'El telefono');
        $email = BootstrapInput::email($data['email'] ?? null, false, 'El email de la organizacion');

        $organization = new Organization;
        $organization->id = (string) Str::uuid7();
        $organization->code = $code;
        $organization->name = $name;
        $organization->tax_id = $taxId;
        $organization->address = $address;
        $organization->phone = $phone;
        $organization->email = $email;
        $organization->type = Organization::TYPE_OPERATOR;
        $organization->status = true;
        $organization->save();

        $this->context->runAs($organization->id, function () use ($organization, $actorId): void {
            $this->context->withAuditActor($actorId, function () use ($organization): void {
                app(AuditLogger::class)->record('operator_organization.created', $organization, [], [
                    'code' => $organization->code,
                    'name' => $organization->name,
                    'type' => $organization->type,
                ]);
            });
        });

        return ['organization' => $organization, 'created' => true];
    }
}
