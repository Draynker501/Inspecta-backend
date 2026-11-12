# M2.1 — Modelo de autorización de INSPECTA

**Estado:** D-40 Implementada (M2.2). D-42 Implementada en M2.3 (policies de Plant, Area y ProductionLine con alcance por asignación y permiso `locations.view_all`). D-41 Implementada en M2.4 (`GET /api/plants`). D-43 sigue Aprobada (pendiente en M2.6+). D-44 Implementada en M2.5: revocación de membresía bloquea el dominio en la siguiente petición; tokens no se invalidan; usuario globalmente desactivado recibe 401 `unauthenticated` con token previo; login conserva 403 `user_inactive`. Reactivación y gestión HTTP de membresías diferidas.
**Fuente:** decisiones D-40 a D-44 registradas en `docs/M1.5/decisiones/REGISTRO_DECISIONES.md` (aprobadas por el responsable el 11-nov-2026). Cambios de estado: D-32, D-33, D-34, D-35 pasan a Aprobada; D-27 Implementada (merge de master, config.platform.php fijado a 8.4.1).
**Norma de este documento:** si un permiso, rol, endpoint o comportamiento no está en este documento ni en el registro de decisiones, se considera **no autorizado en M2**.

---

## 1. Convenciones de autorización

| Convención | Reglamento |
|---|---|
| **Denegación por defecto** | `hasPermission()` deniega por defecto cualquier permiso no concedido explícitamente al rol de la organización activa. |
| **Único punto de evaluación** | `hasPermission()` es el único punto de evaluación de permisos (D-20, D-40). No se sobrescribe `User::can()`. |
| **Nivel de organización** | `hasPermission()` opera a nivel de la organización activa (contexto de tenant resuelto por `X-Organization-Id` + membresía activa). |
| **Carga de permisos** | Los permisos de la membresía se cargan una vez por petición y no persisten entre peticiones (D-40). |
| **Evolución del catálogo** | Un permiso nuevo del catálogo se añade a los roles existentes solo de forma explícita y solo añadiendo, nunca quitando (D-40). |
| **Catálogo en código** | Los permisos viven en código (`recurso.accion`); la base de datos guarda solo `role_permissions` (rol, organización, permiso) con FK compuesta al rol (D-09, D-15). No hay tabla `permissions` (D-40). |
| **403 `forbidden`** | Los 403 de políticas/Gate se renderizan con code `forbidden` y mensaje fijo del catálogo de errores (D-39, OpenAPI 2.1.0). Los 403 de contexto conservan su código y mensaje propios. |
| **404 coherente con D-39** | Un recurso de otra organización, o inexistente, se comporta como no existente: 404 (aislamiento fail-closed, D-08). No se revela existencia de recursos ajenos a la organización. |
| **401 de autenticación** | `/auth/me`, `/auth/organizations` y el resto de endpoints protegidos requieren token válido (D-44: los tokens no se invalidan al revocar una membresía). |

---

## 2. Roles base y alcance (D-42)

Roles base: **Administrador**, **Supervisor**, **Inspector**.

- `hasPermission()` opera a nivel de organización.
- Las policies aplican el alcance por asignación (`user_plants`, `user_areas`).
- **Inspector**: solo ve y ejecuta en sus plantas y áreas asignadas.
- **Administrador y Supervisor**: alcance de toda la organización (supuesto a confirmar; D-42).

### Matriz rol → capacidad

| Capacidad | Administrador | Supervisor | Inspector |
|---|---|---|---|
| Ver plantas, áreas y líneas | Sí | Sí | Sí (asignadas) |
| Gestionar plantas, áreas y líneas | Sí | No | No |
| Gestionar usuarios, roles y membresías | Sí | No | No |
| Ver normas y checklists | Sí | Sí | Sí |
| Gestionar normas y checklists | Sí | No | No |
| Crear y asignar inspecciones | Sí | Sí | No |
| Ejecutar inspecciones | Sí | Sí | Sí (asignadas) |
| Registrar hallazgos y evidencias | Sí | Sí | Sí |
| Cerrar y verificar hallazgos | Sí | Sí | No |
| Crear y asignar acciones correctivas | Sí | Sí | No |
| Ver reportes de la organización | Sí | Sí | No |
| Ver reportes personales (hallazgos propios en sus inspecciones) | Sí | Sí | Sí |

Áreas y líneas de producción no tienen permisos propios: se rigen por `plants.*` sobre la planta/área/padre asignada (jerarquía de la planta).

---

## 3. Catálogo borrador de permisos `recurso.accion` (D-40)

Granularidad conservadora: pocos permisos, agrupados por recurso. Los nombres marcados **POR CONFIRMAR** aún no son definitivos.

