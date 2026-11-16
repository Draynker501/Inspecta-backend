# INS-01 S2 — auditoría independiente y validación

**Veredicto: preparación técnica condicionada para S3; no aprobación de producción.**
La revisión independiente inicial identificó dos defectos concretos y ausencia de
pruebas concurrentes de INS-01. Se corrigieron y se repitieron pruebas en copia
aislada; la ejecución simultánea no controla determinísticamente todos los
órdenes posibles. No se publicaron cambios ni se modificaron consumidores.

## Hallazgos y correcciones

| Gravedad | Hallazgo | Corrección y comprobación |
| --- | --- | --- |
| Alta | El preflight MariaDB de DEC-16 validaba columnas de FK simples históricas, pero no reglas `ON UPDATE`/`ON DELETE`. | Comprueba esquema referenciado y reglas `CASCADE`/`RESTRICT` antes de DDL, también en `down`. Prueba con FK de planta alterada a `RESTRICT` verifica rechazo y ausencia de índices DEC-16. |
| Media | POST documentaba 422 para referencia de otro tenant, pero la API devuelve 404 sin revelar existencia. | OpenAPI 2.3.0 conserva 404 y elimina ejemplo cross-tenant de 422 para POST. |
| Media | La prueba de revocación no acreditaba sensibilidad frente a quitar la segunda revalidación. | Mutación **solo en copia aislada**: retirada de flush/Gate/asignación posterior al bloqueo produjo 201 en vez de 403; restauración produjo una prueba aprobada. No demuestra revocación simultánea entre procesos. |
| Media | Faltaban pruebas INS-01 de concurrencia funcional entre procesos. | Se añadieron escenarios específicos con worker HTTP y escritura de ubicación en proceso independiente. No hay API de desactivación de ubicaciones. |
| Baja | Pruebas DEC-16 exclusivamente SQLite fallaban al ejecutar la suite MariaDB. | Se omiten explícitamente fuera de SQLite, manteniendo validación en `:memory:`. |

## Autorización, historia, auditoría y contrato

`InspectionPolicy` exige organización cliente activa, membresía y permisos
independientes. `inspections.execute` permite crear propia; la consulta propia
requiere execute o manage, la ajena manage. Toda operación requiere asignación
de planta o área y `locations.view_all` no la reemplaza. El listado restringe
alcance antes de contar y paginar. La suite cubre áreas exclusivas, terceros,
aislamiento tenant, plataforma y revocación simulada dentro de la petición.

POST toma tenant y actor del contexto, genera UUIDv7, crea `draft` con fecha
del servidor y audita `inspection.created` en la misma transacción sin copiar
coordenadas ni observaciones a la auditoría. La prueba de fallo del logger
comprueba rollback. Las lecturas preservan las referencias a ubicaciones
inactivas o eliminadas lógicamente y versiones archivadas; el árbol histórico
del contenido queda fuera de INS-01. Los tres endpoints online concuerdan con
OpenAPI 2.3.0; el anterior POST offline y la PWA no son compatibles.

## Integridad SQL y DDL parcial

Las cuatro FK DEC-16 están probadas para SQLite y MariaDB 11.8.9 en esquemas
aislados: creación desde cero, datos relacionados, `up/down/up`, rama y tenant
adversariales, línea nula y FK entrantes. El preflight rechaza datos inválidos
sin repararlos. SQLite rechaza esquemas con vistas/triggers sin eliminarlos,
restaura `foreign_keys` y verifica integridad. En MariaDB los tres índices
padre se crean mediante DDL independientes y las cuatro FK en un único ALTER.
La prueba de colisión 1061 entre ALTER confirma bloqueo de reintento y
recuperación supervisada con inspección e incidencia conservadas; **no cubre
todas las fronteras de interrupción**. Antes de cualquier recuperación real,
detener escrituras, contrastar `information_schema` con `migrations` y respaldo
de datos, y ensayar un plan específico aprobado; nunca reparar automáticamente.
Detalles en [DEC-16 MariaDB](../dec-16-mariadb-s2.md).

## Concurrencia funcional MariaDB

