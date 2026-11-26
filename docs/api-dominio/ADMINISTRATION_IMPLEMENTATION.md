# Implementación del módulo de Administración — INSPECTA

## 1. Objetivo

Implementar un módulo administrativo que permita gestionar usuarios, membresías, roles, permisos y ubicaciones dentro de cada organización, respetando la arquitectura multi-tenancy y los mecanismos de autorización establecidos en INSPECTA.

## 2. Implementación del backend

### Administración de usuarios y membresías

- Consulta paginada de usuarios pertenecientes a la organización.
- Registro de usuarios y creación de membresías.
- Asignación y actualización de roles.
- Revocación y reactivación de membresías.
- Asignación de plantas y áreas a usuarios.
- Validación del alcance administrativo y restricciones de delegación.

### Administración de roles y permisos

- Consulta, creación y edición de roles personalizados.
- Consulta del catálogo de permisos.
- Actualización de permisos asociados a roles.
- Activación y desactivación de roles.
- Protección de roles del sistema.
- Prevención de escalamiento de privilegios.

### Administración de ubicaciones

- Consulta jerárquica de plantas, áreas y líneas de producción.
- Creación y actualización de ubicaciones.
- Activación y desactivación de registros.
- Validación de disponibilidad jerárquica.
- Restricciones de acceso según las ubicaciones asignadas al usuario.
- Conservación de registros históricos.

### Seguridad

- Aislamiento de información entre organizaciones.
- Autorización mediante roles y permisos.
- Validación de capacidades administrativas.
- Protección contra modificaciones no autorizadas.
- Registro de operaciones administrativas mediante auditoría.
- Uso de transacciones para operaciones críticas.
- Validación de solicitudes y manejo de errores HTTP.

## 3. Implementación del frontend

Se desarrolló e integró el módulo de Administración de INSPECTA Web mediante cuatro pestañas:

### Usuarios

- Listado paginado y filtros de búsqueda.
- Registro de usuarios.
- Consulta de información personal y membresías.
- Asignación de roles.
- Administración del estado de membresías.
- Asignación de plantas y áreas.

### Roles y permisos

- Listado de roles mediante tarjetas.
- Creación y edición de roles personalizados.
- Consulta y modificación de permisos.
- Catálogo de permisos agrupados por funcionalidad.
- Activación y desactivación de roles.
- Protección visual de roles del sistema y roles no delegables.

### Ubicaciones

- Visualización jerárquica mediante acordeones.
- Administración de plantas, áreas y líneas de producción.
- Indicadores de estado y disponibilidad.
- Formularios de creación y edición.
- Confirmación de cambios de estado.
- Actualización silenciosa del listado.

### Organización

- Consulta de nombre, código, tipo y estado de la organización seleccionada.
- Visualización de información en modo de solo lectura.
- Actualización manual de información.

## 4. Centralización de capacidades

Se implementó el hook `useAdministrationCapabilities` para centralizar las comprobaciones de autorización del módulo administrativo.

Las capacidades obtenidas desde el backend determinan:

- Visibilidad de pestañas.
- Disponibilidad de indicadores.
- Acceso a operaciones administrativas.
- Visibilidad de botones y formularios.
- Ejecución de solicitudes a la API.
- Restricciones de consulta y modificación.

Las comprobaciones del frontend complementan las autorizaciones del backend, sin sustituirlas.

## 5. Indicadores administrativos

Se integraron indicadores correspondientes a:

- Usuarios.
- Membresías activas.
- Roles.
- Áreas.

Los indicadores se consultan según las capacidades del usuario y se actualizan automáticamente después de operaciones administrativas exitosas.

## 6. Integración y pruebas

Durante el desarrollo se realizaron validaciones relacionadas con:

- Autorización de operaciones administrativas.
- Aislamiento multi-tenancy.
- Restricciones de delegación.
- Asignación de roles y ubicaciones.
- Disponibilidad jerárquica.
- Protección de rutas del frontend.
- Visibilidad de módulos según permisos.
- Actualización de indicadores.
- Contrato OpenAPI.
- Compilación de la plataforma web.

Se comprobó funcionalmente el acceso con diferentes roles y permisos, incluyendo usuarios con acceso administrativo completo, parcial y denegado.

## 7. Decisiones técnicas

- Se mantuvo la estructura existente de la base de datos.
- No se incorporaron permisos adicionales para editar la organización.
- La información de la organización permanece en modo de solo lectura.
- Se reutilizó el contexto de organización para gestionar capacidades.
- Las restricciones definitivas de autorización permanecen en el backend.
- La actualización de indicadores se realiza mediante callbacks entre componentes.

## 8. Resultado

El módulo de Administración queda integrado funcionalmente con la API REST de INSPECTA, permitiendo gestionar los recursos administrativos de cada organización conforme a los permisos y alcances autorizados.

La administración del perfil personal y el cambio de contraseña se abordarán de manera independiente en el módulo Mi perfil.