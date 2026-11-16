# INS-01 — decisiones, implementación y validación

La solicitud S1 aprobó las decisiones S0 siguientes y prevalece donde precisa
su alcance. Los apartados de requisitos conservan el diseño original; el
estado verificado posterior figura en «Cierre de validación y alcance S3».
La preparación técnica para integración no autoriza producción.

## Decisiones aprobadas

| Decisión | Alcance |
| --- | --- |
| DEC-01 | Crear online, listar y consultar borradores; respuestas y transiciones quedan fuera. |
| DEC-02 | Tenant y actor del contexto; creación propia sin supervisor ni selección de usuarios cliente. |
| DEC-03 | Permisos explícitos execute/manage independientes, sin privilegios por rol o plataforma. |
| DEC-04 | Asignación de planta o área requerida; `locations.view_all` solo da visibilidad de catálogo. |
| DEC-05 | Planta y área obligatorias, línea opcional; validar tenant, rama, actividad y eliminación lógica. |
| DEC-06 | Checklist activo y versión publicada; preservar referencia de versión. |
| DEC-07 | UUIDv7 y fechas del servidor; auditoría transaccional minimizada. |
| DEC-08 | Creación en `draft`, sin transición ni finalización. |
| DEC-09 | Sin idempotencia persistente ni garantía de reintento seguro. |
| DEC-10 | Bloqueos coordinados con EHS; sin promesa de revocación inmediata concurrente. |
| DEC-11 | La propuesta preliminar de no agregar FK queda sustituida por DEC-16. |
| DEC-12 | Conciliar contrato sin publicar versión; incompatibilidad con POST offline anterior y PWA. |
| DEC-13 | Lectura de terceros limitada a asignaciones. |
| DEC-14 | Lectura histórica de checklist; completitud, firma, revisión y reapertura posteriores. |
| DEC-15 | Solo roles recién creados: Administrador execute y manage; Inspector execute; Supervisor manage; otros ninguno. Preservar roles existentes. |
| DEC-16 | Nuevas migraciones de integridad SQL según las cuatro FK compuestas indicadas abajo. |
| DEC-17 | La desactivación posterior de normativa no invalida por sí sola una versión legítimamente publicada. |
| DEC-18 | INS-02 lee el árbol de checklist congelado en `inspections.checklist_version_id`; no resuelve la versión publicada actual ni exige `checklists.view` o `norms.view`. |
| DEC-19 | Las respuestas solo las escribe el propietario de una inspección `draft` con `inspections.execute`, membresía y asignación vigentes; `inspections.manage` es solo lectura. |
| DEC-20 | INS-02 conserva OpenAPI 2.3.0 sin publicación y no habilita consumidores ni sincronización offline. |

## HTTP y autorización

Todas las operaciones requieren autenticación, organización cliente activa,
membresía activa y asignación a la planta o al área correspondiente. Crear
propia requiere `inspections.execute`; leer propia requiere execute o manage;
leer ajena requiere manage. Asignación solo de área no cubre áreas hermanas;
`locations.view_all` no sustituye asignación y plataforma no tiene bypass.
Autorizar antes de resolver referencias protegidas y de paginar.

`POST /api/inspections`: JSON estricto con `checklist_version_id`, `plant_id`,
`area_id` obligatorios y UUID; `production_line_id` UUID o null. Coordenadas
ambas informadas o ausentes/nulas, rangos ±90/±180 y hasta siete decimales;
descripción hasta 255 caracteres y observaciones hasta 65535 bytes. Rechazar
campos adicionales, respuestas, usuarios, estados y fechas. El servidor
establece identidad, actor, tenant, estado y fechas; responde 201 tras commit.
Auditar `inspection.created` en la transacción con referencias mínimas, sin
coordenadas, observaciones, descripciones ni tokens.

