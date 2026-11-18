# INSPECTA — Integración de controladores de la API

## 1. Objetivo

Documentar la integración de los módulos de incidencias, acciones correctivas, actualizaciones de acciones correctivas y evidencias en la rama `integracion-api`, antes de publicar los cambios y evaluar su incorporación a `master`.

**Alcance:** este documento registra el trabajo y las pruebas comunicados durante la integración. No sustituye una revisión de diferencias, una ejecución completa de pruebas ni la validación del contrato OpenAPI.

## 2. Revisión de controladores entre ramas

Se compararon los nombres de archivos en `app/Http/Controllers` de las ramas remotas `api` e `integracion-api`.

- Todos los archivos de controladores encontrados en `api` también están presentes en `integracion-api`.
- `integracion-api` contiene además `AreaStoreController.php`, `ProductionLineStoreController.php` y `LocationHierarchyController.php`.
- **Conclusión limitada:** no se identificaron controladores pendientes de copiar desde `api`. La presencia del archivo no demuestra por sí sola que las rutas, reglas y operaciones sean equivalentes; esa comprobación requiere revisar implementación y pruebas.

## 3. Módulos trabajados

| Módulo | Trabajo realizado | Evidencia de pruebas |
|---|---|---|
| `IncidentController` | Integración de autorización, aislamiento por organización y auditoría | Pruebas reportadas como exitosas en el trabajo previo; confirmar resultado actual |
| `CorrectiveActionController` | Integración con permisos, contexto de organización y auditoría | 11 pruebas aprobadas, según ejecución reportada |
| `CorrectiveActionUpdateController` | Integración de actualizaciones y validaciones | 11 pruebas aprobadas, según ejecución reportada |
| `EvidenceController` | Integración de carga, consulta, listado, descarga y vista previa, con controles de acceso y auditoría | **14 de 14 pruebas aprobadas**, según última ejecución reportada |

### 3.1. Evidencias: corrección del nombre de tabla

Durante `EvidenceIntegrationTest`, SQLite reportó:

```text
SQLSTATE[HY000]: General error: 1 no such table: evidence
```

La tabla existente se denomina `evidences`, mientras que Eloquent intentaba acceder a `evidence`. Se agregó en `app/Models/Evidence.php`:

```php
protected $table = 'evidences';
```

Después de esta corrección se reportaron **14 pruebas aprobadas y 0 fallidas** en `EvidenceIntegrationTest`.

### 3.2. Alcance de las pruebas de evidencias

Las pruebas incluyen carga y auditoría, consulta de metadatos, listado y filtrado, descarga, vista previa de imágenes, validación de tipos y tamaño de archivo, rechazo de campos protegidos y coordenadas incompletas, autorización, aislamiento entre organizaciones, restricciones por ubicación, archivos faltantes y reversión ante fallos de auditoría.

Las pruebas de relaciones opcionales `inspection_answer_id` e `incident_id` no formaron parte de las 14 pruebas mencionadas y deben considerarse cobertura pendiente si se exige validación exhaustiva de ese comportamiento.

## 4. Estado remoto al momento de la revisión

La comparación remota `master...integracion-api` mostró:

- `integracion-api`: **1 commit por delante** de `master` y **0 por detrás**.
- **7 archivos** distintos en esa comparación.

**Importante:** esta comparación se realizó **antes del push solicitado** y no representa necesariamente el estado del directorio de trabajo local. Es indispensable publicar primero los commits locales y volver a comparar las ramas.

## 5. Verificaciones previas al pull request

- [ ] Confirmar la rama actual con `git branch --show-current`.
- [ ] Revisar `git status` y `git diff` para identificar exactamente los archivos pendientes.
- [ ] Comprobar que los controladores, políticas, rutas, modelo y pruebas de los módulos integrados estén incluidos en los commits.
- [ ] Ejecutar las pruebas específicas de los módulos integrados y registrar el resultado actual.
- [ ] Ejecutar la suite general y revisar todas las fallas y omisiones.
- [ ] Validar las rutas y los contratos de entrada/salida frente a OpenAPI, sin introducir cambios funcionales no acordados.
- [ ] Revisar las migraciones y sus métodos `up()`/`down()` en entornos de prueba apropiados.
- [ ] Revisar permisos, pertenencia a organización, acceso por ubicación y auditoría en los endpoints integrados.
- [ ] Verificar que no se incluyan secretos, archivos `.env`, logs o artefactos temporales.
- [ ] Hacer push y revisar el diff actualizado contra `master`.

**Antecedente:** `03-correccion_migraciones_sqlite.md` registró en una ejecución anterior de la suite general **686 pruebas aprobadas, 28 fallidas y 93 omitidas**. Este dato es histórico y no debe confundirse con una ejecución posterior a la integración. Antes del merge se debe obtener un resultado actualizado y determinar la causa de las fallas que permanezcan.