| Permiso | Recurso | Capacidad | Roles (según matriz) |
|---|---|---|---|
| `plants.view` | plants | Ver plantas, áreas y líneas | Administrador, Supervisor, Inspector (asignadas) |
| `locations.view_all` | locations | Leer todas las plantas, áreas y líneas de la organización activa sin asignaciones individuales | Administrador, Supervisor |
| `plants.manage` | plants | Gestionar plantas, áreas y líneas | Administrador |
| `users.manage` | users | Gestionar usuarios | Administrador |
| `roles.manage` | roles | Gestionar roles | Administrador |
| `memberships.manage` | memberships | Gestionar membresías | Administrador (en catálogo desde M2.2, D-43) |
| `norms.view` | norms | Ver normas y checklists | Administrador, Supervisor, Inspector |
| `norms.manage` | norms | Gestionar normas y checklists | Administrador |
| `inspections.manage` | inspections | Crear y asignar inspecciones | Administrador, Supervisor |
| `inspections.execute` | inspections | Ejecutar inspecciones | Administrador, Supervisor, Inspector (asignadas) |
| `findings.register` | findings | Registrar hallazgos y evidencias | Administrador, Supervisor, Inspector |
| `findings.close` | findings | Cerrar y verificar hallazgos | Administrador, Supervisor |
| `corrective_actions.manage` | corrective_actions | Crear y asignar acciones correctivas | Administrador, Supervisor |
| `reports.view` | reports | Ver reportes de la organización | Administrador, Supervisor |
| `reports.view_own` | reports | Ver reportes personales (hallazgos propios) | Administrador, Supervisor, Inspector **POR CONFIRMAR** |

Notas:
- `memberships.manage` entra al catálogo en M2.2 (D-43).
- `reports.view_own` es el punto de reportes personales: se marca **POR CONFIRMAR** por falta de definición de "hallazgo propio" sobre inspecciones propias del inspector.
- No se añaden permisos de eliminación, lectura de auditoría ni sincronización en este borrador (quedan fuera del alcance de M2).

---

## 4. Operadora sin acceso implícito (D-43)

- Un usuario de plataforma opera en un cliente solo con membresía activa y roles en ese cliente; la operadora no es superusuario.
- El alta de clientes y de las membresías de los consultores la hace el usuario de plataforma **fuera del TenantContext** (coherente con `PlatformOperatorPolicy` y el flujo de membresías de M1.5, P3.3.3/P3.3.5).
- La alta de membresías dentro de un cliente requiere `memberships.manage` y contexto organizacional activo.

---

## 5. Revocación de membresías y tokens (D-44, documenta D-06 para M2)

- Revocar una membresía bloquea el dominio de esa organización en la siguiente petición (D-20).
- La revocación **no invalida tokens**: siguen válidos para `/auth/me`, `/auth/organizations` y las demás organizaciones del usuario.
- Riesgo: los tokens no expiran. La decisión sobre `sanctum.expiration` queda pendiente porque depende de la operación sin conexión de la PWA; se registra entre los riesgos de despliegue (sección 7).
- **M2.5:** prueba de que un usuario desactivado recibe 401 en la siguiente petición. Pendiente de verificar si `EnsureApiAuthenticated` comprueba `users.status` en cada petición (hoy devuelve 403 para `user.status = false`, véase `docs/ACCESOS_Y_PERMISOS.md` §3.4; punto abierto de M2.5).

---

## 6. Plan de bloques M2.2 a M2.7

| Bloque | Contenido | Depende de | Prueba validadora |
|---|---|---|---|
| **M2.2** | Tabla `role_permissions` (rol, organización, permiso) con FK compuesta al rol; catálogo de permisos en código; `hasPermission()` único punto de evaluación; denegación por defecto. | M2.1, D-40, D-09, D-15 | Prueba de cobertura: todo permiso guardado en `role_permissions` existe en el catálogo de código. |
| **M2.3** | Policies de plantas, áreas y líneas con alcance por asignación (`user_plants`, `user_areas`): Inspector solo sus asignados; Admin/Supervisor org-wide (sujeto a confirmar). | M2.2 | Inspector recibe 404 en planta/área no asignada; Admin y Supervisor ven todas las de la organización. |
| **M2.4** | Endpoint de corte vertical de lectura (candidato `GET /plants`, a confirmar en M2.6): autenticación, tenant, controlador, policy, permiso y respuesta 403 `forbidden`. Contrato pasa a 2.2.0. | M2.2, M2.3, D-39 | 200 con token válido y permiso; 403 `forbidden` sin permiso; 404 para recurso de otra organización. |
| **M2.5** | Revocación de membresía bloquea el dominio en la siguiente petición; tokens no invalidados; prueba de usuario desactivado → 401 en la siguiente petición; verificación de `users.status` en `EnsureApiAuthenticated` por petición. | M2.2, D-44, D-20 | Usuario desactivado/miembro revocado obtiene 401 (o el código que defina M2.5) en la petición siguiente a la revocación. |
| **M2.6** | Confirmación del corte vertical `GET /plants` como única ruta de producción de M2; resto de rutas de dominio con rutas temporales; rutas de catálogos organizacionales se deferran a los catálogos organizacionales. | M2.4, D-41 | Contrato en 2.2.0; ruta de corte vertical lista; sin otras rutas de producción en M2. |
| **M2.7** | Jobs y comandos fijan el contexto de forma explícita (`TenantContext::runAs`, patrón D-38); pruebas de jobs y comandos con organización (T20 a T22). | D-35, D-38, M2.2 | Pruebas T20-T22 en verde: contexto organizacional explícito en jobs y comandos. |

