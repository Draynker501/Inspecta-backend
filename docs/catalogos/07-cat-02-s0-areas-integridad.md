# CAT-02 — S0: alta de áreas e integridad

**Estado:** decisiones funcionales registradas como aprobadas por el responsable del proyecto. Este documento se reconstruyó el 2026-11-14 a partir del encargo vigente y del historial de trabajo disponible; no se recuperó el archivo original. No se atribuye aprobación a todo el equipo.

## 1. Alcance

CAT-02 es el corte de alta de áreas bajo una planta. No amplía CAT-01, que corresponde a `POST /api/plants`. No autoriza CRUD general de áreas, líneas, inspecciones ni incidencias; tampoco autoriza modificar OpenAPI o desplegar cambios.

## 2. Decisiones funcionales aprobadas

| ID | Decisión |
|---|---|
| CAT-02-DEC-01 | La creación de áreas es el único corte funcional de CAT-02. |
| CAT-02-DEC-02 | La ruta propuesta es `POST /api/plants/{plantId}/areas`. |
| CAT-02-DEC-03 | Exigir `plants.manage` y membresía activa. No exigir además `plants.view` ni una asignación adicional a la planta. |
| CAT-02-DEC-04 | La planta padre debe pertenecer al tenant del contexto, existir, estar activa y no estar eliminada. |
| CAT-02-DEC-05 | Normalizar `code` con `trim` y mayúsculas; aceptar solo ASCII `A–Z`, `0–9` y `-`, máximo 50 caracteres. El código es único por planta, también frente a áreas soft-deleted. |
| CAT-02-DEC-06 | `type` es opcional y nullable, máximo 50 caracteres; no se exige un enum. |
| CAT-02-DEC-07 | Aceptar exclusivamente JSON; generar UUIDv7 en servidor; `status=true`; usar DTO explícito de ocho campos y rechazar cualquier campo adicional. |
| CAT-02-DEC-08 | Emitir `area.created` de forma transaccional, con `old_values=[]` y `new_values` limitado a `plant_id`, `name`, `code` y `status`. |

La aprobación original de CAT-02-DEC-07 especifica que la respuesta HTTP `201` se encapsula en `data` y contiene exactamente los ocho campos `id`, `organization_id`, `plant_id`, `name`, `code`, `type`, `description` y `status`. No incluye timestamps, `deleted_at` ni relaciones anidadas.

## 3. Precisiones HTTP aprobadas

- `415` para contenido no JSON, incluido `multipart`.
- `422 validation_failed` para JSON inválido, propiedades adicionales y parámetros query no permitidos.
- `422` con `errors.plantId` si la planta propia está inactiva.
- `404` genérico para planta ajena, inexistente o eliminada.
- `201` para alta válida.

La conciliación OpenAPI de S1.3-A describe exclusivamente el POST y no habilita las operaciones vecinas marcadas como planificadas.

## 4. Integridad jerárquica

La operación debe asegurar que el área y su planta pertenezcan a la misma organización. La regla de aplicación complementa, no reemplaza, las restricciones de base de datos. La decisión de integridad referencial aprobada está especificada en [CAT-02-INT-01 y CAT-02-INT-02](08-cat-02-s0-1-diseno-fk-area-planta.md).

## 5. Estado y límites

En el cierre histórico de S0, las decisiones anteriores no acreditaban endpoint, validación MariaDB ni despliegue. Las pruebas posteriores SQLite y MariaDB se documentan en [S1.1](09-cat-02-s1-1-integridad-referencial.md); no acreditan despliegue. El tratamiento de concurrencia HTTP y el catálogo exhaustivo de errores distintos de los expresamente aprobados quedan pendientes si no se precisan en las fuentes recuperadas.

## 6. Estado S1.2 y auditoría S1.3-A

**Veredicto de auditoría HTTP:** conforme con las decisiones CAT-02, con salvedades de validación MariaDB y concurrencia no reproducidas aún en una prueba HTTP MariaDB. No se identificaron hallazgos P0 ni cambios de decisión funcional necesarios.

