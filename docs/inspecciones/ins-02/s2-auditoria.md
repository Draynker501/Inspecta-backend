# INS-02 S2 — auditoría independiente y validación

**Veredicto: preparación técnica condicionada para S3; no aprobación de producción.**

La auditoría independiente del corte INS-02 (S1) detectó cuatro hallazgos
sobre el árbol auditado: uno de severidad alta (paginación divergente con el
requisito) y tres de severidad media (orden de comprobaciones con fuga de
información, traducción SQL demasiado amplia y excepción global de recorte de
espacios). Los cuatro están corregidos y verificados en este corte, con
pruebas que fallarían ante una regresión.

La auditoría no repite S0, no reimplementa componentes y no introduce
funcionalidad fuera del alcance aprobado (sin transiciones, firmas, incidencias,
evidencias, sincronización offline ni cambios en consumidores).

## 1. Hallazgos por severidad y correcciones

| Severidad | Hallazgo | Corrección | Evidencia |
| --- | --- | --- | --- |
| Alta | `GET /api/inspections/{inspectionId}/answers` devolvía 15 elementos por página (valor del parámetro compartido `perPageParam`), mientras el requisito de S1 fija 100 como predeterminado. La prueba de listado no cubría el valor predeterminado, por lo que la divergencia no era detectable. | El controlador usa 100 como predeterminado; se añadió un parámetro OpenAPI propio `answersPerPageParam` (predeterminado 100, máximo 100) sin alterar el parámetro compartido `perPageParam`, que sigue en 15 para el resto de colecciones; se alinearon `openapi.yaml`, `docs/inspecciones/ins-02/03-contrato-http.md` y la prueba contractual. | `app/Http/Controllers/InspectionAnswerController.php:52`; `openapi.yaml` (`components.parameters.answersPerPageParam`); `tests/Feature/Tenancy/InspectionAnswerHttpTest.php::test_listado_devuelve_cien_respuestas_por_pagina_por_defecto`; `tests/Feature/Documentation/InspectionAnswersOpenApiContractTest.php`. |
| Media | Orden de comprobaciones en el listado: la validación de la paginación precedía a la resolución y autorización del recurso, de modo que una inspección no visible respondía 422 con parámetros inválidos y 404 sin ellos. La ruta de denegación era distinguible. | La visibilidad del recurso se resuelve antes de validar la paginación. La respuesta es 404 con independencia de los parámetros de consulta. | `app/Http/Controllers/InspectionAnswerController.php:33-46`; prueba `test_la_visibilidad_se_resuelve_antes_de_validar_la_paginacion`. |
| Media | Traducción de errores SQL demasiado amplia: cualquier `QueryException` con SQLSTATE `23000` durante el borrado se presentaba como bloqueo por evidencias y se convertía en 422, ocultando otros errores de integridad. | La traducción exige ahora las tres condiciones: SQLSTATE `23000`, error de clave foránea (1451 en MariaDB o mensaje de FK en SQLite) y que el error corresponda a `evidences` (en MariaDB el motor nombra la tabla o la restricción; en SQLite se comprueba la evidencia asociada a la respuesta). Cualquier otro error se propaga. | `app/Http/Controllers/InspectionAnswerController.php` (`isEvidencesForeignKeyViolation`); pruebas `test_un_error_de_integridad_distinto_de_evidencias_no_se_traduce_a_422` (SQLite, en `InspectionAnswerHttpTest`) y equivalente en MariaDB (`InspectionAnswerConcurrencyMariaDbTest`), que comprueban 500, conservación de la respuesta y ausencia de auditoría. |
| Media | Excepción global de `TrimStrings` por nombre de campo (`answer`, `observation`): afectaba a todas las rutas de la API, no solo al flujo de respuestas. | La excepción global se eliminó. El recorte se desactiva exclusivamente para la escritura de respuestas mediante `TrimStrings::skipWhen` con una condición de método y ruta (`PUT api/inspections/*/answers/*`). El resto de la API conserva el recorte. Además, el valor se toma del cuerpo JSON crudo y las escrituras no admiten parámetros de consulta, de modo que ningún parámetro puede aportar el valor ni eludir la validación. | `bootstrap/app.php:41-52`; `app/Http/Controllers/InspectionAnswerController.php` (`assertNoQueryParameters`, lectura del cuerpo JSON); pruebas `test_los_parametros_de_consulta_no_permiten_eludir_el_cuerpo_json` y `test_los_requisitos_normativos_historicos_no_se_pueden_modificar`. |

Hallazgo detectado durante la propia corrección: la primera versión de la
traducción acotada exigía que `evidences` fuese la **única** tabla que
referenciaba `inspection_answers`. Esa condición era frágil (bastaba con que
apareciera otra tabla referenciante para perder el 422 por evidencias) y se
sustituyó por la identificación directa de la causa: el mensaje del motor o la
evidencia asociada a la respuesta.

## 2. Garantías verificadas

### Autorización y aislamiento

