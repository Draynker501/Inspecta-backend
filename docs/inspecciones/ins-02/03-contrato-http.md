# INS-02 — contrato HTTP

Todas las rutas requieren `Authorization: Bearer` y `X-Organization-Id` con una
organización cliente activa. El contexto se valida antes de resolver la
inspección (middleware `tenant.require`).

## `GET /api/inspections/{inspectionId}/checklist`

- Autorización: la de la inspección (`inspections.execute` o
  `inspections.manage`, membresía y asignación). No exige permisos de catálogo.
- Sin parámetros de consulta.
- Respuesta 200:

```json
{
  "data": {
    "inspection_id": "uuid",
    "checklist": { "id": "uuid", "name": "string|null", "status": true },
    "checklist_version": {
      "id": "uuid",
      "version": "1",
      "status": "archived",
      "published_at": "2026-10-10T14:30:00Z"
    },
    "sections": [
      {
        "id": "uuid",
        "name": "string",
        "description": "string|null",
        "display_order": 1,
        "questions": [
          {
            "id": "uuid",
            "question_text": "string",
            "description": "string|null",
            "answer_type": "boolean",
            "options": [{ "value": "si", "label": "Sí" }],
            "required": true,
            "allows_evidence": true,
            "display_order": 1,
            "supported": true,
            "norm_requirements": [
              { "link_id": "uuid", "id": "uuid", "code": "REQ-1", "description": "string|null" }
            ]
          }
        ]
      }
    ]
  }
}
```

Errores: 400 contexto inválido, 401 no autenticado, 403 sin acceso,
404 inspección inexistente, de otra organización o no visible.

## `GET /api/inspections/{inspectionId}/answers`

- Parámetros: `page` ≥ 1; `per_page` 1–100 (predeterminado 100). Cualquier otro
  parámetro o valor inválido → 422 `validation_failed`.
- La visibilidad del recurso se resuelve **antes** de validar la paginación:
  una inspección no visible responde 404 con independencia de los parámetros
  de consulta, de modo que la ruta de denegación no sea distinguible.
- Orden ascendente por `created_at` e `id`.
- Respuesta 200 con `data` de respuestas y `meta` de paginación
  (`current_page`, `last_page`, `per_page`, `total`).
- Funciona en cualquier estado de la inspección; es solo lectura.
- Errores: 400, 401, 403, 404 y 422 de validación.

## `PUT /api/inspections/{inspectionId}/answers/{questionId}`

Cuerpo cerrado:

```json
{ "answer": "true", "observation": "opcional o null" }
```

- `answer` obligatorio; `observation` opcional y nullable. Omitirla equivale a
  `null` (reemplazo completo).
- `answer` se valida según `questions.answer_type` (véase
  [validación](06-validacion.md)). Para `text` no se admite una cadena vacía
  ni compuesta exclusivamente por espacios en blanco; un texto válido se
  conserva sin recortar, incluidos sus espacios extremos.
- El cuerpo JSON es la única fuente del valor: los parámetros de consulta no
  pueden aportarlo ni eludir la validación. Cualquier parámetro de consulta
  responde 422 (`fields`).
- Cualquier campo adicional en el cuerpo (`id`, `organization_id`,
  `inspection_id`, `question_id`, `checklist_version_id`,
  `checklist_section_id`, `created_at`, `updated_at`, `status`, etc.) → 422.
- 200 con el DTO de la respuesta:

```json
{
  "data": {
    "id": "uuid-v7",
    "inspection_id": "uuid",
    "question_id": "uuid",
    "answer": "true",
    "observation": null,
    "created_at": "2026-11-16T10:00:00Z",
    "updated_at": "2026-11-16T10:00:00Z"
  }
}
```

- Repetir el mismo contenido no cambia timestamps ni crea auditoría.
- Errores: 400, 401, 403 (visible pero no escribible), 404 (inspección o
  pregunta fuera de la versión) y 422 (cuerpo/valor inválido, pregunta
  inactiva o inspección no `draft`).

## `DELETE /api/inspections/{inspectionId}/answers/{questionId}`

- 204 si elimina o si la pregunta es válida y no hay respuesta.
- 422 si la inspección no es `draft`, la pregunta está inactiva o existen
  evidencias asociadas.
- Solo la violación de la FK entrante de `evidences` se traduce a 422; cualquier
  otro error de integridad se propaga y no se presenta como un bloqueo por
  evidencias.
- No admite parámetros de consulta: cualquiera de ellos responde 422.
- 403/404 con la misma matriz que `PUT`.
- Nunca elimina evidencias ni genera auditoría cuando no borra nada.

## Paginación, idempotencia y errores

- `PUT` es idempotente por contenido; no hay clave de idempotencia de red.
- `DELETE` es idempotente por identidad funcional.
- Los errores de validación usan el formato `Error` con `code:
  validation_failed` y el detalle por campo.
- Los errores de autorización y recursos no revelan la existencia de datos de
  otra organización: se responde 404.

## Estado y offline

Solo las inspecciones `draft` admiten escritura. Al completar, confirmar o
rechazar, las respuestas permanecen legibles pero dejan de ser editables. Este
contrato **no** es compatible con la sincronización offline anterior ni con la
PWA inspeccionada; no se publica una nueva versión de OpenAPI (`2.3.0`).