Orden lógico: M2.2 → M2.3 → M2.4 → (M2.5 y M2.6 en paralelo tras M2.4) → M2.7.

---

## 7. Riesgos abiertos de M2

| Riesgo | Estado | Notas |
|---|---|---|
| Tokens sin expiración (`sanctum.expiration` sin configurar) | Abierto | Depende de la operación sin conexión de la PWA; decisión pendiente; riesgo de despliegue (D-44). |
| `Organization` sin scope de aislamiento | Abierto | El aislamiento se ejerce desde el contexto de tenant y las membresías; la tabla `organizations` por sí sola no impone scope de fila. |
| Auditoría sin escritor automático | Abierto | `AuditLogger` requiere contexto explícito; no hay escritor automático de auditoría (append-only, excepción dedicada). |
| `devices` y `sync_operations` sin `organization_id` | Abierto | Sin `organization_id` no hay aislamiento a nivel de fila; riesgo de fuga en `/sync/bootstrap` y dispositivos (ver `docs/ACCESOS_Y_PERMISOS.md` §6.13). |
| 401 vs 403 de usuario desactivado | Abierto | D-44 espera 401 en la siguiente petición; `EnsureApiAuthenticated` devuelve 403 hoy para `user.status = false`. Se verifica en M2.5. |
| Inserts masivos de `role_permissions` saltan los eventos del modelo (M2.3) | Abierto | Los eventos `saved`/`deleted` de `RolePermission` (que invalidan la caché de permisos) solo se disparan al usar Eloquent. Los inserts masivos (`DB::table()->insert()` o `insertOrIgnore`) no los disparan, dejando la caché stale. Requisito para el servicio de aprovisionamiento: crear filas por el modelo (`RolePermission::create`) o validar contra el catálogo y llamar `TenantContext::flushPermissionCache()` explícitamente. |

---

## 8. Alcance explícito de M2.1

Este bloque **no escribe código**: no toca controladores, rutas de producción, pruebas, migraciones, `openapi.yaml` ni `.env`. Solo registra decisiones y crea la documentación de diseño bajo `docs/M2/`.


## 9. M2.3 — Policies y alcance por asignación

**Estado:** Implementado y validado localmente (pendiente de publicación e integración).

### Permiso dedicado

- `locations.view_all`: permite leer todas las plantas, áreas y líneas de la organización activa sin asignaciones individuales.
- No sustituye `plants.view`.
- No concede creación, modificación ni eliminación.
- No otorga acceso a otras organizaciones.

### Matriz de alcance

| Rol base | Lectura | Escritura |
|---|---|---|
| Administrador | Organizacional completa con `plants.view` + `locations.view_all` | Organizacional completa con `plants.manage` |
| Supervisor | Organizacional completa con `plants.view` + `locations.view_all`; sin `plants.manage` | Denegada salvo concesión explícita de `plants.manage` |
| Inspector | Solo asignaciones con `plants.view` | Denegada salvo concesión explícita de `plants.manage` |

### Herencia

- Planta asignada: planta, sus áreas y las líneas descendientes.
- Área asignada: área y sus líneas; no concede planta padre ni áreas hermanas.
- Líneas: no tienen asignaciones directas; su alcance depende del área o planta correspondiente.

### Requisito para M2.4

`viewAny()` autoriza la capacidad general, pero no filtra colecciones. Toda consulta futura deberá filtrar por organización, `plants.view`, `locations.view_all` y asignaciones aplicando herencia.


## 10. M2.4 — Primer corte vertical HTTP

**Estado:** Implementado y validado localmente (pendiente de publicación e integración).

- Endpoint: `GET /api/plants`.
- Middleware: `api.tenant`.
- Autorización: `plants.view` mediante `PlantPolicy`.
- Alcance: `locations.view_all` o `user_plants`.
- Filtrado SQL antes de paginar.
- OpenAPI 2.2.0.

### Hallazgos no bloqueantes

- H-M2.4-01: filtrado de colecciones depende del controlador.
- H-M2.4-02: evaluar rendimiento SQL con volúmenes representativos.
- H-M2.4-03: `include_hierarchy` no implementado.
- H-M2.4-04: no validado en entornos persistentes.
