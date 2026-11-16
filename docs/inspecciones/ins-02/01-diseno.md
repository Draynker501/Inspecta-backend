# INS-02 — diseño técnico

## 1. Punto de partida

Una inspección guarda `checklist_version_id` al crearse (INS-01). Esa
referencia es la fuente de verdad histórica: no se resuelve la versión
publicada actual ni el estado presente del checklist, la versión, las normas o
los requerimientos.

## 2. Componentes

| Componente | Responsabilidad |
| --- | --- |
| `InspectionController::checklist()` | Resuelve la inspección visible y devuelve el árbol histórico de su versión. |
| `InspectionAnswerController` | Lista, crea/reemplaza y elimina respuestas. |
| `InspectionAnswerValidator` | Valida el valor según `questions.answer_type`. |
| `InspectionAnswerPreflight` | Detecta respuestas huérfanas o incoherentes antes de la migración, sin reparar. |
| `InspectionPolicy::viewAnswers()` / `answer()` | Autorización de lectura y escritura. |
| Migración `2026_11_19_000001_...` | Columnas de versión/sección y tres FK compuestas. |

## 3. Flujo de lectura del checklist

1. Se exige `viewAny` de inspecciones (membresía activa y execute o manage).
2. Se resuelve la inspección por `organization_id` del contexto. Fuera del
   tenant o inexistente: 404.
3. Se aplica `InspectionPolicy::view()`: propietario o `inspections.manage`,
   más asignación vigente. Una inspección visible pero no autorizada: 403; una
   inspección oculta: 404.
4. Se carga la versión referenciada con checklist, secciones y preguntas de la
   misma organización.
5. Solo se incluyen preguntas `status = true`. No se mezclan preguntas de la
   versión actual ni de otra versión.
6. Cada pregunta expone `supported`, calculado por
   `InspectionAnswerValidator::supports()`.
7. Se ordenan secciones y preguntas por `display_order` e `id`, y los
   requerimientos por `code` e `id`.

La lectura no exige `checklists.view` ni `norms.view`, no consulta el estado
de la norma y no escribe auditoría.

## 4. Flujo de escritura de respuestas

`PUT` y `DELETE` comparten esta secuencia:

1. Autorización de visibilidad (`viewAny` + `viewAnswers`); si la inspección
   no es visible: 404.
2. Comprobación temprana de escritura; si es visible pero no escribible: 403.
3. Transacción con `lockForUpdate()` sobre la fila de la inspección.
4. `flushPermissionCache()` y revalidación de membresía, permisos,
   propiedad, asignación y estado dentro de la transacción.
5. Resolución de la pregunta dentro de la organización y de la versión
   original de la inspección; si no existe o es de otra versión: 404.
6. Rechazo de preguntas inactivas con 422 (`question`).
7. Validación del valor con el tipo de la pregunta.
8. Lectura/escritura/borrado de la respuesta y auditoría en la misma
   transacción.

`PUT` crea con UUIDv7 si no existe, o reemplaza `answer` y `observation` si
existe. Un contenido idéntico no cambia timestamps ni audita.

`DELETE` elimina si existe. Si no existe y la pregunta es válida, responde 204
sin auditar. Si hay evidencias, responde 422 y no borra nada.

## 5. Modelo de datos

`inspection_answers` conserva su identidad funcional
`(inspection_id, question_id)` y añade:

- `checklist_version_id` CHAR(36) NOT NULL;
- `checklist_section_id` CHAR(36) NOT NULL.

Las tres FK compuestas garantizan que inspección, sección y pregunta
pertenecen a la misma organización y versión. El detalle está en
[integridad](04-integridad.md).

## 6. Fuera de alcance

No se implementan transiciones, cierre, firma, evidencias nuevas, adjuntos,
reapertura, borrado en cascada de evidencias, sincronización offline ni
idempotencia persistente de red. La política de concurrencia es
last-write-wins documentada en [concurrencia](07-concurrencia.md).
