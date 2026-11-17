# INS-03 S2.2 — contención entre respuestas y finalización

**Base:** rama `feat/ins-03-inspection-completion`, commit `6c115486fead9a1922c55aa3768ffb20b33d3796` más cambios S1 sin commit. Alcance autorizado: concurrencia INS-03 en MariaDB 11.8.9 efímera aislada; no suites completas, producción, publicación ni S3. Decisiones INS-03-DEC-01–08 en [S0](s0-diseno.md), hallazgos previos en [S2.1](s2-1-auditoria.md).

## Preflight y método

- Se utilizó imagen **local** `mariadb:11.8.9`, contenedor propio efímero con puerto TCP dinámico ligado a `127.0.0.1`, base exclusiva `ins03_s22_test` y contraseña temporal fuera del repositorio. Se comprobó `SELECT VERSION()` = `11.8.9-MariaDB-ubu2404` y el nombre de BD antes de cualquier migración. `TestDatabaseGuard` exige `APP_ENV=testing`, host local, nombre terminado en `_test` y permiso explícito `TESTING_DB_ALLOW_EXTERNAL=true`; el test verifica además nombre exacto y versión antes de `migrate:fresh`.
- La copia aislada del árbol está en `/tmp/opencode/ins03-s22-copy-*`, sin `.env*`, Git ni dependencias duplicadas: enlace a `vendor` preexistente del checkout INS-02, cuyo `composer.lock` coincide con este árbol. El bootstrap temporal antepone el autoload `App`/`Tests` de la copia. `APP_BASE_PATH` apunta a ella; preflight PHP comprobó base efectiva, autoload de `InspectionCompletion`, host, puerto, base y versión efectivos. PHPUnit se ejecutó `--no-configuration` con parámetros explícitos de la base exclusiva y STDIN cerrado, para no heredar SQLite `:memory:` de `phpunit.xml`. No se instalaron dependencias.
- `tests/Feature/Database/InspectionCompletionConcurrencyMariaDbTest.php` crea datos sobre migraciones reales en esa base y arranca `tests/Support/InspectionCompletionConcurrencyWorker.php` mediante procesos HTTP separados. El primer proceso retiene dentro de su transacción el bloqueo de la fila `inspections` tras `SELECT … FOR UPDATE`; escribe un marcador. Se inicia un segundo proceso sin liberar al primero. Se comprueba `SHOW ENGINE INNODB STATUS` con `TRX HAS BEEN WAITING` **y** en `information_schema.PROCESSLIST` el `SELECT … inspections … FOR UPDATE` del contendiente; antes de soltar la barrera la inspección sigue `draft`. Los cierres independientes se verifican con la segunda inspección `completed` **antes** de liberar la primera. Se comprueban respuestas HTTP, estados/valores persistidos y eventos de auditoría.

## Resultados medidos por el ejecutor

| Escenario dirigido | Respuestas HTTP y estado persistido | Auditoría |
| --- | --- | --- |
| PUT antes de finalizar, respuesta obligatoria inicialmente ausente | PUT 200; cierre 200; `completed`, valor `new` | 1 `inspection.answer_created`, 1 `inspection.completed` |
| Finalizar antes de PUT | Cierre 200; PUT 422 `inspection`; valor `initial` inalterado | 1 `inspection.completed`, 0 `inspection.answer_updated` |
| DELETE antes de finalizar | DELETE 204; cierre 422 `answers`; `draft`, `completed_at` nulo y respuesta ausente | 1 `inspection.answer_deleted`, 0 `inspection.completed` |
| Finalizar antes de DELETE | Cierre 200; DELETE 422 `inspection`; valor `initial` inalterado | 1 `inspection.completed`, 0 `inspection.answer_deleted` |
| Dos cierres sobre la misma inspección | Primero 200; segundo 422 `inspection` | Exactamente 1 `inspection.completed` |
| Cierres de inspecciones distintas | Ambos 200; ambas `completed`; el segundo avanza mientras el primero aún retiene su bloqueo | Exactamente 2 `inspection.completed` |

