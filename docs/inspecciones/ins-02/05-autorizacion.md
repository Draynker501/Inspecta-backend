# INS-02 — autorización

## Contexto obligatorio

Todas las operaciones usan el grupo `api.tenant`: autenticación, organización
cliente activa, membresía activa y `X-Organization-Id` válido. El tenant de la
inspección y el del contexto deben coincidir; una inspección de otra
organización se trata como inexistente (404).

## Lectura

`InspectionPolicy::viewAnswers()` usa la misma matriz que la lectura de la
inspección:

| Condición | Requisito |
| --- | --- |
| Permiso base | `inspections.execute` o `inspections.manage` |
| Inspección propia | execute o manage |
| Inspección ajena | `inspections.manage` |
| Membresía | activa en la organización |
| Asignación | planta o área de la inspección |
| Tenant | mismo `organization_id` |

- `checklists.view` y `norms.view` **no** se exigen; el checklist es parte de
  la inspección, no del catálogo.
- `locations.view_all` no sustituye la asignación.
- No existe bypass de plataforma ni permiso nuevo.
- Una inspección invisible responde 404; una visible sin autorización, 403.

## Escritura y borrado

`InspectionPolicy::answer()` exige **todas** estas condiciones:

1. membresía activa;
2. asignación vigente a la planta o área;
3. `inspections.user_id` igual al usuario autenticado;
4. `inspections.execute`;
5. inspección en `draft`.

`inspections.manage` es solo lectura y **nunca** concede `PUT` ni `DELETE`.
Tampoco lo concede `locations.view_all`, la plataforma ni la pertenencia a la
organización por sí sola.

## Revalidación transaccional

Antes de escribir se hace una comprobación temprana para distinguir 403 de 404.
Después, dentro de la transacción y tras bloquear la inspección:

1. se vacía la caché de permisos del usuario;
2. se revalidan membresía, permisos, propiedad y asignación;
3. se revalida el estado `draft`;
4. se resuelve la pregunta dentro de la organización y la versión original.

Esto evita que una revocación concurrente observada después del bloqueo siga
produciendo una escritura. No se promete revocación inmediata frente a todos
los interleavings posibles; véase [concurrencia](07-concurrencia.md).

## Matriz de respuestas

| Situación | Estado |
| --- | --- |
| Inspección inexistente o de otra organización | 404 |
| Inspección visible y autorizada | 200 / 204 |
| Inspección visible, sin execute/propiedad/asignación | 403 |
| Inspección visible sin `manage` y ajena (lectura) | 404 |
| Pregunta inexistente o de otra versión | 404 |
| Pregunta inactiva | 422 `question` |
| Inspección no `draft` | 422 `inspection` |
| Cuerpo o valor inválido | 422 `answer`/`fields` |
| Respuesta con evidencias al borrar | 422 `answer` |

El orden de comprobaciones es estable: la visibilidad del recurso se resuelve
antes de validar la paginación o el cuerpo. Una inspección no visible responde
404 con independencia de los parámetros de consulta, de modo que la ruta de
denegación no sea distinguible por el código de respuesta.
