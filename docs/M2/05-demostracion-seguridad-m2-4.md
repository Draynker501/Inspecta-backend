# Demostración funcional de seguridad M0–M2.4

## A. Objetivo

Demostrar mediante peticiones HTTP reales las capas de autenticación, selección organizacional, autorización por permisos, filtrado por asignaciones y paginación implementadas en `GET /api/plants`.

## B. Arquitectura y capas de seguridad

1. Autenticación Sanctum.
2. Resolución de `X-Organization-Id`.
3. Membresía activa.
4. `OrganizationScope`.
5. `PlantPolicy::viewAny()` con `plants.view`.
6. Filtrado por `locations.view_all` o `user_plants`.
7. Paginación posterior al filtrado.

## C. Datos ficticios utilizados

- Organizaciones: INSP-01 y ORG-02.
- Plantas: 2 por organización (Planta Norte, Planta Sur).
- Usuarios de demostración con membresías y roles explícitos.
- Permisos concedidos mediante `role_permissions`.
- Base de datos temporal SQLite aislada, sin datos reales.

## D. Peticiones HTTP reproducibles

```bash
curl -i -H "Authorization: Bearer <TOKEN>" -H "X-Organization-Id: <ORG_ID>" http://127.0.0.1:8099/api/plants
curl -i http://127.0.0.1:8099/api/plants
curl -i -H "Authorization: Bearer invalid" -H "X-Organization-Id: <ORG_ID>" http://127.0.0.1:8099/api/plants
curl -i -H "Authorization: Bearer <TOKEN>" http://127.0.0.1:8099/api/plants
curl -i -H "Authorization: Bearer <TOKEN>" -H "X-Organization-Id: <ORG_ID>" "http://127.0.0.1:8099/api/plants?per_page=1"
```

## E. Resultados esperados y observados

| Escenario | HTTP | Observado |
|---|---|---|
| Sin token | 401 | `unauthenticated` |
| Token inválido | 401 | `unauthenticated` |
| Sin `X-Organization-Id` | 400 | `organization_required` |
| Administrador INSP-01 | 200 | 2 plantas, `meta.total=2` |
| Supervisor INSP-01 | 200 | 2 plantas, `meta.total=2` |
| Inspector INSP-01 | 200 | 1 planta asignada, `meta.total=1` |
| Membresía revocada | 403 | `organization_access_denied` |
| Cambio INSP-01 → ORG-02 | 200 | 2 plantas de la organización seleccionada |
| Paginación `per_page=1` | 200 | 1 recurso, `last_page=2`, `total=2` |

## F. Comparación Administrador, Supervisor e Inspector

- Administrador y Supervisor con `locations.view_all`: ven todas las plantas de la organización.
- Inspector con `plants.view` únicamente: ve solo su planta asignada.

## G. Demostración de aislamiento multi-tenant

El usuario multiempresa recibe plantas distintas según `X-Organization-Id`; no se mezclan resultados ni metadatos entre organizaciones.

## H. Evidencias de permisos y filtrado

- Sin `plants.view`: 403 `forbidden`.
- Sin asignaciones y sin `locations.view_all`: colección vacía con 200.
- Plantas soft-deleted: excluidas.

## I. Limitaciones de la demostración

- No se validó en entorno persistente (Octane/workers).
- No se probaron grandes volúmenes.
- Los permisos se concedieron manualmente para la demostración.
- No se ejecutaron endpoints adicionales.

## J. Conclusiones

La demostración confirma que `GET /api/plants` aplica autenticación, selección organizacional, autorización, filtrado y paginación conforme al diseño aprobado.

No se observaron filtraciones cross-tenant ni exposición de plantas no autorizadas.
