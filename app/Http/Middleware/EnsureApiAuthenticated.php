<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureApiAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'No autenticado. Token de acceso ausente.',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'message' => 'No autenticado. Token de acceso inválido.',
            ], 401);
        }

        if (!$accessToken->tokenable) {
            return response()->json([
                'message' => 'No autenticado. Token de acceso inválido.',
            ], 401);
        }

        if (!$accessToken->tokenable->status) {
            return response()->json([
                'message' => 'El usuario está inactivo. Contacte al administrador.',
            ], 403);
        }

        Auth::setUser($accessToken->tokenable);

        return $next($request);
    }
}
