# INS-02 — matriz de pruebas

## Archivos

| Archivo | Cobertura |
| --- | --- |
| `tests/Feature/Tenancy/InspectionAnswerHttpTest.php` | Histórico, autorización, cinco tipos, valores inválidos, campos protegidos, bytes, idempotencia, evidencias, no-draft, paginación, auditoría, superficie cerrada y requisitos normativos históricos. |
| `tests/Feature/Database/InspectionAnswerMigrationTest.php` | Migración SQLite, FK compuestas, cruces, preflight, estado parcial, reversibilidad, backfill, evidencias y fallo inducido. |
| `tests/Feature/Database/InspectionAnswerMariaDbTest.php` | Equivalente funcional en MariaDB 11.8.9 y acciones referenciales por SQL directo (CASCADE y RESTRICT con columnas compartidas). |
| `tests/Feature/Database/InspectionAnswerConcurrencyMariaDbTest.php` | Diez escenarios multiproceso: dos `PUT` sobre la misma pregunta, preguntas distintas, `PUT`/`DELETE` en ambos órdenes, inspecciones distintas, organizaciones distintas, evidencia concurrente, error de integridad ajeno a `evidencias`, `DELETE` repetido y `PUT` idéntico. |
| `tests/Feature/Database/InspectionAnswerMariaDbDdlRecoveryTest.php` | Fronteras DDL de MariaDB, detección de estado parcial, ausencia de registro de la migración, recuperación supervisada, `up/down/up` con datos y atomicidad del `ALTER TABLE` agrupado. |
| `tests/Feature/Api/RouteSurfaceTest.php` | Registro de las cuatro rutas. |
| `tests/Feature/Documentation/InspectionAnswersOpenApiContractTest.php` | Contrato OpenAPI de INS-02. |
| `tests/Feature/Documentation/OpenApiRouteCorrespondenceTest.php` | Correspondencia OpenAPI–Laravel. |

## Casos HTTP

- Checklist histórico con versión archivada, checklist desactivado, norma
  desactivada, pregunta inactiva y versión nueva publicada: solo se devuelve la
  versión original y sus preguntas activas.
- Lectura propia con execute, ajena con manage, ajena sin manage, área hermana,
  sin asignación, `locations.view_all`, membresía revocada y otro tenant.
- Los cinco tipos válidos, incluidos `-0012.50`, `2024-02-29` y opción exacta.
- Valores inválidos por tipo, `signature`, `__NA__`, `null`, arreglo, cadena
  vacía, exponente, coma, `INF`, `NAN`, fecha imposible y opción inexistente.
- Campos protegidos: `organization_id`, `inspection_id`,
  `checklist_version_id`, `checklist_section_id`, `id`, timestamps y `status`.
- Bytes: 65535 exactos aceptados y 65536 rechazados; espacios conservados.
- `text` compuesto solo por espacios (ASCII, tabuladores, saltos y separadores
  Unicode) rechazado sin recortar; un texto válido conserva sus espacios
  extremos.
- Idempotencia de `PUT` y `DELETE`, ausencia de auditoría en no-ops.
- Borrado bloqueado por evidencias, sin borrar la evidencia.
- Error de integridad ajeno a `evidences` (tabla auxiliar que referencia
  `inspection_answers`): no se traduce a 422, la respuesta y la evidencia se
  conservan y no se audita.
- Inspección no `draft`: `PUT` y `DELETE` responden 422 y conservan la fila.
- Pregunta de otra versión o inexistente: 404.
- Paginación: predeterminado 100, máximo 100, valores explícitos respetados.
- Parámetros de consulta inválidos o desconocidos sobre una inspección visible
  (422) y sobre una inspección no visible (404, con independencia de la
  consulta).
- Parámetros de consulta en las escrituras: 422 y ningún cuerpo almacenado.
- Requisitos normativos históricos: la modificación de un requisito enlazado
  a una versión publicada o archivada se rechaza y la lectura histórica
  conserva el contenido original.
- Rollback si falla la auditoría en creación, en actualización y en borrado.

## Casos de integridad

- Columnas, tipos, nulabilidad, índices padre/hijos y tres FK compuestas.
- Duplicado funcional.
- Pregunta de otra organización, de otra versión o de otra sección.
- Inspección de otra organización.
- Borrado restringido de pregunta y sección.
- Borrado en cascada de la inspección cuando no hay evidencias.
- Preservación de filas, evidencias e índices ajenos en `up/down/up`.
- Preflight sin reparación y sin DDL.
- Estado parcial por columna o índice inesperado.
- Fallo inducido durante la reconstrucción SQLite: DDL y datos intactos,
  `foreign_keys` restaurado.

## Casos MariaDB

- Esquema desde cero y `up/down/up` con datos.
- Backfill de versión y sección.
- Cruces de tenant, versión y sección.
- Preflight y estado parcial sin DDL.
- Índices ajenos preservados.
- FK entrante de evidencias preservada.
- Concurrencia: una fila ante dos `PUT`, dos filas ante preguntas distintas,
  resultado consistente ante `PUT`/`DELETE` en ambos órdenes, inspecciones y
  organizaciones distintas, evidencia concurrente y error de integridad ajeno
  a `evidences`.
- Atomicidad del `ALTER TABLE` agrupado: un `ADD CONSTRAINT` inválido revierte
  la sentencia completa y las FK previas permanecen.

## Omisiones por motor

Las omisiones son siempre condicionales y se resuelven en el motor
correspondiente; ninguna prueba aplicable queda deshabilitada en ambos.

| Motor | Motivo | Pruebas omitidas en S3 |
| --- | --- | --- |
| SQLite | Las pruebas de integridad directa, concurrencia multiproceso y recuperación DDL requieren MariaDB 11.8.9 y se omiten con una condición de driver. | 19 de S2 (7 de concurrencia, 6 de integridad directa y 6 de recuperación DDL) más 3 de S3 (atomicidad y dos escenarios de concurrencia). |
| MariaDB | El escenario de error de integridad ajeno a `evidences` crea una tabla auxiliar con DDL: en MariaDB el DDL provoca `COMMIT` implícito y contaminaría el esquema compartido de la suite. | 1 (S2), cubierta de forma equivalente en `InspectionAnswerConcurrencyMariaDbTest`. |

El incremento de omisiones en SQLite de 65 a 84 durante S2 se explica por las
19 pruebas MariaDB-only añadidas; el total pasó de 743 a 768 pruebas. En S3 el
total sube a 772: una prueba HTTP nueva se ejecuta en SQLite y tres pruebas
MariaDB nuevas se omiten en SQLite (87 omisiones).

## Validación ejecutada

Las cifras finales y las omisiones se registran en el informe de cierre del
corte. Las pruebas específicas del otro motor se omiten explícitamente; una
omisión no se interpreta como éxito. La suite completa se ejecuta en SQLite
`:memory:` y en MariaDB 11.8.9 efímera con base terminada en `_test`.
