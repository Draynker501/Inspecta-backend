# EHS S1 — Integración de checklists y normativas

## Preflight

El 15 de noviembre de 2026 se actualizaron las referencias de `origin` y se
verificaron los siguientes puntos de partida:

- `origin/master`: `1970706e856752c935f26b74266281d2b1849bea`.
- `origin/api`: `6e830db2c4f95fe7eabf25d1e002f2cc9b7984dc`.
- Base común: `0919383425407b05864d94bb71afa7b41cc6c7d4`.
- Diferencia: 59 commits exclusivos de master y 17 exclusivos de api.
- Rama local: `feat/ehs-checklists-integration`.

La integración debe ser selectiva; no implica fusionar la rama completa. La
trazabilidad completa y el cierre de S1 quedan pendientes. La solicitud del
responsable recoge las reglas aprobadas para EHS-DEC-01–12, pero no se encontró
un acta independiente con el texto de cada decisión.

## EHS-DEC-13 — Permisos iniciales de checklists

Aprobada por el responsable durante S1:

| Rol de la organización | Permisos iniciales |
| --- | --- |
| Administrador | `checklists.view`, `checklists.manage` |
| Inspector | `checklists.view` |
| Supervisor | `checklists.view` |
| Otros | Ninguno automáticamente |

El administrador de plataforma no obtiene acceso implícito a los recursos de
organizaciones cliente. La configuración debe respetar mínimo privilegio,
aislamiento por organización, independencia de permisos, denegación por
defecto, idempotencia y configuraciones personalizadas existentes. Esta
decisión describe el comportamiento aprobado; su implementación y
verificación HTTP están pendientes.

El aprovisionamiento implementado se limita a roles predeterminados nuevos.
No concede permisos a roles existentes ni al rol de plataforma.

## EHS-DEC-14 — Preservación de roles existentes

Aprobada por el responsable durante S1: aplicar EHS-DEC-13 únicamente al
crear por primera vez los roles predeterminados; preservar íntegramente los
permisos de los roles existentes; no conceder ni revocar permisos al repetir
los seeders. La asignación explícita se realiza mediante los mecanismos de
administración vigentes. La operación debe ser idempotente y estar cubierta
por pruebas. No se implementará asignación masiva en esta fase.

## EHS-DEC-15 — Órdenes positivos en SQL

El responsable autorizó implementar localmente restricciones
`CHECK (display_order >= 1)` para `checklist_sections` y `questions` mediante
nuevas migraciones, preservando índices, claves foráneas y restricciones
existentes. Se exige preflight sin reparación automática, pruebas SQLite en S1
y MariaDB en S2, y evaluación de reversibilidad en ambos motores. No se
utilizarán bases persistentes. La implementación SQLite se probó en memoria;
la variante MariaDB no se ha
ejecutado y debe validarse en S2.

## Hallazgos preliminares de auditoría

La inspección independiente de `origin/api` identificó rutas EHS bajo
`api.tenant` sin autorización explícita por permiso en sus controladores.
Estas rutas no deben incorporarse sin adaptar y probar sus controles. El
contrato de esa rama todavía marcaba operaciones como `planned` pese a tener
rutas implementadas.

La publicación bloquea el checklist, pero no se ha demostrado con pruebas
concurrentes MariaDB que las demás escrituras se coordinen con ella. También
hay relaciones de inspecciones y respuestas con claves foráneas simples en
el esquema inspeccionado que podrían admitir inconsistencias por SQL directo:
son un riesgo inferido, no una reproducción ejecutada, y las inspecciones
están fuera del alcance de implementación S1. Cualquier propuesta de
migración estructural requiere aprobación específica.

El pivote EHS `question_norm_requirement` conserva claves foráneas simples a
pregunta y requisito. La aplicación verifica tenant y jerarquía, pero SQL
directo no tiene una FK compuesta que obligue a ambos extremos a pertenecer a
la misma organización. Como propuesta para S2, evaluar índices únicos y FK
compuestas por `(id, organization_id)`, con preflight de inconsistencias y
plan reversible en ambos motores. No se ha aprobado ni implementado esa
migración; tampoco se ha demostrado la existencia de datos inconsistentes.

