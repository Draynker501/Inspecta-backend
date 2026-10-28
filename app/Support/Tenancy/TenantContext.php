<?php

namespace App\Support\Tenancy;

use Closure;
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

    public function id(): ?string
    {
        return $this->organizationId;
    }

    public function has(): bool
    {
        return $this->organizationId !== null;
    }

    public function set(string $organizationId): void
    {
        if (trim($organizationId) === '') {
            throw new InvalidArgumentException('El identificador de organización no puede estar vacío.');
        }

        $this->organizationId = $organizationId;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }

    /**
     * Ejecuta un callback con otra organización activa.
     *
     * Reservado para operaciones administrativas explícitas (comandos
     * Artisan, jobs y tareas de mantenimiento) que deben ser auditables.
     * No debe usarse en controladores para "saltarse" el aislamiento.
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
     */
    public function withoutTenant(Closure $callback): mixed
    {
        $previous = $this->organizationId;

        $this->organizationId = null;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }
}