`GET /api/inspections`: `page` positivo, `per_page` 1–100 por defecto 15,
`status` draft/completed/confirmed/rejected y filtros UUID de planta/área;
rechazar filtros desconocidos. Filtrar antes de contar y paginar, ordenar por
`created_at` e `id` descendentes. Respuesta `data/meta` sin relaciones
anidadas. `GET /api/inspections/{id}` responde DTO explícito o 404 genérico
fuera de alcance; mantiene referencias históricas si recursos se desactivan,
eliminan lógicamente o la versión se archiva. El árbol histórico es INS-02.

## Integridad y concurrencia

Nuevas FK: `(checklist_version_id, organization_id)` a
`checklist_versions(id, organization_id)`; `(plant_id, organization_id)` a
`plants(id, organization_id)`; `(area_id, plant_id, organization_id)` a
`areas(id, plant_id, organization_id)`; `(production_line_id, area_id,
organization_id)` a `production_lines(id, area_id, organization_id)`. Preservar
línea nullable e identidad histórica, índices y FK entrantes; preflight sin
reparación, reversión con datos y restauración de `foreign_keys` SQLite.
Evaluar DDL parcial MariaDB. No agregar FK a respuestas ni membresías.

Al crear, bloquear organización, checklist y versión según EHS y luego planta,
área y línea en orden estable compatible con escritores. Revalidar dentro de
transacción organización, membresía, permisos sin caché obsoleta, asignaciones,
ubicaciones y checklist. GET no usa mutex organizacional. Sin protocolo común
de revocación no se garantiza revocación inmediata concurrente.

## Matriz de pruebas y riesgos planificados en S0

Casos exigidos en S0: autenticación y aislamiento; membresía revocada; permisos
independientes y roles personalizados; propias/ajenas; asignación planta/área,
hermanas, view_all y plataforma; entradas estrictas, coordenadas y bytes;
jerarquía, recursos inactivos o eliminados, checklist y norma histórica; SQL
directo, preflight y up/down/up con datos; auditoría y rollback; paginación,
orden, filtros, DTO, no filtraciones y contrato; regresión CAT/EHS. S2
añadió MariaDB aislada y concurrencia coordinada, incluida DDL parcial.

Riesgos: incompatibilidad PWA, falta de idempotencia, revocación concurrente
no garantizada y migración sobre datos existentes. La validación MariaDB
posterior no elimina el riesgo de un estado parcial no ensayado. Su aceptación
diferida no autoriza producción (EHS-DEC-19).

## Avance de implementación

Se incorporaron rutas de creación y consulta online; el contrato anterior de
sincronización offline con respuestas no es compatible con este corte ni con
la PWA inspeccionada. El DTO devuelve coordenadas como cadenas decimales de
siete posiciones o null. Las operaciones de estado siguen planeadas y la
versión OpenAPI permanece en 2.3.0, sin publicación contractual.

DEC-16 cuenta con implementación y pruebas focalizadas SQLite y MariaDB 11.8.9
efímera. La recuperación supervisada se ensayó para una frontera de fallo, no
para todos los estados posibles. El preflight SQLite
rechaza conservadoramente esquemas con vistas o triggers; no los elimina.
Véase [preparación MariaDB](../dec-16-mariadb-s2.md). La situación posterior
se resume en el cierre siguiente.

## Cierre de validación y alcance S3

La auditoría S2 y la revisión focalizada S3 están documentadas en
[s2-auditoria.md](s2-auditoria.md). El corte implementa únicamente
`POST /api/inspections` (propia, online y `draft`), `GET /api/inspections`
y `GET /api/inspections/{inspectionId}`. La organización y el inspector
proceden del contexto, los permisos `inspections.execute` y
`inspections.manage` son independientes, y la lectura y escritura requieren
asignación operativa a planta o área. `locations.view_all` no concede
ejecución. La creación usa el mutex EHS de organización y después bloquea
checklist, versión, planta, área y línea antes de revalidar. Las cuatro FK
compuestas DEC-16 protegen tenant y rama; la auditoría `inspection.created`
se registra en la transacción sin coordenadas ni observaciones.

