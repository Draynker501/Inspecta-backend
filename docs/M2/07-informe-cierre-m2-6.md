# Informe preliminar de implementación M2.6 — Consolidación HTTP

> **Fotografía histórica previa a la integración.** M2.6 se integró posteriormente por PR #33, commit `7f0465702aba452dc6d0363ef00be567099f0cf4` (2026-11-12). Las notas de rama, cambios sin commit y publicación pendiente reflejan el momento de validación local, no el estado actual.

Estado: preliminar, pendiente de auditoría y publicación.

## Objetivo
Consolidar la superficie HTTP de M2, cumplir D-32 y mantener consistencia OpenAPI.

## Alcance implementado
- D-32: `PlatformOperatorPolicy::viewMemberships()` ahora exige `isPlatformOperator() && hasPlatformRole()`.
- Pruebas de superficie HTTP: existencia de las 5 operaciones API, autenticación obligatoria, tenant en `/api/plants`, rutas planeadas no registradas.
- OpenAPI: correcciones descriptivas (JWT → usuario autenticado; organización vía `X-Organization-Id`).
- Pruebas de correspondencia OpenAPI/Laravel.

## D-32
La condición aprobada se implementó: `viewMemberships` requiere usuario activo, membresía activa y asignación de rol de plataforma activo en la operadora. `manageMemberships` conserva su semántica. `hasPlatformRole()` filtra `roles.status`; el estado del usuario lo comprueba `isPlatformOperator()`, no el helper por sí solo.

## Inventario definitivo de rutas
5 operaciones API: POST login, POST logout, GET me, GET organizations, GET plants.

## Correspondencia OpenAPI/Laravel
Pruebas automatizadas verifican bidireccionalidad entre operaciones implementadas en OpenAPI y rutas Laravel.

## Pruebas añadidas o modificadas
- `RouteSurfaceTest`: 6 métodos nuevos.
- `PlatformRoleActiveMembershipTest`: caso d actualizado y cuatro pruebas añadidas para rol inactivo, usuario inactivo, rol en otra organización y reactivación. Con los cuatro casos existentes son ocho pruebas; el caso sin membresía usa revocación porque la FK compuesta impide mantener una asignación huérfana.
- `OpenApiRouteCorrespondenceTest`: nuevo.
- OpenAPI: correcciones descriptivas.

## Resultados

**SQLite**
- 441 pruebas.
- 432 aprobadas.
- 9 omitidas.
- 1331 aserciones.
- 0 fallos.

**MariaDB 11.8.9** (contenedor temporal aislado `mariadb:11.8`, puerto loopback dinámico, base `inspecta_m26_test`, eliminado tras validación)
- 441 pruebas.
- 433 aprobadas.
- 8 omitidas.
- 1360 aserciones.
- 0 fallos.

En una ejecución anterior de M2.6 se verificó reversibilidad de migraciones (`migrate:fresh`, `migrate:reset`, `migrate`); no se repitió en esta corrección.

Las seis clases específicas solicitadas sumaron 93 pruebas y 173 aserciones, sin fallos en SQLite. Antes de corregir el helper, la clase de regresión produjo 2 fallos de 8 pruebas (rol inactivo y reactivación).

## Hallazgos
- La prueba HTTP original no detectaba la retirada de `api.tenant`, pues el controlador también declara middleware. Se agregó una aserción sobre el grupo explícito de la ruta y un caso HTTP con membresía y permiso, sin cabecera y con cabecera válida.
- Sensibilidad: en una copia aislada, eliminar `api.tenant` de la ruta hizo fallar `test_plants_declara_el_grupo_api_tenant_en_la_ruta` porque faltaba el grupo; la prueba original pasa sin la mutación.
- Sensibilidad del rol activo en copia aislada: la prueba de rol inactivo pasó con el filtro (1 prueba, 3 aserciones), falló al quitarlo (1 prueba, 1 aserción) y volvió a pasar al restaurarlo (1 prueba, 3 aserciones).

## Riesgos residuales
- Descripciones de endpoints futuros pueden requerir revisión adicional.
- La comprobación del rol activo también afecta `manageMemberships`; no concede acceso a datos de dominio.
- Tokens sin expiración pendiente (D-44).
- Reactivación de membresías diferida.

## Estado Git
Al validar: rama `feat/m2-6-consolidacion-http`, base `origin/master` `becf03e`, cambios todavía sin commit ni integración.

## Validaciones finales
- Pint: aprobado.
- Composer validate: válido.
- Composer audit: sin vulnerabilidades.
- git diff --check: OK.
- OpenAPI: 2.3.0 consistente.

## Pendientes para auditoría
- Auditoría independiente de D-32 y superficie HTTP.
- Commit, push, PR y merge (no ejecutados).
