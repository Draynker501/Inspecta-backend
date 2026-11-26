<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class ProfileRateLimitKey
{
    public static function resolve(Request $request): string
    {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken) || $bearerToken === '') {
            return self::ipKey($request);
        }

        $accessToken = PersonalAccessToken::findToken(
            $bearerToken
        );

        if ($accessToken === null) {
            return self::ipKey($request);
        }

        // Comprobar la expiración explícita del token.
        if (
            $accessToken->expires_at !== null
            && $accessToken->expires_at->isPast()
        ) {
            return self::ipKey($request);
        }

        // Comprobar la expiración global de Sanctum.
        $expiration = config('sanctum.expiration');

        if (
            is_numeric($expiration)
            && (int) $expiration > 0
            && $accessToken->created_at !== null
            && $accessToken->created_at->lte(
                now()->subMinutes((int) $expiration)
            )
        ) {
            return self::ipKey($request);
        }

        $user = $accessToken->tokenable;

        // No asignar una cuota personal a tokens cuyo
        // propietario ya no existe o está desactivado.
        if (
            ! ($user instanceof User)
            || ! $user->status
        ) {
            return self::ipKey($request);
        }

        return 'user:'.$user->getKey();
    }

    private static function ipKey(Request $request): string
    {
        return 'ip:'.$request->ip();
    }
}
