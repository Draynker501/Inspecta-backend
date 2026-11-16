# Trazabilidad de `origin/api`

Registro inicial de EHS-DEC-16. Los 17 commits son de Diego Castaneda; no se
han creado commits integrados. «Parcial» no significa que la funcionalidad
esté validada ni completa. Los estados se actualizarán al cierre.

| Commit | Contenido original y archivos | Reutilización y pendientes |
| --- | --- | --- |
| `e57aaf7` | CRUD checklist, rutas, `composer.lock` | Parcial: lectura y creación adaptadas con permisos y auditoría; actualización y estado pendientes. Se preserva el lock de master, no se copia el lock ajeno. |
| `d292fed` | CRUD versiones y rutas | Listado, detalle, creación, edición draft y transición adaptados con permisos, locks y auditoría. |
| `6532e23` | CRUD secciones y rutas | Listado, detalle, creación y edición adaptados con autorización y tenant. |
| `958f0b6` | CRUD preguntas, rutas y versión | Listado, detalle, creación y edición adaptados con autorización y tenant. |
| `0d97d3b` | `options` en preguntas y migración | Parcial: columna, cast y validación adaptados; queda validar íntegramente migraciones y ciclo de vida. |
| `e98da29` | Validación de publicación | Adaptada: validaciones de estructura, opciones y normas; concurrencia MariaDB pendiente. |
| `8d0ab11` | Bloqueo de edición de secciones publicadas/archivadas | Creación y edición restringidas a drafts nunca publicados. |
| `5748bd9` | CRUD normas, secciones y rutas | Lecturas y escrituras adaptadas, con estado explícito y protección histórica. |
| `9d71f54` | CRUD requisitos y rutas | Lecturas y escrituras adaptadas con protección histórica. |
| `e26aac3` | Vinculaciones, modelos y rutas | Vínculos y DTO contextual adaptados; FK compuestas cross-tenant pendientes de propuesta y aprobación. |
| `aba4de2` | Consulta de versión activa | Adaptada con lectura contextual mediante `checklists.view`. |
| `9f11aaa` | Documentación original de controladores | Conservada en la documentación final reorganizada por `6e830db`; no sobrescribir documentación de master. |
| `6dc9429` | Publicación, orden y dos migraciones UNIQUE | Migraciones incorporadas y reversibilidad SQLite con relaciones probada; MariaDB pendiente. |
| `36f856d` | Refactor `TenantController` | Los controladores adaptados usan la arquitectura de master; tenant por sí solo no autoriza operaciones. |
| `585b6d4` | Correcciones de orden y concurrencia | Adaptadas con mutex organizacional y locks; concurrencia MariaDB pendiente. |
| `807140e` | 25 pruebas de tenancy, publicación y orden | Originales ejecutadas: 25 fallos de fixture 403; 25 escenarios adaptados a permisos: 25 aprobados. |
| `6e830db` | Organización documental `docs/api-dominio` | Archivos originales conservados en `docs/api-dominio/`; no se declara completa la implementación descrita allí. |

No se descartan reglas de negocio por conveniencia: los métodos de la rama
original sin autorización, auditoría o aislamiento suficientes se mantienen
pendientes de adaptación en vez de activarse inseguros. Las rutas de master se
preservan. La atribución definitiva en commits locales queda pendiente.
