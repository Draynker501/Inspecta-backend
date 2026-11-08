# Motivos válidos para `withoutTenant()` (D-08 / D-36)

Fuente única: `App\Support\Tenancy\WithoutTenantReason`.

| Constante | Valor | Uso permitido |
|---|---|---|
| `PLATFORM_ROLE_CHECK` | `platform-role-check` | Verificación de rol de plataforma fuera del tenant activo (`User::hasPlatformRole`). |
| `CONSOLE_DIAGNOSTIC` | `diagnóstico de consola` | Diagnósticos y comandos Artisan en consola. |
| `QUERY_DIAGNOSTIC` | `consulta de diagnóstico sin tenant` | Consultas de diagnóstico sin contexto organizacional. |
| `TEST` | `test` | Pruebas automatizadas. |

Reglas:
- `withoutTenant()` está prohibido en `app/Http/Controllers/`.
- En `app/` y `database/` solo está permitido en `app/Models/User.php` (rol de plataforma).
- Todo otro uso requiere justificación y adición explícita a la lista permitida.