- Lectura: inspección propia con `inspections.execute` o `inspections.manage`;
  ajena solo con `manage`; membresía activa, asignación vigente y mismo tenant.
- Escritura y borrado: propietario, `inspections.execute`, membresía activa,
  asignación vigente e inspección `draft`. `inspections.manage` nunca escribe.
- `locations.view_all` no sustituye la asignación; no hay bypass de plataforma.
- No se exigen `checklists.view` ni `norms.view`.
- Revalidación dentro de la transacción tras `lockForUpdate()`, con vaciado de
  la caché de permisos.
- El orden de comprobaciones hace que una inspección no visible responda 404
  con independencia de los parámetros de consulta.

### Integridad SQL (comprobación directa en MariaDB 11.8.9)

- CASCADE al actualizar el identificador de la inspección: se propaga a
  `inspection_answers` y a `evidences`, y la FK compuesta sigue satisfecha.
- RESTRICT al borrar una pregunta o una sección con respuestas: rechazo con
  1451 y datos intactos; una pregunta sin respuestas sí se borra.
- CASCADE al borrar una inspección sin evidencias; rechazo RESTRICT con
  evidencias, conservando respuestas y evidencias.
- Actualizar `checklist_version_id`, `checklist_section_id` o el identificador
  de la sección padre se rechaza (1452) y no deja filas inconsistentes.
- Ocho inserciones incoherentes rechazadas por las tres FK compuestas:
  organización distinta, versión distinta, sección inconsistente, pregunta de
  otra organización, pregunta de otra versión, inspección de otra organización
  (con y sin su versión).

### Recuperación DDL parcial (MariaDB 11.8.9)

Fronteras identificadas: (1) lectura de `information_schema`, (2) `ADD COLUMN`,
(3) backfill, (4) `MODIFY NOT NULL`, (5) índices padre, (6) índices hijo,
(7) `DROP FOREIGN KEY` simples, (8) `ADD CONSTRAINT` compuestas.

Simulación controlada: una segunda conexión retiene el metadato de
`inspections` (`LOCK TABLES inspections READ`) mientras la migración se
ejecuta con `lock_wait_timeout = 3` s. La migración falla con 1205 **después**
de añadir las columnas y **antes** de los índices.

Comprobado:

- el estado parcial se detecta y la migración aborta sin continuar;
- la tabla `migrations` no registra la migración, de modo que un reintento no
  es un reintento inseguro: el siguiente intento vuelve a abortar por estado
  parcial;
- `down` desde el estado esperado restaura el esquema previo (FK simples, sin
  columnas nuevas) conservando filas, evidencias e índices ajenos;
- `up/down/up` completo con datos conserva respuestas y evidencias;
- tras una recuperación supervisada (deshacer el paso fallido) el migrador
  completa y registra la migración.

Fronteras **no** ensayadas, documentadas en
`tests/Feature/Database/InspectionAnswerMariaDbDdlRecoveryTest.php`: la
frontera 1 (requiere alterar la instancia), las fronteras 3 y 4 (inalcanzables
por fallo de motor: el preflight y las restricciones los impiden), la frontera
5 con el índice padre ya creado (la comprobación de estado falla antes), las
fronteras 7 y 8 (ventana demasiado estrecha para inducir de forma fiable) y la
ventana real de inconsistencia entre `DROP FOREIGN KEY` y `ADD CONSTRAINT`, que
existe porque el DDL de MariaDB no es transaccional. La cobertura es
representativa, no exhaustiva.

### Validación HTTP

- Cinco tipos con comparación estricta, límites de bytes con multibyte,
  rechazo de `null`, vacío, `signature` y `__NA__`, cuerpo cerrado y
  parámetros de consulta no admitidos en las escrituras.
- UUIDv7 en la creación, reemplazo completo con `PUT`, `DELETE` bloqueado por
  evidencias.
- Requisitos normativos históricos: la modificación de un requisito enlazado
  a una versión publicada o archivada se rechaza (`NormWriteGuard`) y la
  lectura histórica conserva el contenido original.

### Concurrencia (MariaDB 11.8.9, procesos independientes)

| Escenario | Resultado comprobado |
| --- | --- |
| Dos `PUT` sobre la misma pregunta | Espera de bloqueo observada en el motor (`TRX HAS BEEN WAITING … FOR THIS LOCK TO BE GRANTED` sobre `select … from inspections … for update`), 1 sola fila, `answer_created` = 1 y `answer_updated` ≤ 1 |
| `PUT` simultáneos sobre preguntas distintas | Dos respuestas, cada una con su `question_id` y `organization_id` |
| `PUT` frente a `DELETE` en ambos órdenes | Orden imposing con doble barrera: borrado→escritura deja 1 fila (`created` 1, `deleted` 1); escritura→borrado deja 0 filas (`updated` 1, `deleted` 1) |
| Escrituras sobre inspecciones distintas | Cada respuesta conserva su `inspection_id` |
| Escrituras sobre organizaciones distintas | Cada respuesta y su auditoría conservan su `organization_id`; sin cruce |
| `DELETE` frente a inserción concurrente de evidencia (SQL aislado con PDO) | Nunca 500: 422 con clave `answer` conservando respuesta y evidencia, o 204 con la evidencia rechazada por la FK |
| `DELETE` repetido | 204 y 204, una sola auditoría `answer_deleted` |
| `PUT` idéntico | Mismo `id`, mismas marcas de tiempo (con pausa de 1 s entre secuencias), sin auditorías `answer_updated` |

