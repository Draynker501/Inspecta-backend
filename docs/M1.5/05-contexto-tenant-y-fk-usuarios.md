# 05 — Contexto de tenant y validación de FK a usuarios

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1)

**Objetivo:** precisar cómo se evita una consulta sin contexto de tenant en HTTP y en código
interno, y cómo se validan las FK a usuarios en creación y actualización sin bloquear registros
históricos.
**Estado:** propuesta. Nada implementado.

---

## 1. Punto de partida confirmado

| Hecho | Evidencia | Consecuencia |
|---|---|---|
| `OrganizationScope` sin contexto **no filtra** | `app/Support/Tenancy/OrganizationScope.php:18-25` | Una consulta olvidada devuelve todas las organizaciones. |
| `TenantContext` es un singleton por proceso | `app/Support/Tenancy/TenantContext.php` | En Octane o pruebas con varias peticiones hay que limpiarlo; ya lo hace `ResetTenantContext`. |
| `ResetTenantContext` limpia al inicio y en `terminate()` | `app/Http/Middleware/ResetTenantContext.php` | Base correcta; se conserva. |
| `api.tenant` resuelve el binding **después** del tenant | `bootstrap/app.php:38-42` | Un id ajeno produce 404, no 403. Comportamiento a preservar. |
| `TenantContext::withoutTenant()` no recibe motivo | `TenantContext.php:64-80` | No es auditable ni greppable por intención. |
| `assertTenantParents()` se ejecuta en `creating` y en `updating` | `BelongsToOrganization.php:31-38` | En update revalida **todas** las FK, no solo las modificadas. |
| `TenantPolicy` deniega sin contexto | `app/Policies/TenantPolicy.php:54-71` | Fail-closed ya vigente en autorización. |

---

## 2. HTTP: cadena y garantías

### 2.1 Orden definitivo

```text
ResetTenantContext              (prepend global, limpia estado)
  └─ api.auth   EnsureApiAuthenticated
       └─ ResolveTenantContext    (cabecera + membresía + estado + tipo)
            └─ SubstituteBindings
                 └─ Controlador (extiende TenantController)
                      └─ Policy (membresía + rol en contexto)
```

Sin cambios en el orden ni en `EnsureApiAuthenticated`. El handler 429 no se toca.

### 2.2 `ResolveTenantContext` objetivo

Pseudocódigo del comportamiento (no implementado):

```text
1. context->clear()
2. user = request->user()                     → si no, 401
3. si !user->status                           → 403 user_inactive
4. header = request->header('X-Organization-Id')
5. si header ausente o vacío                  → 400 organization_required
6. si header no es UUID válido                → 400 organization_invalid
7. org = Organization::withTrashed()->find(header)
8. si !org o org->trashed() o !org->status    → 403 organization_access_denied
9. si org->type !== 'client'                  → 403 organization_not_tenant
10. membership = OrganizationMembership
        ->where(user_id, user->id)
        ->where(organization_id, org->id)
        ->first()
11. si !membership o !membership->status      → 403 organization_access_denied
12. context->set(org->id)
13. next(request)
```

Reglas:

- Los pasos 8 y 11 devuelven **el mismo cuerpo** (`organization_access_denied`) para no permitir
  enumerar organizaciones por diferencia de respuesta.
- El paso 9 usa un mensaje distinto **solo** porque el cliente nunca debe enviar el id del
  operador; no revela información de terceros.
- La cabecera **nunca** se acepta desde query string ni body.
- El paso 10 usa el índice único `(user_id, organization_id)`; costo de una búsqueda por índice.

### 2.3 `TenantController`

Clase base nueva para controladores de dominio:

```text
abstract class TenantController extends Controller
{
    public function __construct()
    {
        app(TenantContext::class)->requireId();
    }
}
```

- Todos los controladores de dominio deben extenderla.
- Excepciones permitidas: `AuthController`, health checks y cualquier controlador de plataforma
  explícitamente autorizado.
