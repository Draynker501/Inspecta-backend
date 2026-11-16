# INS-02 — validación

## Reglas por tipo

El tipo se lee de `questions.answer_type`; el cliente no puede declararlo.

| `answer_type` | Valor aceptado |
| --- | --- |
| `boolean` | `"true"` o `"false"` exactos, en minúsculas. |
| `text` | String no vacío y no compuesto exclusivamente por espacios en blanco (incluidos tabuladores, saltos de línea y separadores Unicode) de hasta 65535 bytes UTF-8. Se conservan espacios y saltos; no se recorta. |
| `number` | Decimal simple de hasta 64 caracteres: `-?\d+(\.\d+)?`. Se aceptan signo negativo y ceros iniciales; se rechazan `+`, exponente, coma decimal, `INF`, `NAN`, punto final y longitud mayor. |
| `date` | `YYYY-MM-DD` con fecha de calendario real (por ejemplo, `2024-02-29` sí; `2024-02-30` no). |
| `multiple_choice` | Exactamente uno de los `value` configurados en `options`. La comparación distingue mayúsculas. |
| `signature` | Rechazado con 422. Sigue visible como lectura histórica. |
| Otro/desconocido | Rechazado con 422. |

## Rechazos generales

- `answer` ausente, `null`, cadena vacía o arreglo.
- `answer` de tipo `text` compuesto exclusivamente por espacios en blanco.
- `answer` igual a `__NA__`.
- `observation` sin `answer`.
- Campos adicionales en el cuerpo, incluyendo identificadores, versión,
  sección, estado y timestamps.
- Cualquier parámetro de consulta en las escrituras: el cuerpo JSON es la
  única fuente del valor y la superficie de escritura es cerrada.
- Pregunta inactiva.
- Pregunta de otra organización o de otra versión.
- Inspección fuera de `draft`.

## Límites de bytes

- `answer`: máximo 65535 bytes UTF-8.
- `observation`: máximo 65535 bytes UTF-8.
- `number`: máximo 64 caracteres.

Los límites se comprueban con `strlen()`, no con longitud de caracteres, para
alinearse con columnas `TEXT` y con el contrato.

El recorte de espacios (`TrimStrings`) se desactiva **solo** para la escritura
de respuestas (`PUT api/inspections/*/answers/*`), de modo que el valor y la
observación se conservan tal como llegan. La excepción global por nombre de
campo se eliminó: el resto de la API conserva el recorte habitual y no se
altera ningún otro módulo. `ConvertEmptyStringsToNull` permanece activo, por lo
que una cadena vacía llega como `null` y falla `required`.

El rechazo de textos compuestos solo por espacios se hace sobre el valor ya
recibido, sin recortarlo: la comprobación determina si existe algún carácter
significativo, pero el valor almacenado es siempre el original. Por eso un
texto como `"  Texto  "` se guarda con sus espacios.

## Observación

Es opcional y nullable. Omitirla en `PUT` equivale a `null`, porque el verbo es
un reemplazo completo. No se crea una respuesta solo con observación.

## Mensajes y códigos

| Clave | Motivo |
| --- | --- |
| `answer` | Falta, tipo incorrecto, valor inválido, límite excedido o evidencia asociada. |
| `observation` | Tipo incorrecto o límite excedido. |
| `question` | Pregunta inactiva. |
| `inspection` | Inspección fuera de `draft`. |
| `fields` | Cuerpo no JSON, no objeto o con campos no permitidos. |

Todos los errores de validación responden 422 con
`code: validation_failed`.
