<?php

namespace App\Console\Commands;

use App\Support\Bootstrap\BootstrapInput;
use App\Support\Bootstrap\ClientBootstrap;
use App\Support\Bootstrap\OrganizationCode;
use App\Support\Console\StdinPasswordReader;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Aprovisiona una organizacion cliente y su administrador desde consola.
 *
 * Es una operacion de bootstrap reservada al administrador del servidor: no
 * existe endpoint HTTP ni autorregistro. El actor administrativo es
 * obligatorio y toda la validacion de entrada se aplica en el servicio.
 */
class BootstrapClientCommand extends Command
{
    protected $signature = 'inspecta:bootstrap-client
        {--code= : Codigo unico de la organizacion (se normaliza a mayusculas; 2-50)}
        {--name= : Nombre de la organizacion}
        {--tax-id= : Identificacion fiscal (opcional)}
        {--address= : Direccion (opcional)}
        {--phone= : Telefono (opcional)}
        {--email= : Email de la organizacion (opcional)}
        {--admin-email= : Email del administrador cliente}
        {--admin-name= : Nombre del administrador cliente}
        {--existing-user : Vincular un usuario existente en lugar de crearlo}
        {--actor= : Email del administrador de plataforma que ejecuta el bootstrap (obligatorio)}
        {--password-stdin : Leer la contrasena del administrador desde la entrada estandar}';

    protected $description = 'Crea una organizacion cliente con sus roles y un administrador con membresia activa. '
        .'El actor de auditoria es obligatorio y debe ser un administrador de plataforma activo.';

    public function handle(ClientBootstrap $bootstrap, StdinPasswordReader $stdin): int
    {
        try {
            $organization = $this->organizationOptions();
            $admin = $this->adminInput($stdin);

            $result = $bootstrap->provision($organization, $admin, $this->option('actor'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (QueryException $exception) {
            $this->error('No se pudo completar el bootstrap por un conflicto de datos. No se realizaron cambios.');
            report($exception);

            return self::FAILURE;
        }

        $organization = $result['organization'];

        $this->info("Organizacion cliente creada: {$organization->code}");
        $this->line('  id: '.$organization->id);
        $this->line('  administrador: '.$result['user']->email.' ('.($result['user_created'] ? 'creado' : 'vinculado').')');
        $this->line('  roles: Administrador, Inspector, Supervisor');
        $this->line('  auditoria registrada para la organizacion');

        return self::SUCCESS;
    }

    /**
     * @return array{code: string, name: string, tax_id: ?string, address: ?string, phone: ?string, email: ?string}
     */
    private function organizationOptions(): array
    {
        $code = OrganizationCode::normalize((string) $this->option('code'));
        OrganizationCode::assertClientCode($code);

        return [
            'code' => $code,
            'name' => BootstrapInput::organizationName($this->option('name')),
            'tax_id' => BootstrapInput::optionalString($this->option('tax-id'), BootstrapInput::MAX_SHORT_TEXT, 'La identificacion fiscal'),
            'address' => BootstrapInput::optionalString($this->option('address'), null, 'La direccion'),
            'phone' => BootstrapInput::optionalString($this->option('phone'), BootstrapInput::MAX_SHORT_TEXT, 'El telefono'),
            'email' => BootstrapInput::email($this->option('email'), false, 'El email de la organizacion'),
        ];
    }

    /**
     * @return array{email: string, name: string, password?: string, link_existing?: bool}
     */
    private function adminInput(StdinPasswordReader $stdin): array
    {
        $email = (string) BootstrapInput::email($this->option('admin-email'), true, 'El email del administrador');
        $linkExisting = (bool) $this->option('existing-user');

        if ($linkExisting) {
            if ((bool) $this->option('password-stdin')) {
                throw new InvalidArgumentException('--password-stdin no aplica al vincular un usuario existente.');
            }

            return ['email' => $email, 'name' => '', 'link_existing' => true];
        }

        $name = BootstrapInput::personName($this->option('admin-name'));

        $password = (bool) $this->option('password-stdin')
            ? $stdin->read()
            : $this->readPasswordInteractively();

        return ['email' => $email, 'name' => $name, 'password' => $password];
    }

    private function readPasswordInteractively(): string
    {
        if (! (bool) ($this->input?->isInteractive() ?? false)) {
            throw new InvalidArgumentException('Falta --password-stdin para leer la contrasena de forma segura.');
        }

        $password = (string) $this->secret('Contrasena del administrador');
        $confirmation = (string) $this->secret('Confirmar contrasena');

        if ($password !== $confirmation) {
            throw new InvalidArgumentException('Las contrasenas no coinciden.');
        }

        return $password;
    }
}
