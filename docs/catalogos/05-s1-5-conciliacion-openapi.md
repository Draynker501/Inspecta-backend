# S1.5 — Conciliación contractual de plantas

CAT-TEC-09, aprobada por el responsable el 12 de noviembre de 2026, conserva temporalmente `include_hierarchy` como parámetro heredado ignorado y deprecado; la PWA inspeccionada depende de áreas/líneas que GET no devuelve. El panel no se ha inspeccionado y no se acredita compatibilidad. No se implementa jerarquía.

OpenAPI describe la operación POST local mediante esquemas específicos de entrada y respuesta, sin alterar `PlantInput`, que también es referenciado por una operación futura. La versión declarada sigue siendo 2.3.0: no implica que estos cambios estén publicados ni que sean compatibles. El antecedente D-16 distingue cambios aditivos de rupturas; la elección de versión y estrategia requiere aprobación tras revisar consumidores y clientes generados. Mantener el parámetro conserva la forma de solicitud pero no su promesa funcional anterior; la restricción de entrada y el DTO de creación también pueden afectar clientes generados del contrato previo.

Antes de publicar: verificar el panel, acordar transición con la PWA y consumidores, aprobar versión y validar la especificación y correspondencia de rutas. Antes de desplegar: comprobar la colación y datos del destino y ejecutar regresión MariaDB posterior a S1.2-C/S1.4 en un entorno nuevamente autorizado y aislado. Concurrencia HTTP no está probada.
