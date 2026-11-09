<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Contexto organizacional de la petición en curso.
 *
 * Se registra como singleton y lo establece el middleware
 * ResolveTenantContext a partir del usuario autenticado. Nunca debe
 * alimentarse desde el body, query string o cabeceras del cliente.
 */
final class TenantContext
{
    private ?string $organizationId = null;

    private bool $bypassingTenant = false;

    private bool $httpRequest = false;

    public function id(): ?string
    {
        return $this->organizationId;
    }

    public function has(): bool
    {
        return $this->organizationId !== null;
    }

    public function isBypassingTenant(): bool
    {
        return $this->bypassingTenant;
    }

    /**
     * Devuelve el identificador de la organización activa.
     *
     * @throws InvalidArgumentException si no hay contexto establecido.
     */
    public function requireId(): string
    {
        if ($this->organizationId === null) {
            throw new InvalidArgumentException('No hay contexto organizacional establecido.');
        }

        return $this->organizationId;
    }

    public function set(string $organizationId): void
    {
        if (! Str::isUuid($organizationId)) {
            throw new InvalidArgumentException('El identificador de organización no es un UUID válido.');
        }

        $this->organizationId = $organizationId;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }

    public function markHttpRequest(): void
    {
        $this->httpRequest = true;
    }

    public function clearHttpRequest(): void
    {
        $this->httpRequest = false;
    }

    public function isHttpRequest(): bool
    {
        return $this->httpRequest;
    }

    /**
     * Ejecuta un callback con otra organización activa.
     *
     * Reservado para operaciones administrativas explícitas (comandos
     * Artisan, jobs y tareas de mantenimiento) que deben ser auditables.
     * No debe usarse en controladores para "saltarse" el aislamiento.
     *
     * El contexto anterior se restaura incluso si el callback lanza una
     * excepción.
     */
    public function runAs(string $organizationId, Closure $callback): mixed
    {
        $previous = $this->organizationId;

        $this->set($organizationId);

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }

    /**
     * Ejecuta un callback sin filtro organizacional.
     *
     * Solo para bootstrap, migraciones de datos y diagnósticos de consola.
     * El motivo es obligatorio para dejar constancia de por qué se omite el
     * aislamiento.
     *
     * El contexto anterior se restaura incluso si el callback lanza una
     * excepción.
     */
    public function withoutTenant(string $reason, Closure $callback): mixed
    {
        $previous = $this->organizationId;
        $previousBypass = $this->bypassingTenant;

        $this->organizationId = null;
        $this->bypassingTenant = true;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
            $this->bypassingTenant = $previousBypass;
        }
    }
}
