# INS-02 — concurrencia

## Estrategia

Cada `PUT` y `DELETE` abre una transacción y bloquea la fila de la inspección
con `lockForUpdate()`. La inspección actúa como mutex natural de sus respuestas:
serializa escrituras de la misma inspección sin introducir un bloqueo
organizacional global.

Dentro de la transacción se revalida:

- membresía activa y permisos sin caché obsoleta;
- propiedad y asignación;
- estado `draft`;
- pertenencia de la pregunta a la versión original.

La comprobación temprana fuera de la transacción solo sirve para distinguir
403/404; la decisión efectiva es la revalidación posterior al bloqueo.

## Semántica

- **Last-write-wins**: dos `PUT` concurrentes sobre la misma pregunta dejan una
  sola fila con el valor de la última escritura confirmada. No hay control
  optimista, ETag ni `If-Match`.
- **Sin duplicados**: el unique `(inspection_id, question_id)` y la búsqueda
  dentro del bloqueo impiden dos filas para la misma pregunta.
- **PUT y DELETE concurrentes**: el resultado es una respuesta con el valor
  actualizado o ninguna respuesta, nunca duplicados. La auditoría refleja las
  operaciones efectivamente confirmadas.
- **Sin idempotencia de red**: dos peticiones idénticas pueden producir dos
  respuestas 200; la segunda no genera auditoría si no cambió nada.
- **Sin mutex organizacional**: inspecciones distintas pueden escribirse en
  paralelo.
- **Evidencias**: si una evidencia se inserta entre la comprobación y el
  `DELETE`, la FK RESTRICT del motor impide el borrado y la API lo traduce a
  422; no se elimina la evidencia. La traducción exige una violación de clave
  foránea identificada como de `evidences`; cualquier otro error de integridad
  se propaga y no se etiqueta como bloqueo por evidencias.
- **Errores transitorios**: un interbloqueo (1213) o una espera de bloqueo
  agotada (1205) no se reintentan ni se traducen a 422; se propagan como 500
  (decisión INS-02-DEC-20). El cliente puede reintentar la operación completa.

## Límites

No se garantiza:

- revocación inmediata de permisos o asignaciones modificados por otro proceso
  en un interleaving no reproducido;
- ausencia de lecturas obsoletas fuera de la transacción de escritura;
- recuperación de una caída a mitad de la migración;
- comportamiento de consumidores offline.

Las pruebas de concurrencia usan procesos independientes y barreras de salida.
Verifican los estados finales y la ausencia de duplicados, pero no agotan todos
los órdenes posibles ni constituyen un benchmark.
