# Acta de cierre formal — M2: autorización, superficie HTTP y contexto organizacional

**Fecha del cierre documental:** 2026-11-12
**Estado de integración verificado:** M2.1–M2.7 integradas en `master` hasta `902ef0ba96f7121a8b0d4132488335ad14ae805f`.
**Dictamen:** **CERRADA CON SALVEDADES** para los alcances aprobados de M2.1–M2.7.
**Aprobación formal del equipo:** no consta en la evidencia consultada; esta acta no la atribuye.

## 1. Objetivo y alcance aprobado

M2 materializa el modelo de autorización multiempresa: permisos y policies organizacionales, un primer corte vertical HTTP, revocación de membresías, consolidación del contrato/superficie y contexto explícito para procesos no HTTP. No equivale a completar el producto EHS ni a habilitar procesamiento de negocio en workers.

El alcance aprobado excluye CRUD general de dominio, inspecciones/incidencias, sincronización offline, dispositivos, jobs reales de negocio, autoridad de sistema sin actor y middleware global de colas.

## 2. Resumen ejecutivo

- Usuarios globales; acceso a una organización cliente mediante membresía activa y contexto explícito. Los roles/permisos se evalúan por organización y las policies aplican alcance por asignaciones.
- La única ruta de dominio de producción de M2 es `GET /api/plants`. La API conserva cuatro operaciones de autenticación/identidad, sumando cinco operaciones API explícitas verificadas en M2.6.
- M2.5 revoca acceso al dominio organizacional en la siguiente petición, sin convertir el token global en token de una organización.
- M2.7 aporta infraestructura `TenantExecution`, `TenantAwareJob`, `TenantCommand` y pruebas T20–T22. Los artefactos de job/comando son de prueba y se invocan directamente. No prueban productor confiable, despacho, serialización ni worker persistente real.
- M2.6 y M2.7 están integradas mediante PR #33 y #34. Los checks CI de PR #34 —tests PHP 8.4/8.5, tests MariaDB 11.8, Composer audit y Pint/style— constan satisfactorios en GitHub.

## 3. Entregables y trazabilidad

