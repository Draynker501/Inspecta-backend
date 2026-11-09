<?php

namespace App\Exceptions;

use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
                return ApiErrorResponse::make(ApiErrorCode::Unauthenticated);
            }
        });

        // Un recurso de otra organización y un recurso inexistente deben ser
        // indistinguibles: mismo 404 y mismo mensaje, sin clase de modelo ni
        // identificadores en el cuerpo. ModelNotFoundException se convierte
        // en NotFoundHttpException antes de llegar aquí.
        $this->renderable(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiErrorResponse::make(ApiErrorCode::NotFound);
            }
        });

        // Validación: mismo cuerpo del framework (message y errors) con el
        // code validation_failed añadido (D-31c).
        $this->renderable(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiErrorResponse::make(
                    ApiErrorCode::ValidationFailed,
                    message: $e->getMessage(),
                    errors: $e->errors(),
                );
            }
        });

        // 429 del throttle: mensaje y código del catálogo, conservando las
        // cabeceras Retry-After y X-RateLimit del rate limiter.
        $this->renderable(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiErrorResponse::make(ApiErrorCode::TooManyAttempts, headers: $e->getHeaders());
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
