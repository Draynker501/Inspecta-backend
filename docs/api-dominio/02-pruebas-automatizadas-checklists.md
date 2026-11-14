# INSPECTA — M2: Informe de pruebas automatizadas de checklists

**Fecha:** 13 de noviembre de 2026  
**Módulo:** API REST — Checklists, versiones, secciones y preguntas  
**Rama de desarrollo:** `api`  
**Estado:** Pruebas específicas aprobadas; regresión general con incidencias pendientes.  
**Tecnologías:** Laravel, PHPUnit, Eloquent ORM, SQLite en memoria y Sanctum.

## 1. Resumen ejecutivo

Se incorporaron 25 pruebas automatizadas de integración HTTP (*Feature Tests*) para validar el funcionamiento de los checklists de INSPECTA.

Las pruebas se concentran en tres aspectos fundamentales:

- **Aislamiento organizacional:** garantizar que los usuarios únicamente puedan consultar y modificar los checklists pertenecientes a su organización.
- **Publicación de versiones:** verificar las reglas de transición entre los estados `draft`, `published` y `archived`.
- **Ordenamiento:** comprobar la asignación automática y la unicidad de `display_order` en secciones y preguntas.

Las 25 pruebas finalizaron satisfactoriamente en SQLite en memoria. Posteriormente se ejecutó la suite completa del proyecto, obteniendo 354 pruebas aprobadas, 9 fallidas y 6 omitidas.

Los fallos de la suite general corresponden a pruebas de OpenAPI, reversibilidad de migraciones y verificaciones estáticas de arquitectura. Ninguno corresponde a las 25 pruebas nuevas.

## 2. Objetivo y alcance

El objetivo de esta implementación es detectar regresiones en las reglas de negocio de los checklists y verificar que los endpoints respeten el contexto organizacional.

Las pruebas ejercitan las rutas HTTP reales de Laravel, incluyendo autenticación, middleware, validaciones, controladores y persistencia en la base de datos.

**Archivos implementados:**

| Archivo | Responsabilidad | Pruebas |
|---|---|---:|
| `ChecklistTenantIsolationTest.php` | Aislamiento organizacional | 5 |
| `ChecklistPublicationTest.php` | Versionado y publicación | 9 |
| `ChecklistDisplayOrderTest.php` | Ordenamiento y duplicados | 11 |
| **Total** | | **25** |

Los archivos se encuentran en `tests/Feature/Checklists/`.

Adicionalmente, se incorporó `tests/Support/ChecklistFixtures.php` para reutilizar la preparación de datos y las solicitudes HTTP.

## 3. Metodología de pruebas

Se utilizó PHPUnit con las herramientas de pruebas integradas en Laravel.

Cada escenario prepara sus propios datos mediante `RefreshDatabase`, crea los recursos necesarios, ejecuta una solicitud HTTP y verifica el resultado esperado.

Las comprobaciones incluyen:

- Códigos de respuesta HTTP (`200`, `201`, `404` y `422`).
- Contenido de las respuestas JSON.
- Validaciones de campos y reglas de negocio.
- Persistencia y estado de los registros mediante `assertDatabaseHas`.
- Ausencia de modificaciones no autorizadas.

Las pruebas utilizan `TenantFixtures` para preparar organizaciones, usuarios y autenticación, y `ChecklistFixtures` para crear checklists, versiones, secciones y preguntas.

La ejecución se realizó con SQLite `:memory:`, evitando modificar la base de datos utilizada durante el desarrollo.

## 4. Matriz de pruebas implementadas

### 4.1 Aislamiento organizacional

**Archivo:** `ChecklistTenantIsolationTest.php`

| ID | Escenario | Resultado esperado |
|---|---|---|
| CT-01 | Crear un checklist con una organización activa | El registro pertenece a la organización seleccionada |
| CT-02 | Consultar un checklist de otra organización | HTTP 404 |
| CT-03 | Listar checklists de una organización | No aparecen registros de otras organizaciones |
| CT-04 | Modificar un checklist ajeno | HTTP 404 y datos originales conservados |
| CT-05 | Publicar una versión perteneciente a otra organización | HTTP 404 y versión permanece en `draft` |

**Resultado:** 5 pruebas aprobadas.

Estas verificaciones confirman que los endpoints evaluados respetan el aislamiento organizacional y evitan exponer o modificar recursos pertenecientes a otros clientes.

### 4.2 Versiones y reglas de publicación

**Archivo:** `ChecklistPublicationTest.php`

| ID | Escenario | Resultado esperado |
|---|---|---|
| CP-01 | Crear una versión nueva | Estado inicial `draft` |
| CP-02 | Publicar una versión sin secciones | HTTP 422; permanece en `draft` |
| CP-03 | Publicar una versión con una sección vacía | HTTP 422 |
| CP-04 | Publicar una versión con únicamente preguntas inactivas | HTTP 422 |
| CP-05 | Publicar una versión de un checklist desactivado | HTTP 422 |
| CP-06 | Publicar una versión válida | Estado `published` y disponible mediante `active-version` |
| CP-07 | Modificar una versión publicada | HTTP 422; datos conservados |
| CP-08 | Volver a publicar una versión archivada | HTTP 422 |
| CP-09 | Publicar una nueva versión cuando existe otra publicada | La anterior pasa a `archived` |

**Resultado:** 9 pruebas aprobadas.

Las pruebas comprueban el ciclo de vida de las versiones y las condiciones mínimas para su publicación.

También verifican que las operaciones rechazadas no modifiquen indebidamente el estado de las versiones.

### 4.3 Ordenamiento de secciones y preguntas

**Archivo:** `ChecklistDisplayOrderTest.php`