| Hito | Objetivo y estado | Integración | Evidencia principal | Pendientes/salvedades |
|---|---|---|---|---|
| M2.1 | Modelo y decisiones de autorización; diseñado e integrado como documentación. | PR #26, `0919383425407b05864d94bb71afa7b41cc6c7d4` | `docs/M2/01-modelo-de-autorizacion.md`, registro de decisiones. | No añade código de producto por sí mismo. |
| M2.2 | Catálogo `Permission`, `role_permissions`, `hasPermission()` y caché; implementado e integrado. | PR #27, `be37d736237faf3424c7bc01532f6cd17ccb03a8` | Informe M2.2: SQLite 377 pruebas (368 aprobadas, 9 omitidas, 1177 aserciones); MariaDB 11.8 377 (369 aprobadas, 8 omitidas, 1206 aserciones). | Inserciones masivas deben invalidar explícitamente caché o usar eventos Eloquent. |
| M2.3 | Policies para plantas/áreas/líneas y alcance por asignaciones; implementado e integrado. | PR #28, `66299cfa3778ecb9828053ecefbb969c2075bc16` | Informe M2.3: SQLite 391 (382 aprobadas, 9 omitidas, 1215 aserciones); MariaDB 11.8 391 (383 aprobadas, 8 omitidas, 1244 aserciones); mutaciones documentadas. | Aprovisionamiento de roles y `reports.view_own` no son parte de este cierre. |
| M2.4 | Primer corte vertical HTTP `GET /api/plants`; implementado e integrado. | PR #29, `04ad0c01724904fa2a63c47057494532d593f892`; cierre documental PR #30, `95ff7dbc811a7f5814acb667b1c4764a2e329b83` | Informe M2.4: SQLite 409 (400 aprobadas, 9 omitidas, 1257 aserciones); MariaDB 11.8 409 (401 aprobadas, 8 omitidas, 1286 aserciones); `PlantsIndexTest`, filtrado antes de paginar y mutaciones. | Rendimiento con grandes volúmenes y entorno persistente no son evidencia del informe. |
| M2.5 | Revocación de membresía y D-44; implementada e integrada. | PR #32, `becf03e971403cdfe17b7743ef4a6e9f10a5b70a` | Informe local: SQLite 429 (420 aprobadas, 9 omitidas, 1293 aserciones); regresiones HTTP, autorización, atomicidad y auditoría. Checks del PR, incluidos MariaDB 11.8, satisfactorios. | No hay endpoints HTTP de membresías ni reactivación; tokens no se invalidan masivamente. |
| M2.6 | Superficie de cinco operaciones API, D-32 y correspondencia OpenAPI/Laravel; integrada. | PR #33, `7f0465702aba452dc6d0363ef00be567099f0cf4` | Informe M2.6: SQLite 441 (432 aprobadas, 9 omitidas, 1331 aserciones); MariaDB 11.8.9 441 (433 aprobadas, 8 omitidas, 1360 aserciones); sensibilidad de `api.tenant` y rol activo. | Informe de subfase conserva su fotografía local previa a integración. |
| M2.7 | Contexto de jobs/comandos de prueba, T20–T22 y endurecimiento D-38; integrada con salvedades. | PR #34, `902ef0ba96f7121a8b0d4132488335ad14ae805f` | Informe M2.7: suites previas SQLite 469 (460 aprobadas, 9 omitidas, 1443 aserciones) y MariaDB 11.8 469 (461 aprobadas, 8 omitidas, 1472 aserciones); regresión SQLite posterior 55 pruebas/256 aserciones; mutación de organización inactiva detectada. Checks CI del PR satisfactorios. | Cifras locales completas preceden una edición posterior de pruebas/documentación; CI validó el commit integrado, pero no se registran aquí contadores CI. Sin worker real ni productor productivo. |

Las cifras corresponden a ejecuciones de cada fotografía de hito y **no se suman**: las suites incluyen pruebas de fases previas. No son ejecuciones realizadas durante este cierre documental.

## 4. Decisiones y arquitectura al cierre

### Autorización y aislamiento

- `Permission` es catálogo en código; `User::hasPermission()` deniega sin contexto, membresía activa y permiso concedido.
- Las policies separan capacidad (permiso) de alcance por `user_plants`/`user_areas`; Inspector se limita a asignaciones. `locations.view_all` solo amplía lectura organizacional y no sustituye `plants.view`.
- La operadora no confiere acceso implícito a clientes. El usuario de plataforma requiere membresía/roles válidos en la organización cliente para operar allí.
- `OrganizationScope` filtra cuando existe contexto. El modelo `Organization` no tiene un scope de aislamiento universal; las consultas de organización requieren cuidado y autorización explícita.

### HTTP y OpenAPI

- Sanctum mantiene identidad global. `X-Organization-Id` selecciona contexto junto con organización cliente activa y membresía vigente; no se deriva del token.
- Las rutas de dominio requieren middleware/contexto y autorización. La superficie M2 confirmada contiene `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`, `GET /api/auth/organizations`, `GET /api/plants`; los métodos automáticos del framework no se cuentan como operaciones explícitas adicionales.
- OpenAPI permanece en 2.3.0 tras M2.5/M2.6. Las operaciones `x-status: planned` son documentación, no endpoints implementados.

### Auditoría

- `AuditLog` es append-only y `AuditLogger` redacta campos sensibles. La organización se toma de `TenantContext`; dentro de `TenantExecution` el actor es explícito y se rechaza auditable organizacionalmente incoherente. Fuera de esa frontera se conserva el comportamiento general existente, incluido el fallback de actor que establece el contrato actual.
- La auditoría y la operación de revocación son transaccionales en el servicio M2.5. No existe escritor automático universal para todo cambio de dominio.

