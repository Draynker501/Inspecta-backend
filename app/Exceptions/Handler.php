<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Handler extends BaseHandler
{
    /**
     * The list of exception inputs that are not reported.
     *
     * @var list<string>
     */
    protected $dontReport = [
        //
    ];

    /**
     * The list of exception inputs that are not rendered as JSON.
     *
     * @var list<string>
     */
    protected $dontRenderAsJson = [
        //
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'No autenticado. Token de acceso ausente o inválido.',
                ], 401);
            }
        });

        $this->renderable(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], $e->getStatusCode());
            }
        });
    }
}
