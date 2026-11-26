<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileService
{
    /**
     * Actualizar la identidad global del usuario autenticado.
     */
    public function updateProfile(
        User $actor,
        array $data
    ): User {
        try {
            [$user, $changedFields] = DB::transaction(
                function () use ($actor, $data): array {
                    $user = User::query()
                        ->whereKey($actor->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    abort_unless(
                        (bool) $user->status,
                        401
                    );

                    $emailChanged =
                        array_key_exists('email', $data)
                        && $data['email'] !== $user->email;

                    if ($emailChanged) {
                        $currentPassword =
                            $data['current_password'] ?? null;

                        if (
                            ! is_string($currentPassword)
                            || ! Hash::check(
                                $currentPassword,
                                $user->password
                            )
                        ) {
                            throw ValidationException::withMessages([
                                'current_password' => [
                                    'La contraseña actual es incorrecta.',
                                ],
                            ]);
                        }
                    }

                    if (array_key_exists('name', $data)) {
                        $user->name = $data['name'];
                    }

                    if ($emailChanged) {
                        $user->email = $data['email'];

                        $user->email_verified_at = null;
                    }

                    $changedFields = array_keys(
                        $user->getDirty()
                    );

                    if ($user->isDirty()) {
                        $user->save();
                    }

                    return [
                        $user,
                        $changedFields,
                    ];
                }
            );
        } catch (UniqueConstraintViolationException $exception) {
            if (! array_key_exists('email', $data)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => [
                    'El correo electrónico ya está registrado.',
                ],
            ]);
        }

        if ($changedFields !== []) {
            Log::info('user.profile_updated', [
                'user_id' => $user->getKey(),
                'changed_fields' => $changedFields,
            ]);
        }

        return $user;
    }

    /**
     * Cambiar la contraseña del usuario autenticado.
     *
     * Conserva el token actual y revoca los demás tokens
     * personales del usuario.
     */
    public function updatePassword(
        User $actor,
        array $data,
        string $bearerToken
    ): void {
        $revokedTokens = DB::transaction(
            function () use (
                $actor,
                $data,
                $bearerToken
            ): int {
                $user = User::query()
                    ->whereKey($actor->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    (bool) $user->status,
                    401
                );

                $currentToken =
                    PersonalAccessToken::findToken(
                        $bearerToken
                    );

                abort_unless(
                    $currentToken !== null
                    && $currentToken->tokenable_type
                        === $user->getMorphClass()
                    && (string) $currentToken->tokenable_id
                        === (string) $user->getKey(),
                    401
                );

                // Confirmar que el token continúa existiendo
                // después de adquirir el bloqueo del usuario.
                $currentToken = $user->tokens()
                    ->whereKey($currentToken->getKey())
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $currentToken !== null,
                    401
                );

                if (
                    $currentToken->expires_at !== null
                    && $currentToken->expires_at->isPast()
                ) {
                    abort(401);
                }

                $expiration = config('sanctum.expiration');

                if (
                    is_numeric($expiration)
                    && (int) $expiration > 0
                    && $currentToken->created_at !== null
                    && $currentToken->created_at->lte(
                        now()->subMinutes((int) $expiration)
                    )
                ) {
                    abort(401);
                }

                if (
                    ! Hash::check(
                        $data['current_password'],
                        $user->password
                    )
                ) {
                    throw ValidationException::withMessages([
                        'current_password' => [
                            'La contraseña actual es incorrecta.',
                        ],
                    ]);
                }

                // Verificación contra el hash vigente,
                // además de la validación different.
                if (
                    Hash::check(
                        $data['password'],
                        $user->password
                    )
                ) {
                    throw ValidationException::withMessages([
                        'password' => [
                            'La nueva contraseña debe ser diferente de la actual.',
                        ],
                    ]);
                }

                // Tratar siempre la contraseña recibida
                // como texto plano, incluso cuando tenga
                // la apariencia de un hash válido.
                $user->password = Hash::make(
                    $data['password']
                );

                // Invalidar posibles sesiones persistentes
                // basadas en remember_token.
                $user->setRememberToken(null);

                $user->save();

                // Revocar todos los tokens excepto el actual.
                return $user->tokens()
                    ->where(
                        'id',
                        '!=',
                        $currentToken->getKey()
                    )
                    ->delete();
            }
        );

        Log::info('user.password_updated', [
            'user_id' => $actor->getKey(),
            'revoked_tokens_count' => $revokedTokens,
        ]);
    }
}