### Procesos no HTTP

- `TenantExecution` valida organización cliente activa, usuario activo, membresía activa y un permiso enum seleccionado por código interno; limpia contexto/caché y no reemplaza managers/guards de Laravel.
- `TenantAwareJob` y `TenantCommand` son infraestructura reusable; T20–T22 se demuestran mediante artefactos exclusivos de pruebas e invocación directa.
- El UUID de actor identifica, pero **no autentica**. La frontera no demuestra procedencia confiable para datos de un payload. No hay productor real aprobado. Los consumidores futuros deberán autenticar el origen, aplicar policies/restricciones propias y no usar `Auth::user()` ambiental para autorización.
- La ejecución sin actor se rechaza; no hay autoridad de sistema definida. `runAs()` directo fuera de `TenantExecution` no recibe automáticamente sus garantías.

## 5. Evidencias de seguridad, pruebas y CI

Los informes referenciados contienen pruebas de permisos, aislamiento, asignaciones, middleware, códigos HTTP, revocación, auditoría y sensibilidad. La última regresión específica M2.7 reportada para SQLite fue 55 pruebas/256 aserciones; no se contabiliza como suite completa ni como prueba de worker.

La revisión independiente final de M2.7 dictaminó **APROBADA CON SALVEDADES** para el alcance limitado. Registró la mutación de filtro de organización activa detectada y la mutación redundante de membresía: retirar la verificación explícita no cambió la denegación porque `hasPermission()` también verifica membresía. Se conserva esa verificación explícita como defensa adicional.

GitHub confirma los merges #26–#34 en `master`. Para PR #34 se observaron satisfactorios los checks `Tests` (PHP 8.4/8.5), `Tests (MariaDB 11.8)` (PHP 8.4/8.5), `Security` / Composer audit y `Style` / Pint. Esta verificación documental no repitió PHPUnit, Pint ni Composer localmente.

## 6. Riesgos residuales

| ID | Riesgo y severidad | Alcance/evidencia | Acción futura y condición de resolución |
|---|---|---|---|
| M2-R1 | **Alto antes de habilitar jobs de negocio:** procedencia del actor no acreditada. | M2.7 acepta un ID de actor que solo identifica; no hay productor confiable real ni autenticación de worker demostrada. | Antes de conectar productor: definir fuente de identidad autenticada, autorización de operación y pruebas negativas de payload manipulable. |
| M2-R2 | **Alto antes de procesamiento en cola:** despacho, serialización, reintentos y worker persistente no validados. | T20/T21 invocan el job de prueba directamente; no simulan worker real. | Antes de desplegar consumidores: pruebas de integración de queue/worker con ejecución secuencial, excepción, retry y limpieza. |
| M2-R3 | **Alto para cualquier operación sin actor:** autoridad de sistema no definida. | La frontera falla cerrada sin actor. | Decidir operaciones y autoridad acotada con aprobación; no añadir bypass genérico. |
| M2-R4 | **Medio:** usos directos de `TenantContext::runAs()` fuera de `TenantExecution` no reciben validación de actor/permiso ni frontera completa. | Contrato del método establece contexto, no autorización. | Revisar cada uso administrativo y job futuro; reemplazarlo o acompañarlo de validación explícita antes de acceso/escritura. |
| M2-R5 | **Alto para despliegue según modelo de amenaza:** tokens Sanctum no tienen expiración configurada. | D-44 mantiene tokens globales y no invalida masivamente al revocar una membresía; el acceso organizacional se revalida por petición. | Decidir expiración/revocación considerando el modo offline de PWA y documentar ciclo de vida antes del despliegue correspondiente. |
| M2-R6 | **Alto antes de sincronización/dispositivos:** `devices` y `sync_operations` carecen de `organization_id` según los documentos de arquitectura. | Sin clave de organización no hay aislamiento de fila. No se implementó sync en M2. | Diseñar y validar esquema y autorización tenant antes del módulo de dispositivos/sincronización. |
| M2-R7 | **Medio:** `Organization` no tiene scope global y hay exenciones de consola en `OrganizationScope`. | El aislamiento depende de contexto, disciplina de consulta y fronteras; `withoutTenant()` es una vía privilegiada. | Revisar consultas administrativas y allowlist/motivos cuando aparezcan consumidores; conservar pruebas fail-closed. |
| M2-R8 | **Medio:** aprovisionamiento completo de catálogos/roles y aprovisionamiento de permisos no forma parte de M2. | Los inserts masivos pueden saltar eventos de invalidación de caché; no existe servicio de catálogo cliente completo. | Exigir Eloquent/eventos o `flushPermissionCache()` explícito y diseñar provisionamiento en el hito que lo requiera. |
| M2-R9 | **Diferido:** reactivación de membresías y endpoints administrativos HTTP no implementados. | M2.5 cubre revocación, no reactivación ni CRUD HTTP. | Definir política de recuperación, permisos, auditoría y pruebas antes de implementarlas. |