El POST `POST /api/plants/{plantId}/areas` usa `api.tenant`, `TenantController` y `AreaPolicy::create`. La autorización exige membresía activa y `plants.manage`, pero no `plants.view` ni asignación a la planta. El Gate se ejecuta antes del parseo/validación del contenido; el padre se busca posteriormente por `organization_id` de `TenantContext`, con el scope de soft deletes de Eloquent. Ajena, inexistente y eliminada devuelven el mismo 404; la planta propia inactiva devuelve 422 en `errors.plantId`.

La auditoría detectó como **P2** que el filtro de colisión MariaDB dependía de que el error del driver reportara el nombre del índice sin calificador de tabla/esquema. Se corrigió para reconocer únicamente el nombre exacto `areas_plant_code_unique`, permitiendo el calificador opcional que puede añadir MariaDB; se agregó una prueba HTTP MariaDB preparada para una colisión SQL posterior a validación. La prueba no se ejecutó en esta fase.

El código padre se bloquea con `lockForUpdate()` dentro de la misma transacción que inserta el área y escribe auditoría. En MariaDB, el bloqueo serializa la creación con una actualización concurrente de estado/soft delete; sin embargo, el comportamiento y la latencia bajo contención no se han medido aún con pruebas concurrentes. SQLite no ofrece evidencia de ese bloqueo. Se clasifica como **P2 pendiente de validación**; no se cambia la regla aprobada.

La prueba HTTP SQLite demuestra el DTO exacto de ocho campos, UUIDv7, autorización, aislamiento, padre inactivo, normalización, unicidad por planta y reserva de soft-deleted, errores de entrada, rollback de auditoría y traducción selectiva de la colisión del índice. Las pruebas rechazadas verifican ausencia de áreas y de eventos `area.created`. Se agregó una prueba MariaDB HTTP sin ejecutar; no se accedió a una base externa.

OpenAPI ahora documenta el POST como implementado con Bearer, `X-Organization-Id`, `plants.manage`, JSON exclusivo, `AreaCreateInput` cerrado y `AreaCreated` exacto. Las demás operaciones de áreas siguen `planned`; no se cambió la versión contractual `2.3.0`. Esta actualización del contrato puede ser incompatible con clientes que traten la operación antes planeada como inexistente; consumidores, publicación y versionado siguen pendientes de decisión y no se publican por este cambio.

No se implementaron otras operaciones de áreas ni líneas de producción. El cambio no acredita despliegue. Siguen aplicando las condiciones de despliegue de S1.1: revisar esquema/datos y discrepancias DDL-migrations, inventario de triggers/vistas SQLite, respaldo y recuperación aprobados, y evaluar algoritmo, bloqueos y concurrencia MariaDB. No se autoriza DDL persistente por este cierre.

## 7. S1.3-B — validación parcial MariaDB

Se ejecutó MariaDB **11.8.9-MariaDB-ubu2404** en un contenedor temporal exclusivo con red independiente, `/var/lib/mysql` en `tmpfs`, puerto aleatorio en `127.0.0.1`, base `inspecta_cat02_test` y usuario limitado. Antes de migrar se verificaron el contenedor, la imagen, la configuración de `tmpfs`, `APP_ENV=testing`, el driver `mariadb`, la versión y base efectivas, el usuario, `TestDatabaseGuard` y `foreign_key_checks=1`. Se empleó un PHPUnit temporal fuera del repositorio. Los contenedores, redes y configuraciones temporales de las ejecuciones se eliminaron y su ausencia se verificó.

Las pruebas HTTP focalizadas MariaDB pasaron: **19 pruebas y 157 aserciones**, incluyendo `AreasStoreTest` y la colisión real preparada. La suite completa MariaDB final pasó con **514 pruebas, 496 aprobadas, 18 omitidas y 1969 aserciones**. Una primera ejecución completa obtuvo 1 fallo y 3 errores por ausencia de `APP_KEY` en la configuración temporal; se repitió con una clave efímera, sin modificar las pruebas.