En el árbol S2, SQLite `:memory:` pasó 714 pruebas (658 aprobadas, 56 omitidas,
3609 aserciones) y MariaDB 11.8.9 efímera pasó 714 (670 aprobadas, 44 omitidas,
3812 aserciones). Tras ampliar pruebas ordenadas S3, su validación focalizada
MariaDB pasó 5 pruebas y 269 aserciones. La suite integral posterior S3 pasó
715 pruebas en SQLite (658 aprobadas, 57 omitidas, 3609 aserciones) y MariaDB
11.8.9 (671 aprobadas, 44 omitidas, 3956 aserciones). La primera ejecución
MariaDB se interrumpió por timeout de la herramienta; una segunda detectó
un fallo intermitente de observación de bloqueo en una prueba CAT ajena a
INS-01, que pasó focalizada y en la tercera suite completa. Esta variabilidad
se conserva como riesgo, no se oculta.

La integración técnica no autoriza producción: siguen pendientes la
idempotencia persistente, sincronización offline, revocación concurrente
estricta, respuestas, árbol histórico de checklist, administración de
permisos existentes, compatibilidad PWA/panel y benchmark/estrés. No habilitar
consumidores ni desplegar hasta aprobar y validar por separado esos cortes.

### Preparación S1 de MariaDB

Se revisaron los patrones CAT-02, CAT-03 y EHS y se añadió al documento de
preparación un diagnóstico SQL de solo lectura, las claves previstas y una
matriz de ensayos de fronteras DDL para S2. No se implementaron la ruta ni el
preflight MariaDB: faltan comprobaciones automáticas completas del esquema y
dependencias, y una recuperación ensayada. Se conserva deliberadamente el
rechazo antes de DDL; no desplegar en MariaDB. No se ha ejecutado una prueba
MariaDB en esta preparación.


## INS-02 — respuestas y checklist histórico

El corte INS-02 implementa `GET /api/inspections/{inspectionId}/checklist`,
`GET /api/inspections/{inspectionId}/answers`,
`PUT /api/inspections/{inspectionId}/answers/{questionId}` y
`DELETE /api/inspections/{inspectionId}/answers/{questionId}`. El checklist se
resuelve por la versión congelada en la inspección; las respuestas se validan
por tipo, solo se escriben en `draft` y la integridad se refuerza con tres FK
compuestas. La migración rechaza datos incoherentes sin repararlos y se validó
con `up/down/up` en SQLite y MariaDB 11.8.9.

Documentación: [diseño](ins-02/01-diseno.md),
[decisiones](ins-02/02-decisiones.md),
[contrato HTTP](ins-02/03-contrato-http.md),
[integridad](ins-02/04-integridad.md),
[autorización](ins-02/05-autorizacion.md),
[validación](ins-02/06-validacion.md),
[concurrencia](ins-02/07-concurrencia.md),
[matriz de pruebas](ins-02/08-matriz-pruebas.md),
[riesgos residuales](ins-02/09-riesgos-residuales.md),
[auditoría independiente S2](ins-02/s2-auditoria.md) y
[consolidación y revisión focalizada S3](ins-02/s3-consolidacion.md).

### Verificación de INS-01-DEC-18–20

En el árbol consolidado de S3 se comprobó que las decisiones de continuidad se
cumplen:

- **INS-01-DEC-18**: el checklist se resuelve exclusivamente por
  `inspections.checklist_version_id`; las pruebas cubren versión archivada,
  checklist desactivado y versión nueva publicada, sin exigir
  `checklists.view` ni `norms.view`.
- **INS-01-DEC-19**: `PUT` y `DELETE` exigen propietario, `inspections.execute`,
  membresía y asignación vigentes e inspección `draft`; `inspections.manage`
  solo permite lectura.
- **INS-01-DEC-20**: OpenAPI permanece en 2.3.0 sin publicar; no se habilitaron
  consumidores ni sincronización offline.

OpenAPI permanece en 2.3.0; las cuatro operaciones están implementadas y el
resto sigue `planned`. La publicación contractual, la migración en entornos
persistentes y cualquier cambio en consumidores requieren autorización separada.
