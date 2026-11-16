# INS-02 — riesgos residuales

| Riesgo | Impacto | Estado |
| --- | --- | --- |
| Migración sobre datos reales | El preflight rechaza datos incoherentes y no repara; un entorno con datos históricos inválidos requiere análisis y recuperación supervisada. | Abierto, bloquea despliegue. |
| Estado parcial de migración | SQLite revierte la reconstrucción y MariaDB valida antes/después; la conmutación de FK es un único `ALTER TABLE` atómico. Aun así no se ensayan todas las fronteras de interrupción. | Abierto; requiere plan aprobado. |
| Revocación concurrente | La revalidación tras el bloqueo reduce la ventana, pero no se garantiza revocación inmediata en todos los interleavings. | Abierto, aceptado para el corte. |
| Idempotencia de red | Dos peticiones idénticas pueden repetir un `PUT`; no hay clave de idempotencia. | Fuera de alcance. |
| Sincronización offline | El contrato no es compatible con la PWA ni con el POST offline anterior. | Fuera de alcance; no habilitar consumidores. |
| Transiciones de estado | No existen endpoints para completar, confirmar, rechazar o reabrir. Las respuestas quedan de solo lectura al cambiar de estado por otros medios. | Fuera de alcance. |
| Firmas | `signature` se conserva como lectura histórica, pero no puede escribirse. | Fuera de alcance. |
| Evidencias | No se crean ni eliminan desde este corte; solo se impide borrar una respuesta con evidencias. | Fuera de alcance. |
| Límites de bytes | Se validan en PHP con `strlen`; una base con `utf8mb4` y bytes inválidos podría comportarse distinto. | Aceptado con validación de entrada. |
| Texto solo con espacios | `text` rechazaba la cadena vacía pero admitía una cadena formada únicamente por espacios porque el recorte está deshabilitado en esta ruta. | **Resuelto en S3**: se rechaza sin recortar el valor (INS-02-DEC-22). |
| Recuperación DDL parcial | MariaDB no permite agrupar toda la migración en una transacción, aunque el DDL atómico revierte un `ALTER TABLE` fallido completo. La migración detecta un estado parcial y se niega a continuar, pero no repara: la recuperación es manual y supervisada. Las fronteras ensayadas son representativas, no exhaustivas. | Abierto, documentado en [s2-auditoria.md](s2-auditoria.md) y [s3-consolidacion.md](s3-consolidacion.md). |
| Contrato OpenAPI | Las cuatro operaciones quedan implementadas en 2.3.0; el resto sigue `planned`. No se publica ni se regenera un cliente. | Requiere autorización separada. |
| Suite MariaDB | Depende de un contenedor efímero 11.8.9; los resultados no se extrapolan a otras versiones. | Aceptado en este corte. |
| Producción | No se autoriza despliegue, migración ni publicación. | Bloqueado por decisión del responsable. |

## Acciones que requieren autorización

- Ejecutar la migración en un entorno persistente o producción.
- Publicar OpenAPI o regenerar clientes.
- Modificar consumidores, PWA o panel.
- Commit, push, PR, merge, release o despliegue.
- Instalar dependencias o Laravel Boost.

La limpieza de recursos efímeros creados en esta sesión no requiere
autorización adicional; cualquier otro recurso externo sí.