- Una **prueba de arquitectura** recorre `app/Http/Controllers` y falla si un controlador no
  extiende `TenantController` y no está en la lista de excepciones.

Esto convierte “no olvidarse del middleware” en un fallo de suite, no en una convención.

### 2.4 Cobertura de rutas

Prueba obligatoria: toda ruta registrada bajo `/api` debe estar en uno de estos conjuntos:

- `api.tenant` (dominio), o
- lista explícita de rutas solo-auth (`/auth/login`, `/auth/logout`, `/auth/me`,
  `/auth/organizations`), o
- lista explícita de rutas públicas (ninguna en M1.5 además de login).

La prueba actual `TenantRoutingTest` ya existe; se amplía.

---

## 3. Código interno: jobs, consola y servicios

### 3.1 `TenantContext` ampliado

```text
requireId(): string
    → devuelve el id o lanza MissingTenantContextException

withoutTenant(string $reason, Closure $callback): mixed
    → igual que hoy, pero exige motivo no vacío; el motivo se registra
```

`MissingTenantContextException` ya existe (`app/Exceptions/MissingTenantContextException.php`); se
reutiliza y se le añade render controlado si llega a HTTP (no debería: el middleware lo impide).

### 3.2 Jobs

- Un job que toque modelos tenant-scoped debe declarar `public string $organizationId` en su
  payload y usar un trait `TenantAwareJob`.
- `handle()` valida que la organización exista, esté activa y sea `client`; luego ejecuta dentro de
  `runAs($organizationId, ...)`.
- Si el job no recibe organización, falla de forma explícita antes de tocar la base de datos.
- Los jobs multiempresa (consolidados) deben implementar una interfaz marcadora
  `CrossTenantJob` y ser aprobados explícitamente; no se implementan en M1.5.

Hoy no existe ningún job (H13), así que esto es diseño preventivo y no rompe nada.

### 3.3 Comandos de consola

- Los comandos de dominio extienden `TenantCommand`, que exige `--organization=` y valida que sea
  una organización `client` activa.
- Los comandos de mantenimiento usan `withoutTenant('motivo')` y quedan en una lista allowlist
  verificada por prueba.
- Hoy `routes/console.php` solo define `inspire`; no hay comandos que adaptar.

### 3.4 Servicios y repositorios

- Reciben la organización como parámetro explícito **o** llaman a `requireId()`.
- Prohibido leer `users.organization_id` para decidir el tenant.
- Prohibido usar `withoutTenant()` dentro de un controlador.

### 3.5 Prueba de intención

Una prueba recorre `app/` y busca `withoutTenant(`; compara los resultados con una allowlist
versionada (backfill, seeders, comprobación de rol de plataforma, migraciones de datos). Cualquier
uso nuevo sin actualizar la allowlist hace fallar la suite.

---

## 4. Validación de FK a usuarios

### 4.1 Situación actual

`BelongsToOrganization::assertTenantParents()` recorre `tenantParentRelations()` y, para cada FK,
comprueba que el padre exista **y** tenga el mismo `organization_id` que el modelo. Diez modelos
declaran `User` como padre tenant-scoped:

`UserRole`, `UserPlant`, `UserArea`, `ChecklistVersion`, `Inspection` (dos FK), `Incident`,
`Evidence`, `CorrectiveAction`, `CorrectiveActionUpdate`, `AuditLog`.

Con identidad global, `users.organization_id` ya no expresa pertenencia, así que la comprobación
actual es incorrecta por diseño.

### 4.2 Regla nueva

| Caso | Comprobación |
|---|---|
| Padre ≠ `User` | Igual que hoy: el padre existe y su `organization_id` coincide con el del modelo |
| Padre = `User`, en **creación** | Existe membresía **activa** `(user_id, organization_id)` y `users.status = true` |
| Padre = `User`, en **actualización** | Solo si la FK está `isDirty()` |
| Padre = `User`, en actualización de otros campos | **No se valida** |
| FK nula | Se omite (comportamiento actual) |
| Actor del registro ≠ usuario asignado | La regla aplica al usuario referenciado, no al actor |

