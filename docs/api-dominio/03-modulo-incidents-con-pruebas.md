# Módulo de Incidentes, Acciones Correctivas y Evidencias

## Descripción

Se implementaron y validaron los endpoints REST correspondientes a la gestión de incidentes, acciones correctivas, historial de actualizaciones y evidencias de INSPECTA.

El módulo permite registrar incidentes de seguridad e higiene, asignar acciones correctivas, controlar su avance y adjuntar evidencias relacionadas con las inspecciones.

## Funcionalidades implementadas

### 1. Incidentes
- Registro y consulta de incidentes.
- Listado paginado de incidentes.
- Actualización de información y estado.
- Validación de datos y aislamiento por organización.

### 2. Acciones correctivas
- Creación de acciones correctivas asociadas a incidentes.
- Asignación de responsables y prioridades.
- Consulta, listado y actualización de acciones.
- Validación de usuarios pertenecientes a la organización.

### 3. Historial de acciones correctivas
- Registro de actualizaciones y comentarios.
- Transiciones secuenciales: `pending → in_progress → completed → verified`.
- Registro automático de la fecha de finalización.
- Historial en orden cronológico ascendente.
- Uso de UUID v7 para nuevos registros de actualizaciones.

### 4. Evidencias
- Carga de imágenes y documentos PDF.
- Asociación de evidencias con inspecciones, incidentes y respuestas.
- Consulta y listado paginado.
- Descarga y visualización de archivos.
- Almacenamiento privado.
- Validación de formato, tamaño y pertenencia a la organización.
- Respuestas HTTP 404 para archivos inexistentes.

## Pruebas automatizadas

Se incorporaron pruebas Feature con PHPUnit para:

- `IncidentTest`
- `CorrectiveActionTest`
- `CorrectiveActionUpdateTest`
- `EvidenceTest`

Se verificaron operaciones exitosas, validaciones, transiciones de estado, aislamiento entre organizaciones, acceso a archivos y orden cronológico del historial.

**Resultado:** 32 pruebas aprobadas en el módulo.

## Consideraciones técnicas

- Backend desarrollado con Laravel y API REST.
- Persistencia mediante Eloquent.
- Operaciones transaccionales para actualizar acciones correctivas.
- Control de acceso multi-organización mediante `TenantContext`.
- Almacenamiento privado de evidencias.
- Pruebas de archivos mediante `Storage::fake()`.
- Pruebas temporales mediante `travelTo()` para evitar resultados intermitentes.