La severidad identifica condiciones para capacidades futuras; R1–R3 bloquean conectar jobs reales, no el cierre del alcance de pruebas/contexto aprobado de M2.7. R5 y R6 requieren resolución antes de habilitar los despliegues/features asociados.

## 7. Elementos fuera de alcance y transición

### Capacidades disponibles

- Permisos y policies base, aislamiento organizacional HTTP, `GET /api/plants`, revocación de membresía y D-32.
- Infraestructura de contexto no HTTP y pruebas T20–T22, con limitaciones de procedencia y sin worker real.
- Auditoría append-only con redacción y contexto explícito en los flujos cubiertos.

### Funcionalidades aún no disponibles

- CRUD general EHS, inspecciones, incidencias, acciones correctivas y endpoints de auditoría.
- Sincronización offline, dispositivos y jobs de negocio.
- Servicio completo de aprovisionamiento de roles/normas, administración HTTP y reactivación de membresías.

Antes de cualquier módulo que agregue consumidores de jobs se requiere resolver M2-R1–R4 y ejecutar preflight de colas/worker. Las funciones EHS necesitan contratos, permisos, policies y alcance propios; no quedan implícitos por los 45 paths `planned` de OpenAPI.

La documentación consultada no define el nombre ni el alcance de M3. No se propone aquí una fase funcional nueva. Próximo paso recomendado: preflight independiente de la hoja de ruta aprobada, dependencias EHS, catálogos, sincronización y decisiones de despliegue; aprobar alcance antes de implementar.

## 8. Conclusión

**M2 queda CERRADA CON SALVEDADES** para los entregables aprobados y ya integrados en M2.1–M2.7. La salvedad esencial es que M2.7 entrega contexto y pruebas, no autenticación general de workers ni autorización automática para futuros consumidores. Los riesgos de producción asociados deben resolverse antes de activar esas capacidades.

Este dictamen documental no sustituye una aprobación formal del responsable/equipo; no se encontró tal aprobación para el cierre M2.

## 9. Referencias

- Decisiones: `docs/M1.5/decisiones/REGISTRO_DECISIONES.md`.
- Estado e histórico: `docs/M1.5/avance/ESTADO.md`.
- Modelo de autorización: `docs/M2/01-modelo-de-autorizacion.md`.
- Informes de subfase: `docs/M2/02-informe-cierre-m2-2.md` a `docs/M2/08-informe-cierre-m2-7.md`.
- Aprobación de transición M1.5→M2: `docs/M1.5/13-acta-aprobacion-m2.md`.
- PR #26–#34, merges a `master`; verificaciones CI observadas para PR #27–#29, #32–#34 y especialmente PR #34. La lista exacta de checks del PR #34 se resume en §5.
