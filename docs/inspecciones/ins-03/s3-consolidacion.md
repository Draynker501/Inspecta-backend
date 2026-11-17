# INS-03 S3 — consolidación y validación final

**Estado:** validación local reportada por el desarrollador; publicación e integración pendientes. Rama `feat/ins-03-inspection-completion`, base `6c115486fead9a1922c55aa3768ffb20b33d3796`. El Draft PR no implica aprobación para producción, publicación de OpenAPI ni despliegue.

## Alcance

Se expone `POST /api/inspections/{inspectionId}/complete` para la transición `draft → completed` conforme a INS-03-DEC-01–08 en [S0](s0-diseno.md), reutilizando la validación de respuestas, policy, transacción y auditoría. El contrato sigue en OpenAPI 2.3.0; la evaluación del supervisor permanece `planned`. Firmas, evidencias, confirmación, rechazo, reapertura y offline siguen fuera del corte.

## Validación integral reportada

| Entorno | Pruebas | Aprobadas | Omitidas | Aserciones | Salida |
| --- | ---: | ---: | ---: | ---: | ---: |
| SQLite `:memory:` | 795 | 702 | 93 | 4259 | 0 |
| MariaDB 11.8.9 efímera | 795 | 737 | 58 | 5138 | 0 |

En MariaDB se ejecutaron las seis pruebas nuevas de concurrencia, sin omisiones, con 112 aserciones en ejecución dirigida. El desarrollador informó Pint, PHP lint de 13 archivos afectados y `git diff --check` aprobados. Las suites se ejecutaron en copia aislada con `APP_BASE_PATH`, autoload y conexión comprobados, utilizando dependencias ya instaladas sin instalación nueva.

La copia se sincronizó desde el worktree completo mediante `rsync`, excluyendo Git, `vendor`, `.env*` y caché PHPUnit; la comparación posterior de `rsync` solo indicó metadatos del directorio raíz. **Limitación:** esa comparación utilizó tamaño y fecha, no hash criptográfico de todos los archivos; tampoco se verificó por hash todo `vendor`. El autoload de `InspectionCompletion` se verificó hacia la copia. La copia, contenedor, volumen y logs propios se retiraron tras las ejecuciones.

### Omisiones reportadas

El desarrollador extrajo los motivos de los XML finales antes de retirarlos; el siguiente desglose es evidencia reportada, no verificable nuevamente desde esos artefactos:

- **SQLite (93):** 29 «solo MariaDB efímera», 22 «solo MariaDB aislada», 14 «requiere MariaDB efímera», 12 exclusivas MariaDB, 6 contención InnoDB, 1 HTTP exclusiva MariaDB; 9 con verificación equivalente SQLite (3 `CompositePivotForeignKey`, 3 `RolePermissionSchema`, 2 `NormsMigrationReversibility`, 1 `OrganizationUniqueness`).
- **MariaDB (58):** 20 específicas SQLite, 13 exclusivas SQLite `:memory:`, 6 completitud con tablas mínimas SQLite, 4 reconstrucción SQLite, 2 solo SQLite en memoria, 1 instrumentación de locks SQLite, 1 migración solo SQLite en memoria, 1 disparador de fallo SQLite, 1 DDL transaccional SQLite y 1 verificación específica SQLite; 8 con equivalente MariaDB (3 `RolePermissionSchema`, 2 `NormsMigrationReversibility`, 2 `CompositePivotForeignKey`, 1 `OrganizationUniqueness`).

**Historial de ejecuciones:** antes del resultado final, SQLite presentó un error de descubrimiento de paquetes en la copia que se corrigió regenerando el manifiesto únicamente allí; MariaDB tuvo dos intentos interrumpidos por timeout (900 s y 300 s) y una ejecución completa con un fallo de aserción que dependía de las comillas SQL de SQLite. Se corrigió esa aserción para ambos motores y se repitieron las dos suites. Los intentos interrumpidos o fallidos no son éxitos.

## Auditoría y riesgos residuales

Las revisiones independientes de [S2.1](s2-1-auditoria.md) y [S2.2](s2-2-concurrencia.md) quedaron **aprobadas con salvedades**. MariaDB observó contención y los órdenes de escritura/finalización; la mutación sin bloqueo, solo en copia aislada, fue detectada por ausencia de la barrera. La atribución de la espera no identifica inequívocamente fila y proceso, y la mutación no demuestra por sí sola una escritura indebida.

La posible diferencia 403/404 ante revocación concurrente permanece inferida y sin reproducción; no se garantiza revocación inmediata en todos los interleavings. Quedan diferidos la coordinación con EHS o SQL externo, los errores transitorios 1205/1213 y la idempotencia ante pérdida de respuesta. La compatibilidad con consumidores PWA/offline y el despliegue no quedan validados.

## Integración

Omisiones, commit, publicación de rama, Draft PR y estado de CI: pendientes de verificación.
