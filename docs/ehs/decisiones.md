# Decisiones EHS — fuente y alcance

El responsable confirmó EHS-DEC-01–12 en la solicitud S1 del 15 de noviembre
de 2026. La solicitud agrupa algunas decisiones sin asignar una regla concreta
a cada identificador individual; para evitar atribuciones inventadas se
documentan aquí por los grupos expresamente indicados.

| Identificadores | Alcance aprobado en la solicitud |
| --- | --- |
| EHS-DEC-01–03 | Permisos independientes de checklist y normas; matriz de autorización, policies y tenant activo. |
| EHS-DEC-04–06 | Ciclo de checklist y versiones, inmutabilidad de publicados/archivados y validaciones de publicación. |
| EHS-DEC-07–08 | Normativas, estado, vinculaciones y preservación histórica tras publicación y archivado. |
| EHS-DEC-09 | Validación, integridad, auditoría transaccional y convenciones de escritura. |
| EHS-DEC-10 | Estrategia Git original, sustituida expresamente por EHS-DEC-16. |
| EHS-DEC-11 | Identificador incluido en la aprobación general EHS-DEC-01–12; la solicitud no identifica su regla de forma individual. |
| EHS-DEC-12 | Conciliación de operaciones EHS reales en OpenAPI 2.3.0, sin publicar versión contractual. |
| EHS-DEC-13 | Plantilla inicial de permisos por rol, detallada en [README](README.md#ehs-dec-13--permisos-iniciales-de-checklists). |
| EHS-DEC-14 | Preservación de permisos existentes y ausencia de asignación masiva, detallada en [README](README.md#ehs-dec-14--preservación-de-roles-existentes). |
| EHS-DEC-15 | Restricciones CHECK positivas nuevas, preflight y validación por motor, detalladas en [README](README.md#ehs-dec-15--órdenes-positivos-en-sql). |
| EHS-DEC-16 | Integración manual trazada, detallada en [README](README.md#ehs-dec-16--integración-manual-trazada). |
| EHS-DEC-17 | Aprobada durante S2: índices candidatos y FK compuestas `(question_id, organization_id)` y `(norm_requirement_id, organization_id)` para `question_norm_requirement`, preflight sin reparación y reversibilidad SQLite/MariaDB aisladas. Véase [informe S2](s2-auditoria.md). |
| EHS-DEC-18 | Diferir la publicación contractual y mantener OpenAPI en 2.3.0, según la solicitud INS-01 S1. |
| EHS-DEC-19 | Aceptar y diferir los riesgos residuales documentados, sin autorizar producción, según la solicitud INS-01 S1. |
| EHS-DEC-20 | Autorizar el merge del PR #42 ya efectuado en `c3f2dc8024f670dbb64badea0ad371272db76d1e`, según la solicitud INS-01 S1. |

Las decisiones son requisitos aprobados, no evidencia de implementación. La
[trazabilidad](trazabilidad-origin-api.md) y el [estado](README.md) distinguen
lo implementado, lo pendiente y las pruebas efectivamente ejecutadas.