En la rama del compañero, `published_at` se asigna al publicar y no se limpia
al archivar. Es necesario comprobar los datos y las rutas de transición antes
de tratarlo como prueba inequívoca de publicación histórica.

## EHS-DEC-16 — Integración manual trazada

El responsable autorizó sustituir la estrategia de cherry-pick de EHS-DEC-10
por integración manual trazada, preservando el árbol de trabajo actual y los
cambios de master. Cada uno de los 17 commits originales debe registrarse con
sus cambios reutilizados, adaptados, descartados y pendientes, justificando
las exclusiones. Se conservarán referencias a los commits originales y
coautoría cuando corresponda. Los futuros commits locales se organizarán por
responsabilidad funcional; no se incluirá configuración local ni se
modificará el historial de `origin/api`. No se autoriza push, PR ni merge.

## Referencia CAT-05

El merge del PR #41 está en `1970706`. La evidencia de pruebas y los riesgos
heredados se conservan en
[`../catalogos/12-cat-05-s0-consulta-jerarquica.md`](../catalogos/12-cat-05-s0-consulta-jerarquica.md).
Entre ellos están la compatibilidad no verificada con consumidores, la ausencia
de benchmark formal, las diferencias de collation y la validación pendiente del
límite de nodos con consumidores reales. No se consideran resueltos por S1.

El responsable confirmó y aprobó las siguientes decisiones de cierre:

- CAT-05-DEC-18: diferir la publicación contractual y mantener la versión 2.3.0.
- CAT-05-DEC-19: aceptar los riesgos residuales documentados de compatibilidad,
  rendimiento y consistencia de lectura.
- CAT-05-DEC-20: ratificar la integración técnica del PR #41 mediante el
  merge `1970706e856752c935f26b74266281d2b1849bea`.

Aceptar los riesgos no equivale a resolverlos; la evidencia y el detalle se
mantienen en la documentación CAT-05 enlazada.

## Validación

La referencia de master documentada para SQLite es 587 pruebas, 551 aprobadas,
36 omitidas y 2435 aserciones. Para MariaDB es 587 pruebas, 559 aprobadas,
28 omitidas y 2631 aserciones. Estas cifras no son resultados de S1.

El worktree no incluye `vendor`. Para las pruebas se preparó una copia aislada
del código, con autoload de aplicación y pruebas dirigido a esa copia y un
enlace a dependencias existentes de CAT-05. Los `composer.lock` del worktree,
de la copia y de CAT-05 tienen el mismo SHA-256. No se han instalado
dependencias ni se han usado bases persistentes.

El desarrollador informó una ejecución en una copia temporal aislada con
autoload adaptado: 584 pruebas aprobadas, 36 omitidas, 2 fallidas y 1 error en
SQLite, antes de desactivar la ruta de transición de estado. Los fallos
reportados correspondían al estado contractual de esa ruta; el error detectó
un rollback SQLite fallido con una FK que referencia secciones. Este último
se reprodujo independientemente en SQLite en memoria: 3 pruebas aprobadas y
1 error. Los resultados deben repetirse y no constituyen el cierre S1.
MariaDB y concurrencia no se han ejecutado en S1.

## Trazabilidad parcial

Se adaptaron lectura y creación de checklists a partir de `e57aaf7`, y
listados de versiones, secciones y preguntas a partir de `d292fed`,
`6532e23` y `958f0b6`, respectivamente, con atribución conservada en código.
Estas seis operaciones están registradas en las rutas locales. No se ha creado
un commit de cherry-pick y queda pendiente la evaluación individual de los
demás commits.

Se añadió también la creación de versiones draft adaptada de `d292fed`.
Las tres migraciones de `origin/api` fueron incorporadas selectivamente:
agregan `options` y verifican datos existentes antes de crear los índices de
orden. Se añadió una migración para los CHECK aprobados en EHS-DEC-15.
En SQLite, la reconstrucción exige ausencia de transacción externa, desactiva
temporalmente las FK tras verificarlo, preserva columnas, índices y triggers,
comprueba la integridad y restaura `foreign_keys`. La prueba focalizada
comprueba datos relacionados, FK, índices y rechazo SQL de órdenes no
positivos. La rama MariaDB 11.8 está preparada pero **no probada**: sus dos
ALTER son independientes y un error del segundo puede dejar un estado parcial
que requiere intervención explícita. Su validación y recuperación quedan para S2.

