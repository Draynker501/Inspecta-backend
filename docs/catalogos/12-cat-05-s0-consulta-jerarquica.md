# CAT-05 — Consulta jerárquica de ubicaciones

## Decisiones aprobadas CAT-05-DEC-01–17

1. Usar endpoint dedicado `GET /api/locations/hierarchy`.
2. Profundidad fija: planta → área → línea.
3. Paginación solo de plantas raíz.
4. Límite máximo de 50 raíces por página.
5. Planta contenedora con campos mínimos para usuarios asignados solo a áreas.
6. Recursos inactivos visibles si hay autorización.
7. Recursos soft-deleted excluidos en todos los niveles.
8. Sin conteos adicionales por nodo.
9. Sin sincronización offline en CAT-05.
10. `include_hierarchy` permanece deprecated e ignorado en `GET /plants`.
11. Sin permisos nuevos.
12. Sin migraciones ni índices nuevos.
13. Límite efectivo de 1,000 nodos por respuesta; 422 sin resultados parciales.
14. Validación estricta de parámetros y rechazo de parámetros desconocidos.
15. DTO jerárquico mínimo uniforme; colecciones vacías como arrays.
16. Paginación exclusiva de raíces y orden determinista `name, id` en los tres niveles.
17. Conciliación contractual en OpenAPI 2.3.0 sin publicación ni cambios de consumidores.

## Cierre documental de CAT-04

CAT-04 quedó integrada en `master` mediante PR #40. La evidencia y las decisiones CAT-04-DEC-01–14 constan en `docs/catalogos/11-cat-04-s0-diseno-lecturas.md` y en el README.

## S1 — Implementación

Implementado `GET /api/locations/hierarchy` con controlador dedicado, reutilizando `LocationReadScope`, autorización por tenant/asignación, paginación de raíces, límite de 1,000 nodos, DTO mínimo y OpenAPI conciliado.

## S2 — Auditoría y validación integral

### Hallazgos

- P2: la documentación inicial solo registraba DEC-01–12, aunque el alcance aprobado incluye DEC-01–17. Corregido en este documento.
- P3: la representación de planta contenedora y el límite de nodos requieren validación con consumidores reales; se documenta como riesgo residual.
- No se detectaron defectos de autorización, aislamiento ni exposición de datos.

### Correcciones aplicadas

- Se completó la numeración de decisiones DEC-13–17.
- Se amplió la cobertura de pruebas con casos de 999, 1,000 y 1,001 nodos, planta contenedora, asignaciones combinadas, recursos inactivos, soft-deletes, parámetros inválidos y cross-tenant.

### Autorización e aislamiento

- `plants.view` obligatorio; `403` sin él, incluso con `locations.view_all` o `plants.manage`.
- `locations.view_all` amplía el alcance solo dentro del tenant activo.
- Membresía activa verificada mediante `OrganizationMembership::findActive`.
- Aislamiento por `TenantContext` y filtros explícitos por `organization_id`.
- Asignaciones restringidas al tenant mediante `wherePivot('organization_id', ...)`.
- Autorización aplicada antes de paginar, contar y cargar relaciones.
- Condiciones OR agrupadas correctamente.

### DTO y plantas contenedoras

- Planta: `id`, `name`, `code`, `status`, `areas`.
- Área: `id`, `name`, `code`, `type`, `status`, `production_lines`.
- Línea: `id`, `name`, `code`, `status`.
- `type` preserva nulabilidad.
- Colecciones vacías como arrays vacíos.
- Sin campos internos, timestamps ni auditorías.
- Usuario asignado solo a área recibe planta contenedora mínima y únicamente áreas autorizadas.

### Paginación y filtros

- `page` positivo; `per_page` entre 1 y 50; predeterminado 15.
- Orden `name, id` en los tres niveles.
- Paginación exclusiva de plantas raíz.
- `meta.total` calculado sobre raíces autorizadas y filtradas.
- Filtros `plant_id` y `area_id` con intersección.
- Identificadores válidos inexistentes, cross-tenant o fuera de alcance responden `200` con colección vacía.
- UUID inválidos, valores inválidos y parámetros desconocidos responden `422`.
- `status`, `q`, `include_deleted` e `include_hierarchy` no se admiten.

### Límite efectivo de nodos

- 999 nodos: `200`.
- 1,000 nodos: `200`.
- 1,001 nodos: `422`.
- Una sola rama que supera el límite: `422`.
- Recursos no autorizados excluidos del conteo.
- Sin resultados parciales.
- Sin cantidades exactas no autorizadas en errores.
- Carga acotada mediante `limit(1001)` en áreas y líneas; conteo sobre colecciones materializadas.

### Consistencia ante cambios concurrentes

- La respuesta se construye a partir de colecciones ya materializadas, por lo que nunca supera el límite aunque cambien los datos.
- Los snapshots pueden presentar inconsistencias menores si los recursos cambian entre consultas; no se introducen bloqueos ni infraestructura de concurrencia.

### Rendimiento

