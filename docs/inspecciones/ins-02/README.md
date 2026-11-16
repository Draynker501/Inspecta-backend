# INS-02 — respuestas de inspección y checklist histórico

Este corte (S1) implementa la lectura del checklist congelado en una inspección
y el ciclo de vida de sus respuestas editables. No publica contrato, no
modifica consumidores y no autoriza producción.

## Alcance implementado

| Operación | Estado |
| --- | --- |
| `GET /api/inspections/{inspectionId}/checklist` | Implementada |
| `GET /api/inspections/{inspectionId}/answers` | Implementada |
| `PUT /api/inspections/{inspectionId}/answers/{questionId}` | Implementada |
| `DELETE /api/inspections/{inspectionId}/answers/{questionId}` | Implementada |

Quedan fuera de alcance: transiciones de estado, finalización, firmas,
sincronización offline, adjuntos, reapertura, idempotencia de red y cualquier
cambio en consumidores o en la PWA.

## Documentos

- [Diseño](01-diseno.md)
- [Decisiones INS-02-DEC-01–22](02-decisiones.md)
- [Contrato HTTP](03-contrato-http.md)
- [Integridad y migración](04-integridad.md)
- [Autorización](05-autorizacion.md)
- [Validación](06-validacion.md)
- [Concurrencia](07-concurrencia.md)
- [Matriz de pruebas](08-matriz-pruebas.md)
- [Riesgos residuales](09-riesgos-residuales.md)
- [Auditoría independiente S2](s2-auditoria.md)
- [Consolidación y revisión focalizada S3](s3-consolidacion.md)

## Relación con INS-01

INS-02 conserva las reglas de INS-01 para contexto, membresía, permisos,
asignaciones y tenant. Añade la lectura histórica del árbol de checklist
(INS-01-DEC-14) y las respuestas, sin conceder permisos de catálogo EHS ni
ampliar la superficie de rutas. Las decisiones de continuidad están registradas
como INS-01-DEC-18–20 en el [README de inspecciones](../README.md).