La transición de publicación y archivado volvió a registrarse después de
incorporar validación de preguntas, normativas vinculadas y organización, y
un protocolo común de bloqueo para las escrituras EHS. Las pruebas de
publicación y correspondencia de rutas ejecutadas en una copia aislada
aprobaron 22 pruebas y 163 aserciones; la validación integral y la concurrencia
MariaDB siguen pendientes.

La validación SQLite aislada del código final completó 668 pruebas: 632
aprobadas, 36 omitidas y 3239 aserciones. Antes de ejecutar se comparó la
copia con el worktree, excluyendo únicamente Git, dependencias, configuración
local y bootstrap auxiliar. La referencia de master era 587 pruebas, 551
aprobadas, 36 omitidas y 2435 aserciones. Los resultados MariaDB y de
concurrencia real no se han ejecutado ni deben inferirse de SQLite.

Validaciones adicionales sobre esa copia:

- Escenarios adaptados del compañero: 25 pruebas, 177 aserciones.
- Checklists y normativas: 74 pruebas, 594 aserciones.
- Migraciones SQLite: 7 pruebas, 61 aserciones.
- Tenancy: 252 pruebas, 245 aprobadas y 7 omitidas, 714 aserciones.
- Contrato y superficie: 13 pruebas, 115 aserciones.
- PHP lint, Pint y `git diff --check` sobre el worktree: sin errores.

La prueba de rollback de auditoría restaura sus handlers incluso al fallar;
la suite final no reportó pruebas riesgosas. Persisten como tareas S2 la
auditoría independiente integral, MariaDB 11.8, concurrencia real, el estado
parcial posible entre los dos ALTER de MariaDB y la evaluación de FK
compuestas. No se acredita compatibilidad con panel o PWA ni pruebas de carga.

En una copia aislada posterior de la implementación parcial, las pruebas de
`tests/Feature/Norms` y `tests/Feature/Checklists` dieron 44 pruebas: 39
aprobadas, 4 fallidas por el PATCH desactivado y 1 error por el rollback
SQLite de secciones referenciadas. PHPUnit también señaló una prueba riesgosa.
No es una validación final ni sustituye las pruebas completas requeridas.

Con la ruta real de publicación activada, las 25 pruebas del compañero
adaptadas para asignar permisos explícitos y cinco pruebas adicionales de
ciclo de vida aprobaron en SQLite aislado: 30 pruebas y 202 aserciones. Las
25 originales sin adaptar se conservan como evidencia separada de la
incompatibilidad de sus fixtures con el modelo de permisos vigente.

Las lecturas de normas y requisitos proceden de `5748bd9` y `9d71f54`. Una
prueba focalizada en copia aislada pasó con 2 pruebas y 18 aserciones. La
auditoría detectó que `norms.version` admite null y que debía invocarse la
policy sobre la norma concreta; se corrigieron el contrato y las llamadas a
la policy.

Las 25 pruebas originales de `807140e` se ejecutaron sin modificarlas en una
copia aislada SQLite: 25 fallaron con 403 al preparar un checklist, antes de
alcanzar sus escenarios, porque sus fixtures no asignan los permisos requeridos
por S1. Este resultado indica incompatibilidad con la autorización incorporada,
no valida ni invalida el comportamiento funcional posterior. La adaptación de
fixtures y repetición, conservando la cobertura y sin debilitar permisos, queda
pendiente.

La columna histórica permite valores de `answer_type` distintos de los seis
aceptados para nuevas preguntas. OpenAPI describe las lecturas como texto
sin restringir su enum para no ocultar posibles valores heredados; las
escrituras conservan la lista aprobada. No se han inspeccionado datos
persistentes ni convertido valores históricos.
