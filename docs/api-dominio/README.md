# INSPECTA — API de dominio

Esta carpeta contiene la documentación técnica del desarrollo de los controladores de dominio de INSPECTA, sus reglas de negocio y las pruebas automatizadas correspondientes.

El trabajo se desarrolla principalmente en la rama `api`, utilizando Laravel, Eloquent ORM, MySQL/MariaDB y PHPUnit.

## Documentación

| Documento | Descripción |
|---|---|
| [01 — Controladores de checklists y normativas](01-controladores-checklists-normativas.md) | Implementación de endpoints, versionado, preguntas, requisitos normativos y reglas de negocio. |
| [02 — Pruebas automatizadas de checklists](02-pruebas-automatizadas-checklists.md) | Validación de aislamiento organizacional, publicación de versiones y ordenamiento mediante 25 pruebas automatizadas. |

## Alcance

La documentación se concentra en las funcionalidades de negocio desarrolladas para la API REST, complementando la infraestructura de autenticación, autorización y contexto organizacional del proyecto.

Los documentos de esta carpeta no sustituyen las decisiones arquitectónicas generales registradas en `docs/M1.5`, `docs/M2` o `docs/catalogos`.

## Estado actual

- Controladores de checklists y normativas implementados en la rama `api`.
- Pruebas automatizadas de checklists: 25 aprobadas.
- Integración con `master`: pendiente de validación.
- Cobertura adicional de permisos, normativas y concurrencia: pendiente.

Los resultados documentados corresponden a las ejecuciones realizadas sobre la rama `api` y no deben interpretarse como resultados de la rama principal.