**Suite dirigida:** 6 pruebas aprobadas, **118 aserciones**, MariaDB 11.8.9 aislada, ejecución aproximadamente 53 s. Regresión INS-02 directamente relacionada (`test_put_y_delete_solapados_comparten_bloqueo_y_no_dejan_estado_incoherente`): 1 prueba aprobada, 19 aserciones. Pint `--test` sobre los dos PHP nuevos: aprobado; `git diff --check`: sin errores. No se ejecutaron suites completas.

**Sensibilidad:** en la **copia aislada solamente**, se retiró la única llamada a `lockForUpdate()` de `InspectionCompletion.php`. `test_complete_first_then_put_rejects_write` falló (1 prueba, 0 aprobadas, 8 aserciones) porque **no se observó el primer bloqueo**; tras restaurar desde el worktree, el mismo test pasó (1 prueba, 20 aserciones). Los SHA-256 del servicio en worktree y copia restaurada coincidieron. Nunca se mutó el árbol de trabajo.

**Intentos previos no válidos:** durante la construcción del test hubo errores de helper PHP, serialización del `{}` y detección de espera vía `INNODB_LOCK_WAITS` que no detectaba una espera real. Se corrigieron y se usó estado InnoDB + consulta observable en `PROCESSLIST`; los intentos fallidos no se cuentan como validación aprobada.

## Hallazgo de revocación y límites

S2.1 identificó por lectura una **inferencia**: si se revoca membresía o asignación después de la visibilidad HTTP pero antes del bloqueo, la revalidación de `InspectionPolicy::answer()` puede dar 403 sin recalcular el 404 de visibilidad. Estos escenarios S2.2 **no inducen revocación concurrente** y no confirman ni refutan ese interleaving. Se mantiene como salvedad baja dentro del límite ya documentado de revocación no inmediata; no se propone protocolo nuevo ni se declara corrección. Los escenarios sí verifican el bloqueo y los dos órdenes de respuesta/cierre sobre la misma fila, no todas las carreras posibles con EHS, escritores SQL externos, deadlocks 1213 ni espera agotada 1205. No hay garantía de idempotencia ante pérdida de respuesta de red.

## Revisión independiente y cierre de S2.2

El `inspecta-auditor` emitió **APROBADA CON SALVEDADES** para S2.2 tras leer código y pruebas; no ejecutó pruebas, mutaciones ni modificó archivos. Los resultados 6/118, la observación InnoDB/PROCESSLIST y la mutación/restauración son **mediciones del ejecutor**, no verificaciones ejecutadas por el auditor. Consideró suficiente la evidencia para contención observada y sensibilidad al bloqueo en esta base aislada, no para todas las carreras posibles.

| Severidad | Salvedad y alcance |
| --- | --- |
| Baja | `InspectionCompletionConcurrencyMariaDbTest.php:183-199`: estado InnoDB y `PROCESSLIST` no identifican inequívocamente la fila ni enlazan espera con ID/proceso; otra transacción en la misma base podría producir atribución errónea. La base efímera exclusiva acota el riesgo. |
| Baja | `InspectionCompletionConcurrencyWorker.php:31-44`: la barrera se crea tras el `SELECT … FOR UPDATE`. Al quitar el bloqueo, la mutación falla por falta de barrera; por sí sola **no** demuestra una escritura indebida. Los resultados finales de ambos órdenes y auditorías son evidencia funcional adicional. Plazos de 8/20 s pueden generar falsos fallos en entornos lentos. |
| Baja | La diferencia 403/404 tras revocación entre visibilidad y revalidación continúa **inferida**, no reproducida. No hay evidencia de finalización no autorizada; requiere caso dirigido o aceptación explícita del límite. |

La coordinación EHS, los errores 1205/1213 y la idempotencia de red siguen diferidos; no se proponen reintentos ni nuevos protocolos. **No hay corrección bloqueante para la garantía ensayada de S2.2.** Se detiene antes de S3: no ejecutar suites completas sin solicitud expresa.

**Recursos propios:** el contenedor efímero se detuvo (`--rm`), se retiraron la copia aislada y el archivo de conexión temporal de `/tmp/opencode`; los marcadores de prueba propios fueron retirados. No se tocaron recursos preexistentes.