| ID | Escenario | Resultado esperado |
|---|---|---|
| CO-01 | Crear la primera sección sin orden explícito | `display_order = 1` |
| CO-02 | Crear una segunda sección sin orden explícito | `display_order = 2` |
| CO-03 | Crear una sección con orden duplicado | HTTP 422 |
| CO-04 | Actualizar una sección a un orden ocupado | HTTP 422 |
| CO-05 | Crear la primera pregunta | `display_order = 1` |
| CO-06 | Crear una segunda pregunta | `display_order = 2` |
| CO-07 | Crear una pregunta con orden duplicado | HTTP 422 |
| CO-08 | Actualizar una pregunta a un orden ocupado | HTTP 422 |
| CO-09 | Repetir un orden en preguntas de distintas secciones | Operación permitida |
| CO-10 | Crear una pregunta automática después de un orden explícito mayor | Se utiliza el máximo existente más uno |
| CO-11 | Asignar `display_order = 0` | HTTP 422 para secciones y preguntas |

**Resultado:** 11 pruebas aprobadas.

Estas pruebas validan la asignación automática del orden y la prevención de duplicados dentro de una misma sección o versión.

También confirman que los órdenes pueden repetirse entre grupos independientes, respetando el alcance de las restricciones de unicidad.

## 5. Evidencia de ejecución

### 5.1 Pruebas específicas

Comando utilizado:

```bash
php artisan test tests/Feature/Checklists
```

**Resultado reportado:** 25 pruebas aprobadas, sin fallos.

La ejecución individual de las tres clases y su posterior ejecución dentro de la suite completa confirmaron resultados satisfactorios.

### 5.2 Regresión general

Comando utilizado:

```bash
php artisan test
```

| Indicador | Resultado |
|---|---:|
| Pruebas aprobadas | 354 |
| Pruebas fallidas | 9 |
| Pruebas omitidas | 6 |
| Total | 369 |
| Aserciones reportadas | 1215 |
| Duración | 37.18 segundos |

**Resultado general:** suite con incidencias pendientes.

Las 25 pruebas nuevas de checklists aprobaron durante la ejecución general.

### 5.3 Análisis de incidencias

| Categoría | Fallos | Observación |
|---|---:|---|
| OpenAPI | 5 | Clase `Symfony\Component\Yaml\Yaml` no disponible en el entorno de ejecución |
| Migraciones de normas | 2 | El rollback no produjo el esquema esperado; requiere revisar la selección de migraciones |
| Membresías | 1 | Posible incompatibilidad en la normalización de rutas de Windows |
| `withoutTenant()` | 1 | Posible incompatibilidad en la normalización de rutas de Windows |

Las seis pruebas omitidas corresponden a verificaciones específicas de MariaDB que no se ejecutan utilizando SQLite.

No se dispone de evidencia de una nueva ejecución completa después de corregir estas incidencias.

## 6. Cambios realizados y trazabilidad

La implementación de pruebas complementa el documento `docs/M2/02-controladores-checklists-normativas.md`.

Se incorporaron verificaciones para reglas de negocio desarrolladas previamente en:

- `ChecklistController`
- `ChecklistVersionController`
- `ChecklistSectionController`
- `QuestionController`

También se validaron las reglas de ordenamiento asociadas con las restricciones UNIQUE de las tablas `checklist_sections` y `questions`.

Los archivos de pruebas y sus funciones auxiliares se incorporaron a la rama `api` y se publicaron mediante Git.

**Estado de integración:** los cambios están disponibles en `api`; no se afirma su integración en `master`.

## 7. Limitaciones y pruebas pendientes

Aunque las 25 pruebas fueron aprobadas, su alcance no constituye una validación completa del módulo.

| Prioridad | Pendiente | Justificación |
|---|---|---|
| Alta | Pruebas concurrentes con MariaDB | Comprobar bloqueos y transacciones bajo solicitudes simultáneas |
| Alta | Pruebas de autorización por permisos y roles | Verificar que el aislamiento tenant no sustituya las policies |
| Media | Validación de preguntas `multiple_choice` | Comprobar opciones inválidas, duplicadas e insuficientes |
| Media | Protección completa de versiones publicadas | Probar modificaciones de secciones, preguntas y vínculos normativos |
| Media | Pruebas de normativas y requisitos | Ampliar la cobertura de los controladores normativos |
| Media | Contrato OpenAPI | Verificar correspondencia entre endpoints implementados y documentación |
| Media | Regresión completa sin fallos | Resolver las nueve incidencias reportadas y repetir la suite |

Las pruebas realizadas con SQLite no demuestran por sí solas el comportamiento concurrente de MariaDB ni el funcionamiento de la API en un entorno desplegado.

## 8. Conclusiones y estado

La incorporación de las 25 pruebas automatizadas permite verificar de manera reproducible tres aspectos esenciales del módulo de checklists: aislamiento organizacional, publicación de versiones y ordenamiento de secciones y preguntas.

Los resultados obtenidos confirman que los escenarios implementados funcionan correctamente en el entorno de pruebas SQLite.

**Dictamen técnico: APROBADO CON SALVEDADES para el alcance de las 25 pruebas específicas.**

La suite completa conserva nueve fallos ajenos a estas pruebas y seis omisiones correspondientes a verificaciones de MariaDB.

Se recomienda resolver las incidencias generales, ampliar la cobertura de autorización y normativas, y ejecutar las pruebas con MariaDB antes de considerar concluida la validación integral del módulo.

Este informe documenta la evidencia disponible al 13 de noviembre de 2026 y no constituye una aprobación de integración en `master` ni de despliegue en producción.
