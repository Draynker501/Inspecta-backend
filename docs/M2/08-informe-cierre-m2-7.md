# Informe de cierre M2.7 — ejecución tenant

> **Fotografía de validación previa a la integración.** M2.7 se integró posteriormente mediante PR #34, commit `902ef0ba96f7121a8b0d4132488335ad14ae805f` (2026-11-12). Las limitaciones técnicas descritas siguen vigentes; la referencia histórica a que no estaba integrada fue sustituida por este estado. Ver `09-acta-cierre-m2.md` para el dictamen consolidado.

Dictamen: **APROBADA CON SALVEDADES** para la frontera y el alcance documentados. Evidencia de validación local; esta auditoría final no repitió las suites completas. No se ha probado un worker real ni existe productor de producción confiable aprobado. Este dictamen no equivale a validación de worker, aprobación de productor ni integración de M2.7.

M2.6 está integrada mediante PR #33, commit `7f0465702aba452dc6d0363ef00be567099f0cf4`; D-32 está vigente, implementada e integrada. Las referencias de rama/base en el informe histórico M2.6 son fotografías del momento de validación y no contradicen esa integración posterior.

## Frontera y alcance

`TenantExecution` exige organización cliente activa, actor activo con membresía activa y un `Permission` seleccionado por código confiable, evaluado mediante `User::hasPermission()`. El identificador del actor es un valor, no un callback. Un UUID identifica al actor pero **no lo autentica**; la frontera no es segura para payloads o productores no confiables. No hay productor de producción aprobado. Las policies y restricciones de alcance adicionales siguen siendo responsabilidad de la operación. Se rechaza la ejecución sin actor hasta aprobar una autoridad de sistema. Se aíslan contexto y caché, se restaura el contexto anidado y se rechazan fibras y fronteras HTTP o bypass externo. No se reemplaza el gestor Auth. Auditoría conserva su contrato general fuera de la frontera; dentro comprueba la organización del auditable y exceptúa usuarios globales.

T20 y T21 se ejercitan mediante invocación directa de un job de prueba; T22 mediante comando de prueba. No se demuestra despacho, serialización, reintentos de cola ni aislamiento de un worker real. Se incluyen pruebas de auditoría anidada, revocación, permisos cambiados y limpieza. El bootstrap de plataforma comprueba el tenant operadora y rechaza el rol de plataforma inactivo.

## Validaciones

- La prueba nueva de auditoría cruzada falló antes de la corrección porque se escribía el evento con organización incorrecta.
- Se prueban denegación sin escrituras en fixtures de job y comando, rechazo de callback de identidad y rollback explícito de escrituras y auditoría mediante `DB::transaction`. La frontera no ofrece transacción automática ni impide que código interno confiable ejecute efectos antes de llamarla; por ejemplo, el método que selecciona el permiso del comando se ejecuta antes de `run`.
- Resultados reportados en ejecuciones previas, no repetidos en la auditoría final: SQLite en memoria, 75 pruebas específicas aprobadas y 284 aserciones; suite completa de 469 pruebas, 460 aprobadas, 9 omitidas y 1443 aserciones.
- Resultados reportados en ejecuciones previas, no repetidos en la auditoría final: MariaDB 11.8 aislada, contenedor temporal eliminado al finalizar, puerto loopback dinámico y base dedicada terminada en `_test`; 75 pruebas específicas aprobadas y 284 aserciones; suite completa de 469 pruebas, 461 aprobadas, 8 omitidas y 1472 aserciones.
- Regresión SQLite reportada: 55 pruebas, 256 aserciones. Resultado previo; no repetido durante la auditoría final.
- Pint de archivos afectados y `git diff --check`: aprobados.
- `composer audit --locked --no-interaction`: sin avisos de vulnerabilidad.
- Sensibilidad en copia aislada e ignorada por Git: retirar el filtro de organización activa hizo fallar `test_organizacion_inactiva_se_rechaza_antes_de_consultar_o_autorizar_al_actor` por no producirse el rechazo de organización; restaurado el filtro, pasó con 8 aserciones. La prueba usa un actor con permiso y verifica que no se consulte al actor ni se ejecute la operación, que no haya nuevas escrituras y que se limpie el contexto.
- La mutación que retiró la comprobación explícita de membresía en `TenantExecution` sobrevivió: `User::hasPermission()` también consulta una membresía activa para el tenant antes de conceder el permiso. El único recorrido de `TenantExecution::run()` hacia la operación exige ambas comprobaciones; se conserva la comprobación explícita como defensa adicional. Esta evidencia no cubre otros posibles usos directos de `runAs()`.
- Tras añadir la prueba de organización inactiva, `TenantExecutionTest` pasó en SQLite: 22 pruebas y 95 aserciones. Las cifras de suites completas anteriores corresponden a ejecuciones previas a esa prueba, sin cambios posteriores de producción.

## Riesgos y pendientes

La procedencia confiable y la autenticación efectiva son responsabilidad del productor; el UUID no prueba identidad. Falta validar worker real, cola y serialización; la capacidad sin actor requiere decisión de sistema. Los resultados de suites son previos y no fueron repetidos en la auditoría final. **Al momento de este informe**, la integración de M2.7 no estaba afirmada; posteriormente se integró por PR #34, como se indica en la nota histórica al inicio. No se cambió OpenAPI ni se instalaron dependencias.