- Restricciones de asignación resueltas mediante subconsultas SQL (`whereIn`, `whereExists`).
- Eager loading controlado mediante agrupación en memoria de colecciones ya cargadas.
- Selección explícita de columnas mediante modelos.
- Consultas acotadas por página.
- Sin N+1 observado en pruebas funcionales.
- Sin caché, migraciones ni índices nuevos.

### Validación contractual

- `GET /locations/hierarchy` conciliado en OpenAPI 2.3.0.
- Parámetros, DTO, paginación, límite de nodos y errores documentados.
- `GET /plants` conserva `include_hierarchy` deprecated e ignorado.
- Correspondencia entre rutas Laravel y OpenAPI verificada.

### Trazabilidad DEC-01–17

| Decisión | Componente | Prueba que la acredita | Evidencia |
|---|---|---|---|
| DEC-01 | Endpoint dedicado | `RouteSurfaceTest`; `OpenApiRouteCorrespondenceTest` | Ruta registrada y documentada |
| DEC-02 | Profundidad fija | `LocationHierarchyTest` | DTO de 3 niveles |
| DEC-03 | Paginación solo raíces | `LocationHierarchyTest` (implícito) | `meta` sobre plantas |
| DEC-04 | Límite 50 raíces | Validación `per_page` max 50 | `422` si se excede |
| DEC-05 | Planta contenedora mínima | `LocationHierarchyTest::test_navegacion_restringida_planta_contenedora` | Campos exactos |
| DEC-06 | Recursos inactivos visibles | `LocationHierarchyTest::test_recurso_inactivo_incluido` | `status=false` visible |
| DEC-07 | Soft-deleted excluidos | `LocationHierarchyTest::test_soft_deleted_ancestors_excluidos`, `test_linea_soft_deleted_excluida` | No aparecen |
| DEC-08 | Sin conteos adicionales | DTO | Solo `meta` de raíces |
| DEC-09 | Sin offline | Alcance | No implementado |
| DEC-10 | `include_hierarchy` deprecated | `PlantsOpenApiContractTest` | Ignorado en `GET /plants` |
| DEC-11 | Sin permisos nuevos | Código | Solo `plants.view` |
| DEC-12 | Sin migraciones/índices | Git diff | Ninguno |
| DEC-13 | Límite 1,000 nodos | `LocationHierarchyTest::test_999/1000/1001` | `200`/`422` |
| DEC-14 | Validación estricta | `LocationHierarchyTest::test_parametros_invalidos_devuelve_422`, `test_unknown_params_rejected` | `422` |
| DEC-15 | DTO mínimo uniforme | `LocationHierarchyTest::test_colecciones_vacias_son_arrays` | Campos exactos |
| DEC-16 | Orden y paginación | `LocationHierarchyTest` (implícito) | Orden `name, id` |
| DEC-17 | OpenAPI sin publicación | `OpenApiRouteCorrespondenceTest` | Versión 2.3.0 |

### Resultados completos

- SQLite `:memory:`: 587 pruebas, 551 aprobadas, 36 omitidas, 2435 aserciones.
- MariaDB 11.8.9 efímera: 587 pruebas, 559 aprobadas, 28 omitidas, 2631 aserciones.
- Pruebas focalizadas CAT-05: 19 pruebas, 19 aprobadas.
- PHP lint, Pint y `git diff --check`: aprobados.

### Limpieza de recursos

- Contenedor y red MariaDB eliminados y verificados.
- Worktree y archivos temporales no comprometidos.

### Riesgos residuales

- Compatibilidad con panel y PWA no verificada.
- Conflicto de rutas con `origin/api` pendiente.
- Sin benchmark formal de carga.
- Sensibilidad a mayúsculas/acentos depende de collation.
- Representación de planta contenedora y límite de nodos requieren validación con consumidores reales.

### Preparación para S3

CAT-05 validada independientemente en SQLite y MariaDB, preparada para consolidación, publicación e integración.

## S3 — Consolidación y publicación

### Comprobación final de consistencia

- No se identificó exposición de nodos fuera del alcance autorizado.
- Las inconsistencias temporales entre snapshots no permiten inclusión de descendientes bajo padres incorrectos.
- La respuesta nunca supera 1,000 nodos; las colecciones se materializan con límites defensivos.
- No hay truncamiento silencioso: cualquier desborde responde `422`.
- Las garantías reales son de consistencia de lectura por snapshot, no de aislamiento transaccional.

### Commits locales

- `docs(catalogs): document CAT-05 hierarchy decisions`
- `feat(locations): implement tenant-scoped location hierarchy`
- `docs(catalogs): close CAT-05 technical validation`

### Validación local final

- SQLite `:memory:`: 587 pruebas, 551 aprobadas, 36 omitidas, 2435 aserciones.
- MariaDB 11.8.9 efímera: 587 pruebas, 559 aprobadas, 28 omitidas, 2631 aserciones.
- PHP lint, Pint y `git diff --check`: aprobados.

### Publicación

- Rama publicada en `origin` sin force push.
- Draft PR contra `master`.
- GitHub Actions pendiente de verificación.
