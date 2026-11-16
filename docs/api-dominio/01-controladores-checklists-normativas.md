# INSPECTA — M2: Controladores de checklists y normativas

**Fecha:** 11 de noviembre de 2026  
**Módulo:** API REST — Catálogos de inspección y cumplimiento normativo  
**Estado:** Implementación local probada manualmente; pendiente de integración, revisión de código y pruebas automatizadas en el repositorio compartido.  
**Base tecnológica:** Laravel, Eloquent ORM, MySQL/MariaDB, Sanctum y arquitectura multi-tenant.  
**Referencia de contrato:** `openapi.yaml` (versión 2.1.0 consultada en `master`; algunas ampliaciones locales se documentan abajo).

## 1. Resumen ejecutivo

Se desarrollaron los endpoints de dominio necesarios para administrar checklists, sus versiones, secciones y preguntas, además del catálogo de normas, requisitos normativos y vínculos entre preguntas y requisitos. Se añadió un endpoint para descargar el árbol completo de la versión publicada de un checklist, pensado para el consumo de la PWA y su posterior almacenamiento offline.

El trabajo se realizó sobre la implementación local del backend; **no se considera fusionado ni cubierto por la suite de pruebas del repositorio compartido** hasta que se integre y valide expresamente.

## 2. Alcance funcional

| Controlador | Responsabilidad |
|---|---|
| `ChecklistController` | Consultar y administrar checklists; entregar su versión publicada con estructura completa. |
| `ChecklistVersionController` | Gestionar versiones y su ciclo de vida `draft → published → archived`. |
| `ChecklistSectionController` | Administrar secciones y su orden dentro de versiones editables. |
| `QuestionController` | Administrar preguntas, tipos de respuesta, obligatoriedad, evidencia y opciones de selección. |
| `NormController` | Administrar normas y su estado de activación. |
| `NormRequirementController` | Administrar requisitos asociados a una norma. |
| `QuestionNormRequirementController` | Vincular y desvincular requisitos normativos con preguntas. |

### 2.1 Ciclo de vida del checklist

Una versión `draft` permite editar su estructura; una versión `published` es la versión disponible para consulta y ejecución de inspecciones; las versiones `archived` se conservan como historial. La publicación incorpora validaciones estructurales y archiva la versión previamente publicada según la implementación local.

### 2.2 Preguntas

Se admiten los tipos `boolean`, `text`, `number`, `multiple_choice`, `signature` y `date`. Para `multiple_choice`, las opciones se almacenan como JSON y contienen pares `value`/`label`; se valida la presencia de al menos dos opciones y la unicidad de sus valores. Cada pregunta puede indicar si es obligatoria y si admite evidencia.

### 2.3 Normativas

Las normas agrupan requisitos normativos identificados por código, sección y descripción. Una pregunta puede asociarse con varios requisitos, y un requisito puede asociarse con varias preguntas mediante `question_norm_requirement`.

## 3. Endpoints relevantes

La siguiente tabla resume las operaciones desarrolladas o revisadas en la implementación local. Las rutas exactas de los controladores ya existentes deben cotejarse con el archivo `routes/api.php` local antes de integrar.

| Recurso | Operaciones principales |
|---|---|
| Checklists | Listado, creación, detalle, actualización, cambio de estado y `GET /api/checklists/{checklistId}/active-version`. |
| Versiones | Consulta, creación y transición de estado de versiones. |
| Secciones | Consulta, creación y edición de secciones de una versión. |
| Preguntas | Consulta, creación, edición y cambio de estado de preguntas. |
| Normas | Consulta, creación, edición y cambio de estado de normas. |
| Requisitos | `GET /api/norms/{normId}/requirements`, `POST /api/norms/{normId}/requirements`, `GET /api/requirements/{id}`, `PUT /api/requirements/{id}`. |
| Vínculos normativos | `GET /api/questions/{questionId}/norm-requirements`, `POST /api/questions/{questionId}/norm-requirements`, `DELETE /api/questions/{questionId}/norm-requirements/{linkId}`. |

## 4. Reglas de negocio y consistencia

1. **Aislamiento organizacional:** los modelos de dominio usan el contexto tenant y sus scopes. Las solicitudes autenticadas de dominio requieren la organización activa (`X-Organization-Id`).
2. **Protección de versiones:** los cambios de estructura y de vínculos normativos se permiten únicamente en versiones `draft`.
3. **Concurrencia:** las operaciones sensibles utilizan `DB::transaction()` y `lockForUpdate()` sobre la versión para coordinar modificaciones y publicación.
4. **Validación de publicación:** se revisa la estructura mínima del checklist y la validez de las opciones de preguntas de selección múltiple.
5. **Duplicados:** la tabla pivote tiene una restricción única sobre `(question_id, norm_requirement_id)` y el controlador valida vínculos existentes.
6. **Integridad de referencias:** se comprueba la existencia y pertenencia organizacional de los recursos relacionados.