`InspectionConcurrencyMariaDbTest` utiliza procesos independientes y barrera
de salida compartida; comprueba HTTP y estados finales en creación frente a
archivado, nueva publicación y desactivación del checklist, además de planta,
área y línea (estas últimas mediante SQL directo en otro proceso). También
prueba dos creaciones legítimas simultáneas y dos organizaciones independientes.
Si la creación gana se espera 201 y una sola auditoría; si el cambio de estado
gana se espera 422 y ninguna creación. Se verifica que los reintentos posteriores
a desactivar o archivar se rechazan. La barrera inicia ambos procesos pero
**no fuerza ambos órdenes** en cada escenario; sin protocolo común para
revocación de membresías/asignaciones no se acredita garantía inmediata.
La ausencia de estados corruptos o de respuestas 500 en estos casos no es
benchmark ni prueba exhaustiva de interleavings.

### Revisión focalizada S3

El auditor independiente confirmó que el POST documenta 404 sin ejemplo
cross-tenant en 422 y que `/plants` conserva su contrato. Revisó el preflight
histórico y la sensibilidad documentada de la prueba de revocación, sin
encontrar otro bloqueante demostrado. Señaló que una sola barrera de arranque
no ejercitaba necesariamente ambos resultados. Se añadieron 12 casos
ordenados (creación antes/después de archivar, publicar otra versión, desactivar
checklist, planta, área o línea) mediante dos procesos con señales sucesivas:
verifican respuestas exactas, filas y auditoría en ambos órdenes. En MariaDB
11.8.9 efímera, el conjunto focalizado de concurrencia aprobó 5 pruebas y
269 aserciones. Estos ensayos ordenados **no son solapamiento simultáneo**;
se conservan también los escenarios simultáneos con una barrera. La
desactivación de ubicaciones sigue siendo SQL directo en otro proceso y solo
demuestra el comportamiento ante ese escritor, no un endpoint inexistente ni
un protocolo global de revocación. No se promete ausencia de lecturas obsoletas
en otros interleavings no reproducidos.

La suite integral posterior S3 en el árbol ampliado registró SQLite 715
pruebas (658 aprobadas, 57 omitidas, 3609 aserciones) y MariaDB 11.8.9
715 (671 aprobadas, 44 omitidas, 3956 aserciones), sin fallos en las ejecuciones
finales. Una ejecución MariaDB previa agotó el tiempo asignado por la
herramienta y otra tuvo un fallo intermitente en la observación de espera
InnoDB de `ProductionLinesStoreConcurrencyMariaDbTest` (CAT); esa prueba
pasó focalizada (9 pruebas, 158 aserciones) y en la repetición integral.
No se modificó CAT para enmascarar el fallo; puede reaparecer en CI.

## Validaciones y entorno

| Suite final en copia aislada | Total | Aprobadas | Omitidas | Aserciones |
| --- | ---: | ---: | ---: | ---: |
| SQLite `:memory:` | 714 | 658 | 56 | 3609 |
| MariaDB 11.8.9 `inspecta_s2_test` | 714 | 670 | 44 | 3812 |

Sin fallos en ambas suites; pruebas contractuales incluidas. Las omisiones
provienen principalmente de escenarios específicos del otro motor y de
funcionalidades excluidas por su entorno; no se interpretan como éxitos de
MariaDB en SQLite ni viceversa. Pint `--test`, lint de PHP modificado,
parseo YAML de OpenAPI 2.3.0 y `git diff --check` sin errores.

Se utilizó una copia de código con dependencias existentes, SQLite `:memory:`
y contenedor MariaDB 11.8.9 exclusivamente etiquetado para S2, con
almacenamiento `tmpfs`, puerto aleatorio enlazado a `127.0.0.1`, base
`inspecta_s2_test`, entorno `testing` y guard de pruebas. La instancia usó
acceso root local sin contraseña **solo en este contenedor temporal**:
no extrapolar esta configuración a instalaciones persistentes. Se verificó
versión y destino antes de `migrate:fresh`. La prueba mutada en la copia falló
como se esperaba (201 frente a 403); tras restaurar el código pasó. Las cifras
anteriores de S1 no sustituyen los resultados S2.

## Riesgos residuales y S3

Permanecen fuera de alcance idempotencia persistente, sincronización offline,
revocación estricta de operaciones concurrentes, respuestas y lectura histórica
del árbol de checklist, administración de permisos existentes, compatibilidad
con consumidores, benchmark formal y estrés. No integrar como autorización
de producción. La integración técnica S3 requiere revisión del diff, evidencia
de limpieza de recursos efímeros, decisión del responsable y autorización
separada para commit, PR, merge, publicación o despliegue.
