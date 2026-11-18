# INSPECTA — Registro de correcciones de API, OpenAPI y pruebas

**Repositorio:** `Draynker501/Inspecta-backend`  
**Rama de trabajo:** `integracion-api`  
**Pull Request:** [#46](https://github.com/Draynker501/Inspecta-backend/pull/46)  
**Fecha:** 17 de noviembre de 2026  
**Estado:** Correcciones validadas localmente; pendiente de confirmar el resultado de CI tras el push.

## 1. Objetivo

Alinear las rutas implementadas en Laravel con el contrato OpenAPI y actualizar las pruebas automatizadas para reflejar los endpoints reales. Resolver, además, fallos detectados al ejecutar la suite completa en Windows/XAMPP, sin desactivar controles de seguridad.

## 2. Cambios realizados

### 2.1. Ruta de actualización de estado de incidentes

Se alineó el endpoint de actualización de estado de incidentes con la especificación OpenAPI:

```php
Route::patch('incidents/{incidentId}/status', [IncidentController::class, 'updateStatus'])
    ->middleware('api.tenant');
```

**Ruta resultante:** `PATCH /api/incidents/{incidentId}/status`.

En OpenAPI, la operación corresponde a `/incidents/{incidentId}/status` e incluye el parámetro reutilizable `organizationIdHeader`.

### 2.2. Pruebas de integración de incidentes

**Archivo:** `tests/Feature/Incidents/IncidentIntegrationTest.php`.

Se actualizaron las seis ubicaciones de llamadas `patchJson()` que modifican estados para utilizar `/api/incidents/{$id}/status`. Se mantuvieron las pruebas de creación, lectura, autorización, aislamiento organizacional, transición de estados, validación y auditoría.

**Resultado observado:** las 10 pruebas de integración de incidentes aprobaron.

### 2.3. Contrato OpenAPI

Se revisó la correspondencia entre operaciones documentadas y rutas registradas, así como las marcas `x-status: planned` de endpoints ya implementados.

Las pruebas de contrato que previamente detectaban discrepancias pasaron satisfactoriamente:

- `OpenApiPlannedStatusContractTest`.
- `OpenApiRouteCorrespondenceTest`.

También se ejecutaron satisfactoriamente las demás pruebas OpenAPI mostradas en la suite.

### 2.4. Catálogo de rutas permitidas

**Archivo:** `tests/Feature/Api/RouteSurfaceTest.php`.

La prueba tenía un catálogo de operaciones desactualizado. Se incorporaron 16 operaciones adicionales correspondientes a preguntas, incidentes, acciones correctivas y evidencias:

```text
PATCH api/questions/{questionId}
GET api/incidents
POST api/incidents
GET api/incidents/{incidentId}
PATCH api/incidents/{incidentId}/status
GET api/corrective-actions
POST api/corrective-actions
GET api/corrective-actions/{actionId}
PATCH api/corrective-actions/{actionId}
GET api/corrective-actions/{actionId}/updates
POST api/corrective-actions/{actionId}/updates
GET api/evidences
POST api/evidences
GET api/evidences/{evidenceId}
GET api/evidences/{evidenceId}/download
GET api/evidences/{evidenceId}/preview
```

Para evitar la duplicación de catálogos, se acordó utilizar `$expected` tanto para comprobar operaciones obligatorias como para detectar rutas inesperadas:

```php
$unexpected = array_values(array_diff($apiOperations, $expected));
```

Así se conservan las dos verificaciones: que estén todas las rutas esperadas y que no existan rutas adicionales no autorizadas.

### 2.5. Compatibilidad de pruebas de tenancy con Windows

**Archivos:**

- `tests/Feature/Tenancy/MembershipSingleSourceTest.php`.
- `tests/Feature/Tenancy/WithoutTenantUsageTest.php`.

Ambas pruebas comparaban rutas de archivos contra listas de excepciones autorizadas. En Windows, la mezcla de separadores `\` y `/` impedía reconocer archivos ya permitidos.

Se normalizaron las rutas para obtener identificadores relativos con separadores `/`:

```php
$relative = str_replace('\\', '/', $file);
$relative = substr($relative, strlen(str_replace('\\', '/', base_path())) + 1);
```

**Casos afectados:**

- `OrganizationMembership.php`: contiene el punto central autorizado de consulta de membresías activas.
- `User.php`: utiliza `withoutTenant()` para verificar el rol de operador de plataforma mediante una excepción expresamente permitida.

No se eliminaron las comprobaciones de seguridad ni se amplió indiscriminadamente el uso permitido de `withoutTenant()`.

## 3. Resultados de las pruebas

Durante la revisión se obtuvieron estos resultados:

| Momento | Aprobadas | Fallidas | Omitidas | Aserciones |
|---|---:|---:|---:|---:|
| Primera ejecución completa compartida | 757 | 3 | 93 | 4,647 |
| Ejecución posterior a las correcciones de tenancy | 759 | 1 | 93 | 4,663 |
| Verificación final comunicada | Todas las ejecutadas | 0 reportadas | Pendientes de ejecución | No se adjuntó salida completa |

Los tres fallos originales correspondían a `RouteSurfaceTest`, `MembershipSingleSourceTest` y `WithoutTenantUsageTest`. Tras corregir las rutas de archivos, solo persistió `RouteSurfaceTest`, que se resolvió al unificar el catálogo de rutas esperado y permitido.

**Nota:** Las 93 pruebas omitidas no equivalen a pruebas superadas. Varias requieren un entorno MariaDB aislado o efímero y deberán verificarse en el entorno correspondiente o mediante los workflows de CI configurados.

## 4. Comandos de verificación

```bash
php artisan test tests/Feature/Incidents/IncidentIntegrationTest.php
php artisan test --filter=OpenApi
php artisan test tests/Feature/Api/RouteSurfaceTest.php
php artisan test tests/Feature/Tenancy/MembershipSingleSourceTest.php tests/Feature/Tenancy/WithoutTenantUsageTest.php
php artisan test
vendor/bin/pint --test
```

## 5. Commit y actualización del PR

**Mensaje de commit propuesto:**

```text
fix: sincronizar rutas API, OpenAPI y pruebas de integracion
```

**Comandos:**

```bash
git branch --show-current
git status
git diff --check
git add .
git commit -m "fix: sincronizar rutas API, OpenAPI y pruebas de integracion"
git push origin integracion-api
```

El push sobre `integracion-api` actualiza el PR #46 existente, dirigido a `master`.

## 6. Pendientes antes del merge

1. Confirmar que los checks de GitHub Actions terminen correctamente después del push.
2. Revisar el resultado de Laravel Pint en CI y corregir cualquier diferencia de formato.
3. Ejecutar o validar las pruebas específicas de MariaDB que quedaron omitidas en la ejecución local.
4. Revisar los cambios finales del PR #46 antes de aprobar su integración a `master`.

## 7. Conclusión

Las rutas de incidentes, el contrato OpenAPI y las pruebas automatizadas quedaron alineados en el entorno local. Se corrigieron también errores de catálogo de endpoints y de compatibilidad de rutas de archivos en Windows, manteniendo los controles de aislamiento organizacional. La integración a `master` queda condicionada a la revisión del PR y de sus verificaciones de CI, especialmente las pruebas dependientes de MariaDB.