La suite completa SQLite `:memory:` pasó con **514 pruebas, 498 aprobadas, 16 omitidas y 1974 aserciones**, idénticas a S1.3-A. Las pruebas de contrato seleccionadas pasaron: **8 pruebas y 169 aserciones**. PHP lint, Pint y `git diff --check` pasaron.

**Limitación importante:** no se realizaron aún pruebas controladas de concurrencia con conexiones independientes ni peticiones HTTP simultáneas. La prueba MariaDB de colisión no demuestra concurrencia real y no registró por separado SQLSTATE, código del motor ni el texto exacto del índice. Tampoco se han comprobado independientemente los nombres de índice con y sin calificador. S1.3-B permanece pendiente por estas verificaciones, sin autorización de despliegue.

Respecto del contrato de `master`, el POST pasa de planificado a implementado, usa entrada cerrada `AreaCreateInput`, salida `AreaCreated` de ocho campos y respuestas de error concretas, incluido 415. Los clientes generados o consumidores que dependieran de los esquemas anteriores podrían requerir ajustes; no se ha verificado compatibilidad con panel o PWA ni decidido publicación o cambio de versión `2.3.0`.

## 8. S1.3-B2 — evidencia SQL parcial

En MariaDB 11.8.9 efímera, con `TestDatabaseGuard`, `foreign_key_checks=1`, base `_test`, usuario limitado, red y contenedor exclusivos, almacenamiento `tmpfs` y puerto loopback aleatorio, se migró el esquema de pruebas y se obtuvieron errores reales: unicidad de área `23000/1062`, mensaje `Duplicate entry '[redacted]' for key 'areas_plant_code_unique'`; clave primaria `23000/1062`, clave `PRIMARY`; FK `23000/1452`; y espera de bloqueo `HY000/1205`. Los mensajes de valores y FK no se conservan para evitar datos innecesarios.

La espera de bloqueo se verificó con dos conexiones SQL independientes: la primera mantuvo `SELECT ... FOR UPDATE` sobre la planta y la segunda agotó su `innodb_lock_wait_timeout=1`. Esta prueba SQL no ejecutó el endpoint ni acredita peticiones HTTP simultáneas. Siguen pendientes las pruebas controladas de los tres escenarios concurrentes, ambos órdenes de modificación de la planta, y la comprobación independiente del clasificador para nombres calificados, similares y errores ajenos. No se ha establecido una conclusión general sobre deadlocks.

En SQLite `:memory:` pasaron 19 pruebas relacionadas, con 175 aserciones; PHP lint de los archivos revisados, Pint y `git diff --check` pasaron. No se modificó código funcional ni se añadieron pruebas permanentes en esta subfase. El contenedor y la red temporales se eliminaron; S1.3-B2 sigue **pendiente**, sin autorización de despliegue o publicación.

## 9. S1.3-B3 — concurrencia del alta HTTP

En MariaDB **11.8.9-MariaDB-ubu2404** efímera se ejecutaron cinco escenarios con procesos PHP independientes y conexiones separadas. Los workers ejecutaron el kernel HTTP real; no se utilizó un servidor HTTP ni sockets de red HTTP. Las modificaciones concurrentes de planta se realizaron mediante SQL controlado, no mediante endpoints. Se observó la espera real entre los IDs de conexión en `information_schema.INNODB_LOCK_WAITS`; las señales mediante archivos coordinan las fases y tienen plazos acotados. Para observarlas, el usuario temporal recibió el privilegio global `PROCESS` exclusivamente en el servidor efímero, además de sus permisos limitados a la base de prueba.

