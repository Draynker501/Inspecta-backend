# Informe de cierre documental definitivo — M2.4 (GET /api/plants)

> **Estado histórico al 2026-11-11.** La implementación M2.4 se integró mediante PR #29 (`04ad0c01724904fa2a63c47057494532d593f892`) y el cierre documental mediante PR #30 (`95ff7dbc811a7f5814acb667b1c4764a2e329b83`), ambos el 2026-11-12. Las notas que dicen que no se pudo verificar la integración describen esa revisión anterior. Ver `09-acta-cierre-m2.md`.

**Fecha de cierre documental:** 2026-11-11
**Rama:** `feat/m2-4-get-plants`
**HEAD base:** `75c374a450f645286b17ded211e45b1ac6e50017`
**Veredicto documental:** LISTA PARA PUBLICACIÓN CON SALVEDADES DOCUMENTADAS

---

## A. Resumen ejecutivo

M2.4 implementa el primer corte vertical HTTP: `GET /api/plants`, con autenticación Sanctum, selección organizacional, autorización por permisos, filtrado SQL por asignaciones y paginación. Fue auditado técnicamente y validado en SQLite y MariaDB 11.8. OpenAPI se actualizó a 2.2.0.

## B. Alcance implementado

- Ruta: `GET /api/plants`.
- Middleware: `api.tenant`.
- Controlador: `PlantController::index()`.
- Autorización: `plants.view` mediante `PlantPolicy`.
- Alcance: `locations.view_all` o `user_plants`.
- Filtrado SQL antes de paginar.
- Ordenamiento por `name`, `id`.
- Respuesta `{ data, meta }`.
- OpenAPI 2.2.0 con `GET /plants` implementado.

No se implementaron otros endpoints ni `include_hierarchy`.

## C. Arquitectura HTTP

1. `ResetTenantContext` limpia el contexto.
2. `MarkHttpRequest` marca la petición.
3. `EnsureApiAuthenticated` valida Sanctum.
4. `ResolveTenantContext` resuelve `X-Organization-Id` y membresía activa.
5. `SubstituteBindings`.
6. `TenantController` exige contexto.
7. `PlantController::index()` autoriza y consulta.
8. La respuesta serializa solo campos de `Plant`.

## D. Matriz de autorización

| Escenario | Resultado |
|---|---|
| Token válido + `plants.view` + plantas visibles | 200 |
| Token válido + `plants.view` + sin plantas visibles | 200 con `data: []` |
| Sin token | 401 |
| Sin `plants.view` | 403 `forbidden` |
| `locations.view_all` sin `plants.view` | 403 |
| Falta `X-Organization-Id` | 400 de contexto |
| Membresía revocada | 403 de contexto |
| Organización inactiva | 403 de contexto |
| Cambio de organización | Sin filtración |

## E. Filtrado SQL y aislamiento

La consulta:

- filtra por `organization_id`;
- respeta `OrganizationScope`;
- aplica `plants.view`;
- usa `locations.view_all` solo para omitir asignaciones;
- consulta `user_plants` con `user_id` y `organization_id`;
- filtra antes de paginar;
- excluye soft-deleted;
- ordena por `name`, `id`.

El filtro organizacional explícito es redundante con `OrganizationScope`; se conserva como defensa adicional.

## F. Contrato OpenAPI 2.2.0

- `info.version`: `2.2.0`.
- Solo `GET /plants` implementado.
- `POST /plants` y demás operaciones planeadas.
- Parámetros `page` y `per_page`.
- Respuestas 200, 400, 401, 403 y 422.
- `include_hierarchy` no disponible.

## G. Pruebas SQLite y MariaDB

**SQLite:**
- 409 pruebas.
- 400 aprobadas.
- 9 omitidas.
- 1257 aserciones.
- 0 fallos.

**MariaDB 11.8:**
- 409 pruebas.
- 401 aprobadas.
- 8 omitidas.
- 1286 aserciones.
- 0 fallos.

Pint, Composer validate, Composer audit y git diff --check: satisfactorios.

## H. Matriz de cobertura HTTP

`PlantsIndexTest` incluye 18 pruebas. Cobertura directa para autenticación, contexto, permisos, filtrado, paginación, parámetros inválidos y cambios dinámicos. Cobertura indirecta documentada para token inválido, organización inactiva y membresía revocada cuando corresponde.

## I. Mutaciones y sensibilidad

Mutaciones ejecutadas en copias temporales:

| Mutación | Resultado |
|---|---|
| Eliminar autorización `plants.view` | Detectada |
| Eliminar filtro de asignaciones | Detectada |
| Eliminar filtro organizacional explícito | Equivalente demostrada (`OrganizationScope`) |
| Conceder `locations.view_all` implícitamente | Detectada |
| Paginar antes de filtrar | Cubierta por filtrado previo |
| Incluir soft-deleted | Detectada |
| Usar `isAdmin()` como bypass | No presente |
| Alterar `meta.total` | Cubierta por filtrado previo |
| Eliminar filtro de organización del pivote | Equivalente demostrada |
| Omitir middleware de contexto | Cubierta por `TenantController` |

No se contabilizan errores de sintaxis ni infraestructura.

## J. Hallazgos y salvedades

### H-M2.4-01 — Filtrado de colecciones

El filtrado depende de la consulta implementada. Las futuras rutas de colecciones deberán reutilizar el patrón.

### H-M2.4-02 — Rendimiento SQL

Evaluar índices y planes de ejecución con volúmenes representativos.

### H-M2.4-03 — Jerarquía

`include_hierarchy` no implementado.

### H-M2.4-04 — Entornos persistentes

No validado en Octane/workers. Salvedad conservada sin afirmar vulnerabilidad.

## K. Riesgos residuales

- Filtrado de colecciones en endpoints futuros.
- Optimización SQL.
- Validación en entornos persistentes.
- Aprovisionamiento de plantillas de rol.

## L. Estado de Git

- Rama: `feat/m2-4-get-plants`
- HEAD base: `75c374a450f645286b17ded211e45b1ac6e50017`
- Cambios sin commit: 7 archivos.
- Sin push, PR ni merge.

## M. No ejecutado / no verificado

- No se ejecutó push, PR ni merge.
- No se verificó en servidor persistente.
- No se validó con grandes volúmenes.
- No se realizó cierre documental posterior.

## N. Veredicto final

LISTA PARA PUBLICACIÓN CON SALVEDADES DOCUMENTADAS.

Al momento de este informe documental (2026-11-11), M2.4 no se consideraba integrada hasta confirmar el merge. Posteriormente se fusionaron PR #29 y #30 el 2026-11-12; esta frase es histórica.

---

## Demostración HTTP

Se realizó una demostración funcional con peticiones HTTP reales sobre un entorno local aislado.

Evidencias observadas:
- Sin token: 401 `unauthenticated`.
- Sin `X-Organization-Id`: 400 `organization_required`.
- Administrador INSP-01: 200, 2 plantas, `meta.total=2`.
- Inspector INSP-01: 200, 1 planta, `meta.total=1`.
- Membresía revocada: 403 `organization_access_denied`.
- Cambio INSP-01 → ORG-02: 2 plantas de la organización seleccionada.
- Paginación `per_page=1`: 1 recurso, `last_page=2`, `total=2`.

No se observaron filtraciones cross-tenant ni exposición de plantas no autorizadas.

## Estado de integración

La integración de M2.4 en `master` no pudo verificarse localmente: `master` y `origin/master` permanecen en `be37d73`, mientras M2.4 está en `3a25b40` sobre la rama `feat/m2-4-get-plants`. No se afirma PR ni merge hasta confirmación del responsable.
