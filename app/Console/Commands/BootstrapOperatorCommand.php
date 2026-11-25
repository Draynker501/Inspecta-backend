<?php

namespace App\Console\Commands;

use App\Support\Bootstrap\OperatorBootstrap;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Aprovisiona la organizacion operadora desde consola.
 *
 * Es una operacion de instalacion reservada al administrador del servidor. No
 * crea usuarios, membresias ni roles: solo la organizacion TYPE_OPERATOR para
 * que inspecta:create-platform-user pueda crear despues al administrador de
 * plataforma. Toda la validacion se aplica en el servicio.
 */
class BootstrapOperatorCommand extends Command
{
    protected $signature = 'inspecta:bootstrap-operator
        {--code=INSPECTA-OP : Codigo de la operadora (debe ser INSPECTA-OP)}
        {--name= : Nombre de la organizacion operadora (obligatorio al crearla)}
        {--tax-id= : Identificacion fiscal (opcional)}
        {--address= : Direccion (opcional)}
        {--phone= : Telefono (opcional)}
        {--email= : Email de la organizacion (opcional)}
        {--actor= : Email de un administrador de plataforma (opcional; en el primer bootstrap se omite)}';

    protected $description = 'Crea la organizacion operadora (INSPECTA-OP) si no existe. No crea usuarios ni membresias. '
        .'Sin --actor la auditoria queda con actor null (no se inventan identidades).';

    public function handle(OperatorBootstrap $bootstrap): int
    {
        try {
            $result = $bootstrap->provision($this->organizationOptions(), $this->option('actor'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            $this->error('No se pudo completar el bootstrap de la operadora por un conflicto de datos. No se realizaron cambios.');
            report($exception);

            return self::FAILURE;
        }

        $organization = $result['organization'];

        if ($result['created']) {
            $this->info("Organizacion operadora creada: {$organization->code}");
            $this->line('  id: '.$organization->id);
            $this->line('  siguiente paso: inspecta:create-platform-user --email=<admin> --password-stdin');
        } else {
            $this->info("La organizacion operadora {$organization->code} ya existe ({$organization->id}). No se realizaron cambios.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{code: string, name: string, tax_id: ?string, address: ?string, phone: ?string, email: ?string}
     */
    private function organizationOptions(): array
    {
        return [
            'code' => (string) $this->option('code'),
            'name' => (string) $this->option('name'),
            'tax_id' => $this->option('tax-id'),
            'address' => $this->option('address'),
            'phone' => $this->option('phone'),
            'email' => $this->option('email'),
        ];
    }
}
