<?php

namespace App\Support\Console;

use InvalidArgumentException;

/**
 * Lee una contrasena desde la entrada estandar sin mostrarla.
 *
 * La fuente es inyectable para pruebas: permite simular una tuberia, una
 * entrada vacia o un flujo cerrado sin depender del STDIN del proceso.
 */
final class StdinPasswordReader
{
    /** @var resource|null */
    private $stream;

    /**
     * @param  resource|null  $stream
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream;
    }

    /**
     * @throws InvalidArgumentException si no hay entrada o esta vacia.
     */
    public function read(): string
    {
        $stream = $this->stream ?? (defined('STDIN') ? STDIN : null);

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('No hay entrada estandar disponible para leer la contrasena.');
        }

        $password = stream_get_contents($stream);

        if (! is_string($password) || $password === '') {
            throw new InvalidArgumentException('No se recibio la contrasena por la entrada estandar.');
        }

        $password = rtrim($password, "\r\n");

        if (trim($password) === '') {
            throw new InvalidArgumentException('La contrasena recibida esta vacia.');
        }

        return $password;
    }
}