### 4.3 Implementación propuesta

```text
assertTenantParents(bool $onlyDirty = false)

foreach (tenantParentRelations() as $fk => $parentClass) {
    if ($onlyDirty && ! $this->isDirty($fk)) continue;
    if ($parentId === null) continue;

    if (is_a($parentClass, User::class, true)) {
        assertActiveMembership($parentId, $organizationId, $fk);
    } else {
        // comprobación actual por organization_id
    }
}

assertActiveMembership():
    exists = OrganizationMembership
        ->where(user_id, $parentId)
        ->where(organization_id, $organizationId)
        ->where(status, 'active')
        ->exists()
    AND users.status = true
    si no → CrossTenantOperationException::relation($fk)
```

Y en el boot:

```text
creating → assertTenantOnCreate(); assertTenantParents(onlyDirty: false)
updating → assertTenantOnUpdate(); assertTenantParents(onlyDirty: true)
```

### 4.4 Por qué “solo dirty” no debilita el aislamiento

- `organization_id` sigue siendo inmutable desde HTTP (`assertTenantOnUpdate`).
- Las FK que no cambian ya fueron validadas cuando se escribieron.
- Una FK existente no puede “migrar” de organización sin cambiar de valor, y el valor no cambia.
- Lo que se evita es revalidar contra un estado **actual** (membresía revocada) un hecho
  **histórico** (el registro se creó cuando la membresía era válida).

### 4.5 Consecuencias prácticas

| Escenario | Antes | Después |
|---|---|---|
| Editar una inspección de 2025 cuyo inspector ya no tiene acceso | 422 (bloqueado) | 200 (permitido) |
| Reasignar `assigned_to` a un usuario sin membresía | 422 | 422 |
| Crear una inspección con `user_id` sin membresía | 422 | 422 |
| Actualizar solo `status` de una inspección histórica | 422 si el inspector fue revocado | 200 |
| Revocar membresía y escribir auditoría | 422 si `AuditLog` valida al actor | 200 (excepción `AuditLogger`) |

### 4.6 Costo

Una consulta por FK a usuario y por escritura, resuelta por el índice único de membresías. El número
de FK a usuario por modelo es 1 o 2; no requiere optimización adicional en M1.5.

---

## 5. Excepción de auditoría

`AuditLog` declara `User` como padre tenant-scoped. Con la regla nueva, escribir auditoría de una
revocación fallaría, porque el actor acaba de perder la membresía.

Solución: `AuditLogger` (servicio nuevo) escribe con:

- `organization_id` = organización afectada,
- `user_id` = actor global,
- sin pasar por `assertTenantParents()` para `user_id`,
- dentro de `TenantContext::runAs($organizationId, ...)` para que el scope aplique al registro.

La excepción se documenta en el propio servicio y se cubre con la prueba T43. No se extiende a
ningún otro modelo.

---

## 6. Riesgo residual y su evolución

| Riesgo | Estado en M1.5 | Evolución |
|---|---|---|
| `Model::query()` sin contexto devuelve todas las organizaciones | Mitigado en fronteras, no imposible | P5: modo estricto del scope |
| Un servicio nuevo olvida `requireId()` | Mitigado por `TenantController` y pruebas de arquitectura | Revisión de código |
| Un job no recibe organización | Falla explícito | — |
| Un comando de mantenimiento usa `withoutTenant` sin justificar | Allowlist + prueba | — |
| Controlador nuevo no extiende `TenantController` | Prueba de arquitectura falla | — |

**Por qué no se activa el modo estricto del scope en P3:** rompería seeders, factories, migraciones
y consola en el mismo cambio que introduce el contexto. Se hace en P5, cuando todo el código ya
declara su intención con `withoutTenant('motivo')`.

---

## 7. Decisiones solicitadas

- **D-07:** validación de FK a usuario por membresía activa y solo *dirty* en actualización.
- **D-08:** fail-closed en fronteras en P3; modo estricto del scope en P5.
- **D-11:** `AuditLogger` como excepción documentada.