## 5. Decisiones técnicas particulares

### 5.1 Nombre de la tabla pivote

El modelo `QuestionNormRequirement` declara explícitamente:

```php
protected $table = 'question_norm_requirement';
```

Esto evita que Eloquent intente utilizar el plural automático `question_norm_requirements`. No fue necesario renombrar la tabla ni modificar migraciones.

### 5.2 Identificador de vinculación (`link_id`)

En el listado de requisitos vinculados se expone `link_id` como primer campo de cada requisito; el `id` siguiente corresponde al requisito normativo. La respuesta de creación también expone `link_id` en vez de `id`, para facilitar el uso del endpoint DELETE. La relación `Question::normRequirements()` incluye `withPivot('id')`.

**Contrato:** estos cambios deben reflejarse en los esquemas `NormRequirement` y `QuestionNormRequirement` de OpenAPI; la versión consultada del repositorio compartido no debe asumirse sincronizada con la implementación local.

## 6. Descarga del checklist publicado

**Endpoint:** `GET /api/checklists/{checklistId}/active-version`.

**Objetivo:** devolver en una sola petición la estructura necesaria para representar un checklist en la PWA.

**Jerarquía de respuesta:**

```text
Checklist
└── active_version (published)
    └── sections[]
        └── questions[] (activas)
            ├── options[] | null
            └── norm_requirements[]
                └── link_id + datos del requisito
```

**Implementación:** consulta del checklist dentro del tenant; selección de su versión `published`; carga anticipada (*eager loading*) de secciones, preguntas y requisitos; orden por `display_order` e `id`; omisión de preguntas inactivas; respuesta `404` cuando no existe una versión publicada.

**Validación manual:** se confirmó una respuesta `200` con una versión `v1.1` publicada, varias secciones, preguntas `boolean`, `text` y `multiple_choice`, opciones JSON y un requisito vinculado con `link_id`.

**Comportamiento observado:** el checklist de la prueba tenía `status: false` y aun así se devolvió su versión publicada. Esto es coherente con el filtro actual (estado de versión, no estado del checklist), pero debe definirse si la PWA podrá descargar checklists desactivados.

## 7. Integración con infraestructura M1.5 / M2

El repositorio compartido incorporó `TenantController`, clase base que declara el middleware `tenant.require` mediante `HasMiddleware`. Al integrar los controladores locales, deben extender `TenantController` y conservar las validaciones de organización y autorización correspondientes. También se añadió un formato uniforme para los errores de autorización `403` (`code: forbidden`).

**Nota:** extender `TenantController` no sustituye la comprobación de permisos o políticas por operación.

## 8. Pruebas y evidencia

**Realizadas manualmente:** creación y consulta de requisitos, vinculación y desvinculación, identificación de `link_id`, protección de versiones publicadas y descarga de árbol completo mediante Postman. La respuesta de ejemplo compartida confirma el árbol de `active-version`.

**Pendientes antes de integrar:** pruebas Feature automatizadas por endpoint, pruebas de acceso entre organizaciones, permisos por rol, publicación concurrente, duplicados, orden de preguntas, ausencia de versión publicada, contrato OpenAPI y regresión sobre SQLite/MariaDB.

## 9. Observaciones y pendientes

| Prioridad | Observación | Acción |
|---|---|---|
| Alta | Los controladores locales deben adoptar la clase base `TenantController`. | Revisar herencia, rutas y middleware al integrar. |
| Alta | `openapi.yaml` del repositorio compartido aún puede diferir de las respuestas locales (`link_id`, árbol con normativas). | Actualizar contrato y ejemplos. |
| Media | Se devolvió un checklist desactivado (`status: false`) con versión publicada. | Definir regla de descarga para la PWA. |
| Media | La sección de ejemplo `ex1` se devolvió sin preguntas. | Decidir si se permiten secciones vacías al publicar o si deben filtrarse. |
| Media | Dos preguntas de una misma sección tenían `display_order: 1`. | Definir si se aceptan empates o si el orden debe ser único. |
| Media | Los textos de requisitos normativos pueden cambiar después de publicar el checklist. | Definir estrategia de preservación histórica o versionado. |
| Media | Los métodos de dominio aún no están cubiertos por las cifras de pruebas del compañero. | Crear pruebas automatizadas y ejecutar CI tras la integración. |

## 10. Estado y siguientes pasos

**Completado localmente:** estructura de checklists, versiones, secciones, preguntas, normas, requisitos, vínculos normativos y consulta de versión publicada.

**Siguiente paso propuesto:** integración controlada con la rama de M2, actualización de OpenAPI y pruebas automatizadas. Después, avanzar con el módulo de inspecciones, respuestas y sincronización de la PWA.
