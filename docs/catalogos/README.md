# Catálogos organizacionales — decisiones y desarrollo

| Documento | Alcance |
|---|---|
| [`01-s0-diseno-post-plants.md`](01-s0-diseno-post-plants.md) | Registro CAT-DEC-01–07 y CAT-TEC-01–07 aprobadas, contrato de CAT-01, discrepancia OpenAPI, verificaciones pendientes, criterios, matriz y plan S1–S3. |
| [`02-s1-2-b-integridad.md`](02-s1-2-b-integridad.md) | Evidencia histórica de integridad SQLite/MariaDB S1.2-B. |
| [`03-s1-2-c-entrada-auditoria.md`](03-s1-2-c-entrada-auditoria.md) | Entrada estricta y evolución de auditoría. |
| [`04-s1-3-compatibilidad-consumidores.md`](04-s1-3-compatibilidad-consumidores.md) | Dependencia PWA y panel pendiente. |
| [`05-s1-5-conciliacion-openapi.md`](05-s1-5-conciliacion-openapi.md) | Conciliación, versión y gates de publicación y despliegue. |
| [`06-s1-7-ajustes-contractuales.md`](06-s1-7-ajustes-contractuales.md) | Resolución de hallazgos P2 y límites del esquema de entrada. |
| [`07-cat-02-s0-areas-integridad.md`](07-cat-02-s0-areas-integridad.md) | Decisiones CAT-02, auditoría HTTP, validación SQLite/MariaDB y concurrencia. |
| [`08-cat-02-s0-1-diseno-fk-area-planta.md`](08-cat-02-s0-1-diseno-fk-area-planta.md) | Diseño y aprobación de la FK compuesta Área → Planta. |
| [`09-cat-02-s1-1-integridad-referencial.md`](09-cat-02-s1-1-integridad-referencial.md) | Investigación S1.1-A/A2, prototipo SQLite, decisión de sustitución y límites. |
| [`10-cat-03-s0-lineas-produccion.md`](10-cat-03-s0-lineas-produccion.md) | Decisiones aprobadas y diseño de CAT-03 para alta de líneas de producción. |
S0 fue integrado y sus informes conservan la fotografía de ese cierre. La implementación de POST `/api/plants` está completada localmente; las regresiones técnicas completas SQLite S1.8-B y MariaDB S1.9 pasaron (evidencia y límites en el documento S1.7). OpenAPI está conciliado localmente, pero su versión contractual sigue pendiente de aprobación. Esto permite preparar una revisión técnica mediante PR; no implica autorización para iniciar operaciones en producción. El panel no se ha verificado, la transición PWA no tiene verificación funcional y la colación/datos del destino siguen pendientes. CAT-01 no está publicada ni desplegada.

CAT-02 S1.3 está cerrada técnicamente: el POST de alta, su contrato y la integridad pasaron validación en SQLite `:memory:` y MariaDB 11.8.9 aislada, incluidos cinco escenarios concurrentes. La configuración de CI se adecuó para MariaDB 11.8.9 y está pendiente de ejecución efectiva en Actions. No se habilitaron otras operaciones de áreas ni líneas. La compatibilidad de consumidores y la publicación contractual quedan pendientes; la aprobación de sustituir la FK histórica no autoriza DDL en bases persistentes ni acredita despliegue. Ver documentos 07 y 09 para evidencia, límites y condiciones.
