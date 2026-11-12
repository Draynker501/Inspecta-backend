# Informe de cierre documental definitivo — M2.3 (Policies y alcance por asignaciones)

> **Estado histórico al 2026-11-11.** M2.3 se integró posteriormente mediante PR #28, commit `66299cfa3778ecb9828053ecefbb969c2075bc16` (2026-11-12). Las referencias de publicación e integración en este informe son una fotografía anterior. Ver `09-acta-cierre-m2.md`.

**Fecha de cierre documental:** 2026-11-11
**Rama:** `feat/m2-3-policies-recursos`
**HEAD:** `be37d736237faf3424c7bc01532f6cd17ccb03a8`
**Base de M2.3:** `be37d736237faf3424c7bc01532f6cd17ccb03a8` (M2.2 integrada en `master`)
**Veredicto documental:** LISTA PARA PUBLICACIÓN CON SALVEDADES DOCUMENTADAS

---

## A. Resumen ejecutivo

M2.3 implementa autorización por recurso para `Plant`, `Area` y `ProductionLine`, eliminando atajos por nombre de rol y expresando el alcance organizacional mediante el permiso dedicado `locations.view_all`. La implementación fue auditada técnicamente y validada en SQLite y MariaDB 11.8.

## B. Alcance implementado

- Permiso `locations.view_all` añadido al catálogo.
- `PlantPolicy`, `AreaPolicy` y `ProductionLinePolicy` adaptadas a `plants.view`, `plants.manage` y `locations.view_all`.
- Herencia de asignaciones implementada.
- Aislamiento organizacional y denegación por defecto mantenidos.
- 14 pruebas nuevas en `LocationScopePolicyTest`.
- Adaptación de pruebas históricas para otorgar permisos explícitos.

No se añadieron endpoints, rutas de producción ni cambios en OpenAPI.

## C. Decisiones arquitectónicas

D-42 queda consolidada:

- Capacidad: evaluada exclusivamente mediante `User::hasPermission()`.
- Alcance: determinado por Policies y asignaciones organizacionales.
- Administrador: alcance organizacional completo según permisos.
- Supervisor: alcance organizacional completo para operaciones permitidas.
- Inspector: alcance por asignaciones.
- Planta asignada: planta, áreas y líneas descendientes.
- Área asignada: área y sus líneas; no planta padre ni áreas hermanas.
- Líneas: sin asignaciones directas.
- `locations.view_all`: omite asignaciones solo para lectura.

D-40 se mantiene: catálogo en código, único punto de evaluación, denegación por defecto, sin tablas ni columnas nuevas.

## D. Matriz de autorización definitiva

| Operación | Permiso | Alcance |
|---|---|---|
| `viewAny` | `plants.view` | Capacidad general; filtrado de colecciones diferido a M2.4 |
| `view` Plant | `plants.view` + (`locations.view_all` o `user_plants`) | Tenant activo |
| `view` Area | `plants.view` + (`locations.view_all` o `user_areas` o planta padre asignada) | Tenant activo |
| `view` ProductionLine | `plants.view` + (`locations.view_all` o área asignada o planta padre asignada) | Tenant activo |
| `create/update/delete/restore/forceDelete` | `plants.manage` | Tenant activo |
| `locations.view_all` | — | Solo lectura organizacional |

## E. Archivos modificados

Producción:
- `app/Authorization/Permission.php`
- `app/Policies/PlantPolicy.php`
- `app/Policies/AreaPolicy.php`
- `app/Policies/ProductionLinePolicy.php`

Pruebas:
- `tests/Feature/Tenancy/LocationScopePolicyTest.php` (nuevo)
- `tests/Feature/Tenancy/TenantPolicyTest.php`
- `tests/Feature/Tenancy/TenantIsolationTest.php`
- `tests/Support/TenantFixtures.php`

Documentación:
- `docs/M2/01-modelo-de-autorizacion.md`
- `docs/M1.5/decisiones/REGISTRO_DECISIONES.md`
- `docs/M1.5/avance/ESTADO.md`
- `docs/M2/03-informe-cierre-m2-3.md`

## F. Validaciones

**SQLite:**
- 391 pruebas.
- 382 aprobadas.
- 9 omitidas.
- 1215 aserciones.
- 0 fallos.

**MariaDB 11.8:**
- 391 pruebas.
- 383 aprobadas.
- 8 omitidas.
- 1244 aserciones.
- 0 fallos.

Pint, Composer validate, Composer audit y git diff --check: satisfactorios.

## G. Pruebas de sensibilidad por mutación

Mutaciones aplicadas en copias temporales y revertidas:

| Mutación | Resultado |
|---|---|
| Eliminación de `plants.view` | Detectada |
| Eliminación de `plants.manage` en escritura | Detectada |
| Omisión del tenant (`parent::view`) | Detectada |
| Omisión de membresía activa | Cubierta por `hasPermission` y `TenantContext` |
| Omisión de asignaciones (`locations.view_all` implícito) | Detectada |
| Escritura mediante `locations.view_all` | Detectada |
| Acceso cross-tenant | Detectada |

No se contabilizan errores de sintaxis ni infraestructura.

## H. Hallazgos y resoluciones

### Hallazgo M2.3-01 — Filtrado de colecciones

`viewAny()` autoriza la capacidad general, pero no filtra automáticamente sus colecciones. Requisito obligatorio para M2.4: toda consulta deberá filtrar por organización, `plants.view`, `locations.view_all` y asignaciones con herencia. Clasificación: MEDIO, diferido con requisito verificable.

### Hallazgo M2.3-02 — Alcance de escritura

`plants.manage` habilita gestión de ubicaciones dentro de toda la organización activa, sin asignaciones individuales. Su concesión debe ser explícita. La plantilla de Administrador deberá incluirla; Supervisor e Inspector no. `locations.view_all` no habilita escritura. Esta semántica es decisión autorizada, no vulnerabilidad. El aprovisionamiento efectivo queda diferido.

### Hallazgos secundarios

- Posible N+1 por carga lazy de `Area` en `ProductionLinePolicy`.
- Preparación automática de permisos en `TenantFixtures::admin()`.
- Necesidad de permisos explícitos en pruebas específicas de autorización.
- Ausencia de concesión retroactiva automática de `locations.view_all`.
- `restore` y `forceDelete` protegidos mediante `plants.manage`.

## I. Riesgos residuales

- Filtrado de colecciones en endpoints futuros.
- Aprovisionamiento de plantillas de rol no implementado.
- `reports.view_own` pendiente de definición.
- Revocación de membresías y endpoints de producción diferidos.

## J. Dependencias de M2.4

- Implementar filtrado de colecciones por asignaciones.
- Confirmar endpoint de corte vertical.
- Mantener permisos y policies actuales.

## K. Estado de Git

- Rama: `feat/m2-3-policies-recursos`
- HEAD: `be37d736237faf3424c7bc01532f6cd17ccb03a8`
- Cambios sin commit: 12 archivos (10 modificados + 2 nuevos).
- Sin push, PR ni merge.

## L. Veredicto final

LISTA PARA PUBLICACIÓN CON SALVEDADES DOCUMENTADAS.

Al momento de este informe documental (2026-11-11), M2.3 no se consideraba integrada hasta confirmar el merge. Posteriormente se fusionó PR #28 el 2026-11-12; esta frase es histórica.

## M. No ejecutado / no verificado

- No se ejecutó push, PR ni merge.
- No se verificó en servidor persistente (Octane/workers).
- No existen endpoints de producción para validar respuestas HTTP de M2.3.
