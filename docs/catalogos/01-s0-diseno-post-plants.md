# S0 — Diseño propuesto del primer corte de catálogos organizacionales

**Estado: S0 DISEÑADO; CAT-DEC-01–07 y CAT-TEC-01–07 APROBADAS POR EL RESPONSABLE DEL PROYECTO el 2026-11-12.** No se atribuye aprobación formal a todo el equipo. Base: `origin/master` `282c86b7a1e587780f604d7193c4b947e41e2b4c` (PR #35); rama `docs/catalogos-s0-diseno`. No se modifican decisiones históricas D-01–D-44. No existe decisión que nombre o defina M3.

## A. Veredicto S0

**LISTA CON SALVEDADES NO BLOQUEANTES para iniciar S1 como desarrollo interno.** CAT-TEC-01–07 cierran normalización, campos/errores, tenant, DTO, auditoría, duplicados y dirección de OpenAPI. No se obtuvo acceso autorizado a una base para comprobar colación efectiva ni datos existentes; tampoco están disponibles los repositorios panel/PWA. Esas limitaciones no impiden iniciar implementación aislada con pruebas SQLite y MariaDB, pero son gates antes de desplegar/publicar el contrato. No se registró la ruta ni se ejecutaron pruebas en este cierre. El alcance sigue limitado a `POST /api/plants`; sin update/delete/restore, nuevos permisos, jobs ni operaciones EHS.

## B. Evidencia y restricciones existentes

- `database/migrations/2026_10_10_000001_create_plants_table.php`: PK `id` `char(36)`, `name` `string(150)` y `code` `string(50)` NOT NULL; `address` y `description` `text` nullable; `status` boolean NOT NULL default `true`; timestamps. `2026_10_22_000002_add_organization_id_to_domain_tables.php` añade `organization_id` `char(36)` NOT NULL, FK a organizaciones con RESTRICT al borrar e índice. `2026_10_29_000002_unique_by_organization_roles_plants.php` sustituye el único global de código por `plants_org_code_unique (organization_id, code)`; `2026_10_22_000004_add_soft_deletes_to_catalog_tables.php` añade `deleted_at` a plantas, áreas y líneas. El índice único también reserva códigos de plantas soft-deleted.
- `Plant` usa `BelongsToOrganization` y `SoftDeletes`. La creación con contexto fija `organization_id` desde `TenantContext` y rechaza un valor diferente, pero el modelo sigue teniendo `organization_id` en `$fillable`; la **API propuesta** no debe aceptar ese campo del cliente. El modelo no genera `id` por sí mismo; la operación deberá generar un UUIDv7 en servidor. No añadir atributos, columnas ni migraciones para este corte.
- `PlantController::index()` es la única acción funcional. `routes/api.php` registra `GET /api/plants` con `api.tenant`; no hay `POST`. `PlantPolicy::create()` comprueba membresía/contexto y `Permission::PlantsManage`. `locations.view_all` es solo ampliación de lectura, no permiso de escritura. `TenantContext::requireId()` exige contexto; `ResolveTenantContext` exige organización cliente activa y membresía activa; `AuditLogger::record()` obtiene organización del contexto y actor autenticado en HTTP, redacta claves sensibles y **no inicia por sí mismo una transacción**.
- `tests/Feature/Api/PlantsIndexTest.php` cubre lectura y aislamiento, no la creación. `openapi.yaml` 2.3.0 marca `POST /plants` como `planned`; `PlantInput` exige `name`/`code` pero permite `organization_id` nullable; esto contradice la entrada segura aquí propuesta. `GET /plants` anuncia `include_hierarchy`, aunque `index()` solo procesa `page`/`per_page` y no carga áreas. CAT-DEC-07 aprueba corregir la discrepancia; editar OpenAPI se difiere hasta validar consumidores y mecanismo/versionado.
- `plants_org_code_unique` es el índice único de esquema en migración. `config/database.php` da `utf8mb4_unicode_ci` por defecto para MySQL/MariaDB pero permite `DB_COLLATION`; esto no prueba configuración de una instancia. No se identificó una BD autorizada/conectada para inspeccionar colación efectiva ni registros existentes. No se ejecutaron consultas SQL.
- La búsqueda local de `include_hierarchy` y clientes REST encontró usos del backend, OpenAPI, pruebas y docs, pero no código del panel/PWA. El directorio de proyectos visible no contiene repositorios de esos clientes. Esto significa **consumidores no verificados**, no ausencia de consumidores.

## C. Registro de decisiones arquitectónicas aprobadas

Las referencias solicitadas DEC-01–DEC-07 se conservan como alias de este preflight. Para no colisionar con D-01–D-07 del registro histórico, los identificadores inequívocos son **CAT-DEC-01–CAT-DEC-07**. Estado de todas: **APROBADA POR EL RESPONSABLE DEL PROYECTO — 2026-11-12**. No se les atribuye aprobación del equipo completo. Las verificaciones operativas aún abiertas se detallan en J y son gates de publicación/despliegue.

| ID / acuerdo aprobado | Alternativas consideradas | Acuerdo y justificación | Consecuencias / estado |
|---|---|---|---|
| **CAT-DEC-01 (DEC-01) — estrategia.** | A: CRUD de ubicaciones en bloque; B: EHS primero; C: desarrollo coordinado. | **C aprobado:** catálogos organizacionales mediante cortes verticales pequeños y diseño de fundamentos EHS en paralelo. | Coordinar contratos y no abrir EHS por la mera existencia de sus modelos. **Aprobada por responsable.** |
| **CAT-DEC-02 (DEC-02) — operaciones.** | Lectura; POST; CRUD completo. | **POST `/api/plants` exclusivamente** como primera operación, complementando GET existente. | No se autoriza detalle, actualización, eliminación ni restauración. **Aprobada por responsable.** |
| **CAT-DEC-03 (DEC-03) — ciclo de vida.** | Desactivar; soft delete; borrado físico. | En S1 no implementar desactivación, soft delete, restauración ni borrado físico. | Efectos sobre hijos y referencias históricas se deciden antes de habilitar esas operaciones. **Aprobada por responsable.** |
| **CAT-DEC-04 (DEC-04) — integridad jerárquica.** | FK/tenant aislados; tenant más coherencia de rama. | Futuras escrituras de áreas, líneas e inspecciones validarán tenant común **y** coherencia entre padres de la misma rama. | Gate de futuras escrituras relacionadas; POST planta no recibe padres. No implica aprobar migraciones nuevas. **Aprobada por responsable.** |
| **CAT-DEC-05 (DEC-05) — autorización.** | Atajo por rol; permiso explícito; permisos heredados entre organizaciones. | Crear exige membresía activa y `plants.manage`; nombre del rol no sustituye el permiso; no hay acceso implícito entre organizaciones. | `locations.view_all` no autoriza escritura. **Aprobada por responsable.** |
| **CAT-DEC-06 (DEC-06) — auditoría.** | Escritura desacoplada; evento transaccional. | Registrar evento explícito `plant.created` con actor y organización correctos; planta y auditoría son atómicas. | Fallo de auditoría revierte también la creación; CAT-TEC-05 concreta los atributos públicos relevantes. **Aprobada por responsable.** |
| **CAT-DEC-07 (DEC-07) — contrato público.** | Mantener promesa; implementarla en GET; corregir la descripción. | Resolver `include_hierarchy` antes de ampliar superficie; no documentar la jerarquía como implementada mientras el controlador no la soporte. | Corrección propuesta en E, sin tocar OpenAPI en S0; definir compatibilidad/versionado antes de publicar. **Aprobada por responsable.** |

### CAT-TEC-01–07 — decisiones técnicas de CAT-01

Las siete decisiones siguientes fueron aprobadas explícitamente por el responsable el 2026-11-12. Son específicas de esta propuesta y no alteran el esquema ni autorizan otras rutas.

| ID | Estado | Decisión aprobada | Consecuencia / límite |
|---|---|---|---|
| **CAT-TEC-01 — forma canónica de `code`** | **APROBADA** | Aplicar trim y convertir a mayúsculas; aceptar solamente `A–Z`, `0–9` y guion ASCII `-`; máximo 50 caracteres tras normalizar. Rechazar caracteres restantes. | Mantener el índice actual. La colación efectiva y datos existentes no fueron consultados; ver D y H. |
| **CAT-TEC-02 — campos y extras** | **APROBADA** | `name` obligatorio (máximo 150), `code` obligatorio (máximo 50), `address` y `description` opcionales y nullable. Rechazar campos adicionales y protegidos con 422 `validation_failed`. | Los límites son columna-esquema; reglas concretas Unicode/espacios de `name` no se ampliarán más allá de la decisión. |
| **CAT-TEC-03 — autoridad tenant** | **APROBADA** | Obtener `organization_id` exclusivamente de `TenantContext`. | Rechazar cualquier `organization_id` proveniente del body; no inferirlo del token ni `users.organization_id`. |
| **CAT-TEC-04 — DTO de creación** | **APROBADA** | 201 con `data: {id, organization_id, name, code, address, description, status}`. | Omitir `created_at`, `updated_at`, `deleted_at` y cualquier otro atributo interno. |
| **CAT-TEC-05 — auditoría atómica** | **APROBADA** | Registrar `plant.created` con actor autenticado, organización correcta y planta creada; inserción y auditoría comparten transacción. | Error de auditoría revierte la planta; sin evento parcial. Valores de auditoría relevantes limitados a los campos públicos acordados. |
| **CAT-TEC-06 — duplicados** | **APROBADA** | Responder 422 `validation_failed` ante duplicado, identificando únicamente la violación de `plants_org_code_unique`. | No traducir otras excepciones SQL a error de validación. El índice sigue siendo autoridad ante concurrencia. |
| **CAT-TEC-07 — contrato OpenAPI** | **APROBADA** | Resolver `include_hierarchy` antes de ampliar la superficie; no fingir que GET implementa jerarquía en S1. Verificar consumidores/compatibilidad antes de versionar. | No editar OpenAPI en S0. Compatibilidad externa no queda demostrada mientras no se inspeccionen repositorios consumidores. |

## D. Contrato técnico propuesto — `POST /api/plants` (sin ruta registrada)

**Solicitud:** `Authorization: Bearer <token>` (Sanctum); `X-Organization-Id: <UUID de cliente>` obligatorio; `Content-Type: application/json`. La organización se resuelve **exclusivamente** desde el header autenticado por `api.tenant` hacia `TenantContext`; nunca desde body/query, token ni `users.organization_id`.

```json
{"name":"Planta Norte","code":"PLN-01","address":"Av. Industria 100","description":"Planta principal"}
```

| Campo de entrada | Regla propuesta, contrastada con el esquema |
|---|---|
| `name` | Obligatorio, string no vacío, máximo 150, correspondiente a `plants.name NOT NULL string(150)`. No se normaliza nombre salvo el middleware Laravel global existente; no hay unicidad de nombre. |
| `code` | Obligatorio, string, normalizar con trim + mayúsculas; después exigir regex ASCII `\A[A-Z0-9-]+\z` y máximo 50. Único por organización mediante índice existente, incluso si la planta tiene soft delete. La normalización ocurre antes de validación de unicidad y persistencia. |
| `address`, `description` | Opcionales y nullable, string o null, correspondientes a columnas `text` nullable. No se inventa límite adicional. |
| `id`, `organization_id`, `status`, `deleted_at`, `created_at`, `updated_at` y demás extras | No forman parte del DTO de entrada y deben rechazarse con 422 `validation_failed`. Generar ID UUIDv7 en servidor (convención existente); tenant exclusivamente de `TenantContext`; `status=true` por default de BD; timestamps administrados por Eloquent. |

**Proceso propuesto:** autenticación y tenant existentes → `Gate::authorize('create', Plant::class)` → normalizar/validar estrictamente y rechazar claves extra → transacción que genera UUIDv7, persiste la planta con organización de contexto y ejecuta `AuditLogger::record('plant.created', ...)` → commit → responder `201 Created`. Validación previa mejora UX; el índice compuesto garantiza unicidad frente a carreras. Interceptar exclusivamente violación de `plants_org_code_unique` para 422 `validation_failed`; propagar otros errores como errores de servidor sin filtrar SQL. Si inserción o auditoría falla, rollback de ambas y ausencia de respuesta 201/log parcial. No incluir secretos.

**Respuesta 201 aprobada para CAT-TEC-04:** envoltorio `{ "data": { ... } }` con exactamente `id`, `organization_id`, `name`, `code`, `address`, `description`, `status`; sin timestamps ni `deleted_at`. No serializar el modelo indiscriminadamente. Ejemplo ilustrativo:

```json
{"data":{"id":"018f1a2b-3c4d-7e5f-8000-9a8b7c6d5e4f","organization_id":"018f1a2b-3c4d-7e5f-8000-9a8b7c6d5e40","name":"Planta Norte","code":"PLN-01","address":null,"description":null,"status":true}}
```

El `id` se genera por servidor; no se propone `Location` porque no existe ruta de detalle. `address` y `description` null cuando no se envían. La respuesta no concede por sí sola lectura de la planta: `plants.view` y su alcance siguen independientes. `Content-Type: application/json`; no agregar cabeceras personalizadas.

| Caso | Respuesta propuesta y origen existente |
|---|---|
| Sin token / token inválido / usuario global desactivado con token | 401 `unauthenticated` (`api.auth`, D-44). |
| Sin `X-Organization-Id` o UUID inválido | 400 `organization_required` / `organization_invalid`. |
| Organización ausente/inactiva/archivada, sin membresía o revocada | 403 `organization_access_denied`; operadora → 403 `organization_not_tenant`. |
| Sin `plants.manage`, incluido rol nominal sin permiso | 403 `forbidden`. |
| Campos inválidos, campos adicionales/protegidos o `code` duplicado en tenant | 422 `validation_failed`, con `errors` por campo; el duplicado se reporta solo ante la colisión del índice correspondiente. |
| Fallo interno/auditoría | Error interno no encubierto; rollback y ausencia de creación/registro parcial; no exponer SQL ni secretos. No introducir código nuevo de error sin aprobarlo. |

No usar `cross_tenant_operation` como sustituto de prohibir `organization_id` en el body: el límite HTTP debe rechazar ese atributo antes de poblar el modelo. No hay `plant_id` de otra organización en este POST; las relaciones cruzadas corresponden a cortes posteriores.

## E. `include_hierarchy` en `GET /api/plants`

**Resolución aprobada en CAT-DEC-07:** el contrato debe dejar de presentar `include_hierarchy` como funcionalidad implementada mientras `PlantController::index()` no soporte áreas/líneas. La corrección de S1 debe retirar/diferir la promesa al reflejar GET realmente desplegado; implementar jerarquía queda como posible corte futuro separado, con autorización y pruebas propias.

No editar `openapi.yaml` en S0. Aunque el servidor actual ignora el parámetro, retirar una promesa del OpenAPI 2.3.0 puede afectar clientes que esperen áreas anidadas. Los repositorios de panel/PWA no están disponibles: consumidores externos **no verificados**, no ausentes. Antes de publicar el contrato corregido, inspeccionar esos clientes y acordar transición compatible. No anunciar `include_hierarchy=true` como funcional ni cambiar versión silenciosamente. No hay base para decidir ahora un número de versión. La jerarquía queda fuera de S1.

## F. Matriz propuesta de pruebas para S1

| Grupo | Caso y resultado esperado |
|---|---|
| Autorización | Creación con cliente activo + membresía + `plants.manage` → 201 y una planta; sin token / token de usuario desactivado → 401; sin header → 400; membresía revocada / organización inactiva o ajena → 403 de contexto; operadora → 403 `organization_not_tenant`. |
| Permiso real | Sin `plants.manage`, con solo `plants.view` o `locations.view_all`, o con nombre de rol «Administrador» pero sin permiso → 403; sin escritura ni auditoría. |
| Tenant / mass assignment | `organization_id`, `id`, `status`, `deleted_at`, timestamps y cualquier clave extra → 422 `validation_failed`; cero escrituras. Tenant solo de `TenantContext`; verificar aislamiento entre peticiones. |
| Validaciones/código | `name` requerido/max 150; `code` trim + uppercase, luego solo ASCII `[A-Z0-9-]`, max 50; vacíos, tipos/caracteres no permitidos → 422. Comparar equivalencias SQLite/MariaDB. |
| Índice/colación/datos | Código normalizado duplicado en la organización incluso soft-deleted → 422 solo por `plants_org_code_unique`; mismo código entre organizaciones permitido. Probar carrera concurrente y no traducir otros SQL errors. Auditoría read-only de colación y datos del entorno destino antes de deploy. |
| Transacción/auditoría | Alta válida crea exactamente un `plant.created` con actor/org correctos y sin secretos; fallo inducido en auditoría revierte planta, fallo de inserción no deja audit log; no hacer afirmación de transacción probada hasta ejecutar las pruebas. |
| Contrato y regresión | POST pasa de *planned* a implementado **solo al existir ruta**; esquema request/201/errores validado contra BD; mantener cinco operaciones actuales hasta S1; ejecutar `PlantsIndexTest`, pruebas de permisos/tenant/revocación/auditoría, SQLite y MariaDB. Verificar GET sin jerarquía y resolver sus parámetros conforme DEC-07. |
| Sensibilidad | Mutaciones: omitir `Gate::authorize`, confiar en nombre del rol, aceptar `organization_id` del body, eliminar filtro/validación de tenant, persistir fuera de transacción, omitir `AuditLogger`, quitar único del índice (en copia de prueba), interpretar duplicado de otra organización como conflicto. Cada cambio no equivalente debe hacer fallar una prueba. |

## G. Backlog CAT-01 (alcance aprobado; implementación S1 pendiente de sus gates)

**CAT-01 — Alta controlada de planta.** Como administrador autorizado de una organización cliente, quiero registrar una planta para organizar las ubicaciones donde se realizarán inspecciones. Requiere `plants.manage`; el nombre del rol no sustituye el permiso. El alcance de crear `POST /api/plants` está aprobado por CAT-DEC-02 y su contrato por CAT-TEC-01–07; la implementación S1 puede iniciar con los gates de rollout documentados en J.

**Criterios de aceptación definitivos:** (1) cliente activo, membresía vigente y `plants.manage` → 201 con `data` exactamente `{id, organization_id, name, code, address, description, status}`, sin timestamps ni `deleted_at`; (2) auth/contexto/permisos conservan los códigos HTTP y errores existentes; (3) organización solo de `TenantContext`; cualquier clave extra o protegida (`organization_id`, `id`, `status`, `deleted_at`, timestamps incluidos) → 422 `validation_failed` sin escritura; (4) `name` requerido/max 150; `code` requerido, trim + mayúsculas, solo ASCII A–Z/0–9/guion y max 50; `address`/`description` opcionales-nullables; (5) duplicado normalizado en mismo tenant incluso soft-deleted → 422 solo por `plants_org_code_unique`, mismo código entre tenants permitido; otros SQL errors no se traducen; (6) un `plant.created` con actor, tenant y planta correctos, atómico con inserción; fallo inducido de auditoría revierte ambas; (7) ninguna otra operación, permiso, job ni ruta EHS; (8) pruebas SQLite/MariaDB, regresión M2 y casos de carrera pasan; auditoría read-only de colación/datos destino antes de deploy.

**Tareas listas para Issues:**

| Issue sugerido | Tarea | Dependencia |
|---|---|---|
| CAT-01-T1 | Implementar reglas y DTO CAT-TEC-01–04, incluyendo rechazo de claves adicionales. | Decisiones técnicas aprobadas. |
| CAT-01-T2 | Preparar pruebas HTTP de éxito, autenticación, tenant, membresía, permiso, mass assignment, duplicidad y aislamiento. | T1; antes de implementación. |
| CAT-01-T3 | Implementar validación; mapear solo campos permitidos; generar UUIDv7 y derivar `organization_id` de `TenantContext`. | T1/T2; sin nueva migración. |
| CAT-01-T4 | Reutilizar/verificar `PlantPolicy::create` existente; implementar persistencia y `plant.created` en transacción; mapear solo conflicto de índice conocido a 422. | T2/T3; policy existente en `app/Policies/PlantPolicy.php`. |
| CAT-01-T5 | Ejecutar pruebas SQLite y MariaDB 11.8, fallos inducidos de auditoría/rollback y mutaciones de seguridad. | T3/T4. |
| CAT-01-T6 | Preparar OpenAPI POST y corregir discrepancia GET tras revisar consumidores; verificar contrato/ruta/errores, documentar y preparar PR. | T1/T5; compatibilidad como gate de publicación. |

No se asignan fechas ni estimaciones. Son tareas propuestas, no implementadas.

**Paralelo solo de diseño EHS:** definir reglas de versiones publicadas y preguntas de checklist; decidir vínculos con normas y requisitos, integridad de referencias de inspección y alcance por asignaciones; elaborar contratos *planned* para revisión. Sin rutas, migraciones, código ni aprobación implícita de escritura EHS.

## H. Plan de implementación y dependencias

- **S1 — CAT-01, un POST:** entrada: CAT-DEC y CAT-TEC aprobadas; salida: una ruta de creación con policy, auditoría atómica, pruebas SQLite/MariaDB, revisión de seguridad y documentación. Revisión de consumidores y colación/datos destino son gates de publicación/despliegue. No se implementa GET nuevo ni CRUD adicional.
- **S2 — catálogo de áreas/líneas:** no iniciado ni autorizado aquí; definir operaciones separadas, alcance D-42, integridad misma rama, códigos por padre, ciclo de vida y dependencias antes de cada corte.
- **S3 — fundamentos para operación EHS/inspección:** diseño coordinado de normas, checklists versionados e invariantes de inspección. Un POST de planta no autoriza rutas EHS.
- En cada corte: revisar decisiones → contrato → modelo/relaciones → autorización → implementación → pruebas SQLite → MariaDB → auditoría/mutaciones → OpenAPI → documentación → preparar PR.

- Gate de implementación S1: CAT-DEC/CAT-TEC aprobadas y pruebas requeridas. Gates previos a publicación/despliegue: inspeccionar consumidores panel/PWA, acordar transición/versionado OpenAPI y auditar read-only colación/datos del entorno destino. No declarar esos controles satisfechos sin evidencia.
- D-40/D-42: no otorgar `plants.manage` por rol nominal, no confundirlo con `plants.view` ni con `locations.view_all`. D-44: revocación bloquea el acceso organizacional en la siguiente petición; tokens globales no expiran, decisión de despliegue separada.
- M2-R1–R3 bloquean jobs de negocio, no este POST HTTP; R7 exige preservar `OrganizationScope` y evitar bypass; R8 concierne aprovisionamiento/caché de permisos, no crear permisos por esta ruta. Los riesgos de padres de distinta rama bloquean escrituras futuras de hijos/inspecciones, no POST planta sin padres.
- Riesgos propios: discrepancia de OpenAPI para `PlantInput` y `include_hierarchy`; colación SQL distinta entre motores; duplicados concurrentes; auditoría no atómica por defecto; confusión entre `status=false` y soft delete; exposición accidental de campos por serialización del modelo. Probar y decidir antes de habilitar producción.

## I. Archivos previstos para S1 (ninguno modificado ahora)

- `routes/api.php`: solo nueva ruta si se aprueba.
- `app/Http/Controllers/PlantController.php`: acción de alta; `app/Http/Requests/` Form Request específica si se ajusta a las convenciones; un DTO/Resource explícito si evita exponer atributos del modelo.
- Servicio o unidad de aplicación de creación/auditoría **solo si** se justifica para transacción y manejo de error; reutilizar `app/Support/Audit/AuditLogger.php`, `PlantPolicy`, `TenantContext` y `Plant` sin editarlos salvo necesidad demostrada.
- `tests/Feature/Api/`: nueva prueba POST y regresiones necesarias; `tests/Feature/Documentation/`: correspondencia OpenAPI/DTO; pruebas de MariaDB y auditoría cuando corresponda.
- `openapi.yaml`: cambiar *planned* de POST y reconciliar `PlantInput`/GET jerarquía con aprobación de versión; documentación del corte bajo `docs/catalogos/` y estado/decisiones pertinentes.
- **No se prevén migraciones** ni nuevas columnas en S1 con el esquema actual; si un requisito las exige, detenerse y obtener aprobación aparte.

## J. Verificaciones pendientes (gates de publicación/despliegue)

CAT-DEC y CAT-TEC están aprobadas. Permanecen verificaciones operativas, no decisiones de producto pendientes:

1. Comparar colación efectiva de MariaDB y semántica real del índice frente a la forma canónica ASCII; para SQLite/MariaDB probar letras, acentos, espacios y casos límite sin alterar índices en S0. No se consultó ninguna instancia ni los registros existentes.
2. Inspeccionar código/contratos de panel y PWA para `include_hierarchy`; no se revisaron sus repositorios, así que el resultado es no verificado.
3. Probar el mapeo de constraint específico ante duplicidad concurrente; asegurar que errores SQL distintos no se traduzcan a 422.
4. Verificar en pruebas que los códigos HTTP del middleware se conservan y que el DTO no filtra atributos internos.

Si las verificaciones descubren incompatibilidad o datos existentes que no respeten CAT-TEC, detener despliegue y elevar el caso; no cambiar el índice durante S0. Estas salvedades no bloquean iniciar implementación aislada. Riesgos diferidos: bajas/descendientes, coherencia de futuras relaciones, expiración de token, jobs/workers y sincronización siguen fuera de este corte.
