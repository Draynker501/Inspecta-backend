<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureApiAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return ApiErrorResponse::make(ApiErrorCode::Unauthenticated);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        // Token inválido o usuario emisor eliminado son indistinguibles:
        // mismo código unauthenticated y mismo mensaje (D-31b).
        if (! $accessToken || ! $accessToken->tokenable) {
            return ApiErrorResponse::make(ApiErrorCode::Unauthenticated);
        }

        if (! $accessToken->tokenable->status) {
            return ApiErrorResponse::make(ApiErrorCode::UserInactive);
        }

        Auth::setUser($accessToken->tokenable);

        return $next($request);
    }
}