| Escenario | Evidencia |
|---|---|
| CONC-01: dos altas con el mismo código | Segundo POST espera; primero 201, segundo 422 `errors.code`; una sola área y un evento. No existe validación previa de unicidad en el FormRequest: el segundo alta encuentra la restricción SQL después del bloqueo. |
| CONC-02: desactivación primero | POST espera; tras confirmarse el cambio devuelve 422 `errors.plantId`; cero áreas y eventos. |
| CONC-03: alta primero | Desactivación SQL espera; alta 201, luego la planta queda inactiva; una área y un evento. |
| CONC-04: soft delete primero | POST espera; tras confirmarse la baja devuelve 404 `not_found`; cero áreas y eventos. |
| CONC-05: alta primero | Soft delete SQL espera; alta 201, luego la planta queda eliminada lógicamente; una área y un evento. |

Los escenarios pasaron individualmente y juntos: **5 pruebas y 89 aserciones**. La suite MariaDB obtuvo **520 pruebas, 502 aprobadas, 18 omitidas y 2086 aserciones**; SQLite `:memory:` obtuvo **520 pruebas, 499 aprobadas, 21 omitidas y 2002 aserciones**. Respecto de S1.3-B, las seis pruebas adicionales son las cinco concurrentes, omitidas en SQLite, y la prueba del clasificador con 28 aserciones simuladas. PHP lint, Pint y `git diff --check` pasaron.

Las pruebas requieren MariaDB 11.8.9 exclusivamente efímera, `TestDatabaseGuard`, `foreign_key_checks=1`, `APP_KEY` temporal y `PROCESS` para consultar esperas; ejecutan `migrate:fresh` y no deben apuntar a una base persistente. Los resultados no prueban ausencia general de deadlocks, tráfico HTTP por red ni comportamiento bajo carga. El contrato sigue sin publicar, con compatibilidad de consumidores y condiciones de despliegue pendientes.

## 10. Cierre técnico S1.3 y preparación de integración

**S1.3 está cerrada técnicamente**, con la evidencia histórica de S1.3-B3: SQLite `:memory:` 520 pruebas, 499 aprobadas, 21 omitidas y 2002 aserciones; MariaDB 11.8.9 efímera 520 pruebas, 502 aprobadas, 18 omitidas y 2086 aserciones. Los cinco escenarios concurrentes pasaron y el clasificador SQL se verificó con 28 aserciones y excepciones simuladas, además de las colisiones reales documentadas en S1.3-B/B2. No se repitieron estas suites para el cierre documental.

La revisión focalizada confirmó procesos independientes, `TestDatabaseGuard` en cada worker, credenciales transmitidas por entrada estándar y sin argumentos de proceso, barreras y tiempos máximos, y limpieza de procesos y señales en los caminos cubiertos. La guardia comprueba entorno, host y sufijo `_test`, pero no demuestra por sí sola que la base sea efímera: identificar la infraestructura antes de `migrate:fresh` sigue siendo obligatorio. Los permisos `PROCESS` son exclusivamente para el servidor de pruebas aislado, nunca para el usuario productivo.

El workflow MariaDB actual usa la etiqueta flotante `mariadb:11.8`, mientras las pruebas concurrentes exigen exactamente 11.8.9. Antes de confiar en CI debe decidirse cómo fijar o ampliar la matriz de versiones y comprobar `PROCESS` en el servicio de pruebas; no se modificó CI ni se omitieron silenciosamente pruebas. El tiempo de 15 minutos y los procesos PHP parecen suficientes según la ejecución local, pero no se ha verificado la ejecución en GitHub Actions.

OpenAPI permanece en versión `2.3.0`: el POST anteriormente planeado pasa a implementado con un esquema de entrada cerrado, DTO de ocho campos y errores actualizados. Los clientes generados pueden necesitar regeneración o cambios; no se comprobó compatibilidad con panel ni PWA, ni se autorizó publicar el contrato. Siguen vigentes las condiciones S1.1: revisar esquema y datos, discrepancias DDL/migraciones, vistas y triggers SQLite, respaldo y recuperación autorizados y bloqueos/concurrencia MariaDB; la evidencia no autoriza DDL persistente ni despliegue.
