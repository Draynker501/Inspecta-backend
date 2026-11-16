# INS-02 — decisiones

| Decisión | Contenido |
| --- | --- |
| INS-02-DEC-01 | El corte expone exactamente cuatro operaciones: checklist histórico, listado de respuestas, `PUT` de respuesta y `DELETE` de respuesta. No se agregan transiciones, sincronización ni rutas auxiliares. |
| INS-02-DEC-02 | El checklist se resuelve por `inspections.checklist_version_id`. No se consulta la versión publicada actual, el estado del checklist ni el estado de la versión. |
| INS-02-DEC-03 | Se devuelven solo secciones y preguntas de la versión original y de la organización; las preguntas inactivas se omiten. No se mezclan preguntas de otras versiones. |
| INS-02-DEC-04 | `supported` distingue tipos escribibles (`boolean`, `text`, `number`, `date`, `multiple_choice`) de `signature` y tipos desconocidos. Una pregunta no soportada sigue siendo visible como lectura histórica. |
| INS-02-DEC-05 | La identidad funcional es `(inspection_id, question_id)`. Se preserva el unique y no se crean duplicados. `PUT` es reemplazo completo; `DELETE` es idempotente. |
| INS-02-DEC-06 | El cuerpo de `PUT` está cerrado a `answer` y `observation`. Organización, inspección, pregunta, versión, sección, identificadores y timestamps se derivan en el servidor. |
| INS-02-DEC-07 | El valor se valida según `questions.answer_type`: boolean exacto; texto no vacío y no compuesto solo por espacios; decimal simple; fecha real; opción configurada exacta. Se rechazan `signature`, `__NA__`, arreglos, `null`, cadena vacía y observación sin respuesta. |
| INS-02-DEC-08 | Los límites son 65535 bytes UTF-8 para `answer` y `observation`, y 64 caracteres para `number`. El recorte se desactiva únicamente en `PUT api/inspections/*/answers/*` mediante una condición de método y ruta; el resto de la API conserva `TrimStrings` y ningún otro módulo se ve afectado. |
| INS-02-DEC-09 | Lectura: inspección propia con `inspections.execute` o `manage`; ajena solo con `manage`; membresía activa, asignación vigente y mismo tenant. No se exige `checklists.view` ni `norms.view`. |
| INS-02-DEC-10 | Escritura y borrado: propietario, `inspections.execute`, membresía activa, asignación vigente e inspección `draft`. `inspections.manage` nunca concede escritura. |
| INS-02-DEC-11 | Mapeo de denegaciones: inspección invisible o pregunta fuera de la versión → 404; inspección visible no escribible → 403; pregunta inactiva, inspección no `draft` o valor inválido → 422 con la clave correspondiente. |
| INS-02-DEC-12 | Solo se escribe en `draft`. Al cambiar de estado las respuestas se conservan, pero `PUT` y `DELETE` responden 422 (`inspection`) sin modificar datos. |
| INS-02-DEC-13 | Integridad: `checklist_version_id` y `checklist_section_id` son CHAR(36) NOT NULL y se añaden tres FK compuestas. Se preserva el unique funcional, la FK entrante de `evidences`, las acciones referenciales históricas y los índices ajenos. |
| INS-02-DEC-14 | La migración ejecuta un preflight sin reparación, hace backfill determinista, rechaza estados parciales y se valida con `up/down/up` en SQLite y MariaDB 11.8. Un estado parcial requiere recuperación supervisada. |
| INS-02-DEC-15 | Cada escritura bloquea la inspección con `lockForUpdate` y revalida dentro de la transacción. No hay mutex organizacional. La política es last-write-wins, sin control optimista ni idempotencia de red. |
| INS-02-DEC-16 | Se auditan `inspection.answer_created`, `inspection.answer_updated` e `inspection.answer_deleted` dentro de la transacción, con actor, tenant, identificadores, tipo y campos modificados; nunca valores ni observaciones. Un `PUT`/`DELETE` sin efecto no audita. |
| INS-02-DEC-17 | OpenAPI permanece en 2.3.0. Las cuatro operaciones se marcan implementadas y el resto sigue `planned`. Se documenta el DTO, la validación, la autorización, la paginación, la idempotencia, los errores, el límite de estado y la incompatibilidad offline. |

## Decisiones de consolidación (S3)

Estas decisiones no modifican las acciones referenciales ni el alcance de
INS-02-DEC-01–17; precisan comportamiento ya implementado.

| Decisión | Contenido |
| --- | --- |
| INS-02-DEC-18 | El listado de respuestas usa `per_page` predeterminado 100 y máximo 100 mediante un parámetro OpenAPI propio (`answersPerPageParam`). El parámetro compartido del resto de colecciones conserva su valor predeterminado 15. |
| INS-02-DEC-19 | La visibilidad de la inspección se resuelve antes de validar la paginación: una inspección no visible responde 404 con independencia de los parámetros de consulta, de modo que la ruta de denegación no sea distinguible por el código. |
| INS-02-DEC-20 | En `DELETE`, solo la violación de la FK entrante de `evidences` se traduce a 422. Cualquier otro error de integridad se propaga sin etiquetarse como bloqueo por evidencias. Los errores transitorios de concurrencia (1213 interbloqueo, 1205 espera agotada) también se propagan como 500: no se reintentan ni se ocultan. |
| INS-02-DEC-21 | La conmutación de `DROP FOREIGN KEY` y `ADD CONSTRAINT` se ejecuta en un único `ALTER TABLE` en `up()` y en `down()`. MariaDB 11.8 aplica DDL atómico: si una cláusula falla, la sentencia completa se revierte y no queda una ventana sin FK. |
| INS-02-DEC-22 | `text` rechaza cadenas compuestas exclusivamente por espacios en blanco (ASCII, tabuladores, saltos de línea y separadores Unicode) sin recortar el valor: un texto con algún carácter significativo se almacena byte a byte. |