### Auditoría

- `inspection.answer_created`, `inspection.answer_updated` e
  `inspection.answer_deleted`, transaccionales y minimizados (sin valores ni
  observaciones).
- Rollback completo demostrado al fallar `AuditLogger` en creación, en
  actualización y en borrado: la respuesta conserva su valor previo, la fila
  no se elimina y no queda ninguna auditoría.

### OpenAPI

- 2.3.0, cuatro operaciones implementadas, resto `planned`, DTO, paginación,
  errores y autorización documentados. No publicado.

## 3. Riesgos residuales

| Riesgo | Impacto | Estado |
| --- | --- | --- |
| DDL no transaccional en MariaDB | Una interrupción entre `DROP FOREIGN KEY` y `ADD CONSTRAINT` puede dejar un esquema inconsistente. La migración lo detecta y se niega a continuar, pero la recuperación es manual y supervisada; la cobertura de fronteras es representativa, no exhaustiva. | Abierto, documentado en la sección 2. |
| Interbloqueo o espera de bloqueo agotada | Un 1213 o un 1205 durante una escritura se propaga como 500, no como 422: es intencionado, porque solo la violación de la FK de `evidences` debe traducirse y ningún otro error SQL debe ocultarse. | Aceptado y documentado. |
| Revocación concurrente | La revalidación tras el bloqueo reduce la ventana, pero no se garantiza revocación inmediata en todos los interleavings. | Aceptado para el corte. |
| Idempotencia de red | Dos peticiones idénticas pueden repetir un `PUT`; no hay clave de idempotencia. | Fuera de alcance. |
| Sincronización offline | El contrato no es compatible con la PWA ni con el POST offline anterior. | Fuera de alcance; no habilitar consumidores. |
| Texto solo con espacios | `text` rechaza la cadena vacía pero admite una cadena formada solo por espacios, porque el recorte está deshabilitado en esta ruta. | Ambigüedad del requisito; a decidir en S3. |
| `up()` no idempotente | Aplicar la migración sobre un esquema ya migrado aborta: el migrador depende de la tabla `migrations`, lo que hace imprescindible la recuperación supervisada probada. | Documentado. |
| Observabilidad del motor | `information_schema.INNODB_LOCK_WAITS` puede devolver vacío para esperas entre procesos; la espera sí es visible en `SHOW ENGINE INNODB STATUS`. | Observación del motor, no defecto de la aplicación. |
| Contrato OpenAPI | Las cuatro operaciones quedan implementadas en 2.3.0; no se publica ni se regenera un cliente. | Requiere autorización separada. |
| Producción | No se autoriza despliegue, migración ni publicación. | Bloqueado por decisión del responsable. |

## 4. Resultados de la validación integral

Sobre el árbol final auditado:

| Comprobación | Resultado |
| --- | --- |
| Suite completa SQLite (`:memory:`) | 768 pruebas, 684 aprobadas, 84 omitidas, 4093 aserciones, salida 0 |
| Suite completa MariaDB 11.8.9 (contenedor efímero aislado) | 768 pruebas, 716 aprobadas, 52 omitidas, 4862 aserciones, salida 0 |
| Pruebas de migraciones | SQLite y MariaDB: up/down/up, preflight, backfill, estado parcial y recuperación |
| Pruebas concurrentes | 10 escenarios en MariaDB multiproceso |
| Pruebas contractuales OpenAPI y superficie de rutas | Aprobadas |
| `php -l` de los PHP modificados | Sin errores |
| `vendor/bin/pint --test` | Aprobado |
| `git diff --check` | Sin incidencias |

Nota de entorno: durante la auditoría se obtuvo un código de salida 1 con un
resumen de pruebas superadas. Se reprodujo en dos ejecuciones simultáneas
sobre el mismo servidor MariaDB y en una ejecución aislada; la repetición final
en aislamiento devolvió salida 0 con las mismas cifras. El código de salida no
se atribuye a un defecto de INS-02, sino a interferencia entre dos procesos
que compartían base de datos.

## 5. Condiciones para S3 y producción

S3 requiere, como mínimo:

1. resolver las fronteras DDL no ensayadas y definir un procedimiento de
   recuperación supervisada;
2. decidir el tratamiento del texto formado solo por espacios;
3. decidir la política de errores transitorios de concurrencia (500 frente a
   503 con reintento) y la idempotencia de red;
4. cualquier cambio en consumidores requiere autorización separada.

La preparación para integración técnica **no** equivale a aprobación para
producción: siguen pendientes la migración en un entorno persistente, la
publicación del contrato, el despliegue y la habilitación de consumidores.
