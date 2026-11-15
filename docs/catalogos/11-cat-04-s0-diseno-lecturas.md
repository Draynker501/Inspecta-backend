# CAT-04 — Operaciones de lectura del catálogo de ubicaciones

## Decisiones aprobadas CAT-04-DEC-01–14

1. `plants.view` es el permiso base de lectura; `plants.manage` no concede lectura.
2. `locations.view_all` amplía el alcance solo dentro del tenant activo.
3. La membresía activa es obligatoria.
4. Los recursos inexistentes, ajenos, eliminados o fuera del alcance responden `404`.
5. La ausencia del permiso base responde `403`.
6. Los recursos inactivos permanecen visibles.
7. Los descendientes de ancestros eliminados no son visibles.
8. Las lecturas son planas; la jerarquía completa queda para CAT-05.
9. `GET /plants` conserva compatibilidad e ignora `include_hierarchy`.
10. Navegación restringida DEC-11: un usuario asignado solo a un área puede listar las áreas autorizadas de una planta sin acceso directo a la planta.
11. La autorización de navegación se resuelve antes de filtros, conteos y paginación.
12. Un usuario autorizado con filtros sin resultados recibe `200` con colección vacía.
13. `page`/`per_page` con valores 1–100; `per_page` predeterminado 15.
14. `status` admite true/false/1/0 y `q` busca en `name` y `code` con máximo 100 caracteres.

## Cierre documental de CAT-03

CAT-03 quedó integrada en `master` mediante PR #39. La evidencia y las decisiones CAT-03-DEC-01–11 y CAT-03-INT-01 constan en `docs/catalogos/10-cat-03-s0-lineas-produccion.md` y en el README.

## S2 — Auditoría y validación integral

### Hallazgo corregido

- P2: `ESCAPE '\'` no era portable a MariaDB y provocaba error de sintaxis SQL en el filtro `q`. Se corrigió usando `ESCAPE '!'` y escape de `!`, `%` y `_` en `LocationReadScope::escapeLike`. Validado en SQLite y MariaDB.

### Resultados finales

- SQLite `:memory:`: 567 pruebas, 531 aprobadas, 36 omitidas, 2361 aserciones.
- MariaDB 11.8.9 efímera: 567 pruebas, 539 aprobadas, 28 omitidas, 2557 aserciones.
- PHP lint, Pint y `git diff --check`: aprobados.

### Autorización y aislamiento

- `plants.view` obligatorio.
- `plants.manage` no concede lectura.
- `locations.view_all` no sustituye `plants.view`.
- Las restricciones por asignación se aplican antes de filtros, conteos y paginación.
- La navegación restringida DEC-11 responde `404` sin acceso a planta ni áreas visibles.
- Los recursos soft-deleted y descendientes de ancestros eliminados no son visibles.
- Los recursos inactivos permanecen visibles.

### Compatibilidad

- `GET /api/plants` conserva su comportamiento existente.
- `include_hierarchy` permanece deprecated e ignorado.
- OpenAPI permanece en `2.3.0`.

## S3 — Consolidación y publicación

### Matriz de trazabilidad corregida

| Decisión | Componente | Prueba que la acredita | Evidencia |
|---|---|---|---|
| DEC-04 | Filtros, búsqueda y escape de LIKE | `LocationReadsTest::test_filters_and_like_escape` | `status`, `q`, escape de `%`, `_` y `!` validados en SQLite y MariaDB |
| DEC-13 | Validación de query y compatibilidad de parámetros heredados | `LocationReadsTest::test_unknown_params_rejected_on_new_endpoints`; `PlantsIndexTest` | Nuevas operaciones rechazan parámetros desconocidos; `GET /plants` conserva `include_hierarchy` ignorado |
| DEC-14 | DTO explícitos y preservación de `GET /plants` | `PlantsIndexTest`; `LocationReadsTest`; `PlantsOpenApiContractTest` | Campos existentes preservados; DTOs planos documentados; OpenAPI sin jerarquía anidada |
| DEC-11 | Navegación restringida | `LocationReadsTest::test_restricted_navigation_dec11` | Usuario asignado solo a área recibe únicamente áreas autorizadas y `404` para planta/detalles ajenos |
| DEC-01–03, 05–08 | Autorización, membresía y aislamiento | `LocationReadsTest`, `PlantsIndexTest` | `403` sin permiso base, `404` cross-tenant/eliminado/fuera de alcance |
| DEC-09 | Paginación y filtros | `LocationReadsTest`, `PlantsIndexTest` | `page`, `per_page`, `status`, `q`, orden determinista |
| DEC-10 | Planos sin jerarquía | `LocationReadsTest`, OpenAPI contract tests | Sin colecciones anidadas en listados/detalles |
| DEC-12 | Colección vacía con autorización | `LocationReadsTest::test_empty_filters_return_200` | `200` con `data: []` |

### Evidencia de rendimiento

- Las restricciones de asignación se resuelven mediante subconsultas SQL (`whereIn`, `whereExists`), no filtrando colecciones en memoria después de paginar.
- La serialización utiliza `toArray()` sin carga de relaciones anidadas en listados.
- No se observó crecimiento lineal de consultas en las pruebas de listado con fixtures pequeños y medianos.
- No se agregaron índices ni migraciones.

### Limitaciones documentadas

- No se ejecutó un benchmark formal de carga.
- La sensibilidad a mayúsculas/acentos depende de la collation del motor; no se modificó arbitrariamente.
- La compatibilidad con panel y PWA sigue pendiente de verificación directa.
- OpenAPI permanece en `2.3.0` sin publicación contractual.

## S3 — Consolidación y publicación

- Commits locales temáticos creados.
- Validación final SQLite: 567 pruebas, 531 aprobadas, 36 omitidas, 2361 aserciones.
- Validación final MariaDB: 567 pruebas, 539 aprobadas, 28 omitidas, 2557 aserciones.
- PHP lint, Pint y `git diff --check`: aprobados.
- Publicación y GitHub Actions pendientes de ejecución.
