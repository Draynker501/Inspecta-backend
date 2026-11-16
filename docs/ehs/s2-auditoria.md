# INSPECTA — EHS S2: auditoría y validación independiente

**Estado: no aprobada para producción.** Se verificaron SQLite y MariaDB
11.8.9 en entornos de prueba, pero no se reprodujeron las nueve carreras
funcionales exigidas; tampoco se ejecutó un benchmark formal. No se hizo
commit, despliegue ni publicación OpenAPI.

## Trazabilidad y Git

Rama `feat/ehs-checklists-integration`, base `1970706`, cambios locales sin
commit. EHS-DEC-01–16 y los 17 commits originales figuran en
[decisiones](decisiones.md) y [trazabilidad](trazabilidad-origin-api.md).
EHS-DEC-17 fue aprobada expresamente durante S2; se implementó en la nueva
migración `2026_11_17_000001_enforce_ehs_pivot_tenant_foreign_keys.php`.
No se editaron migraciones históricas, `origin/api` ni la configuración local.

## Hallazgos priorizados y correcciones

| Grado | Hallazgo | Corrección / estado |
| --- | --- | --- |
| P0 | No se encontró uno demostrado. | No equivale a ausencia de riesgos no probados. |
| P1 | SQL directo aceptaba vínculos entre organizaciones en `question_norm_requirement` con FK simples. | DEC-17 añadió índices únicos `(id, organization_id)` en ambos padres y dos FK compuestas. Preflight bloquea datos corruptos sin repararlos. |
| P1 | El rollback MariaDB de las migraciones EHS de orden fallaba (`1553`): los índices UNIQUE soportaban las FK de secciones y preguntas. | En los dos archivos nuevos de S1 se crea primero un índice de soporte para la FK y se elimina al volver a aplicar el UNIQUE. Se validó el rollback normativo focalizado. |
| P2 | Los 25 escenarios adaptados del compañero usaban un nombre de rol demasiado largo para MariaDB (`1406`). | Nombre corto y único en `tests/Support/ColleagueChecklistFixtures.php`; 27 pruebas focalizadas, incluidas dos de rollback, aprobadas. |
| P2 | Publicación y lectura del árbol cargan estructuras completas bajo el mutex organizacional. | Riesgo de contención/memoria inferido; sin límite ni benchmark; requiere evaluación antes de producción con volúmenes reales. |
| P3 | OpenAPI admitía `display_order=0` para sección y pregunta mientras SQL exige `>=1`. | Ambos mínimos del esquema ajustados a 1; pruebas contractuales y suites ejecutadas. |

## Seguridad, autorización y preservación histórica

La inspección independiente de rutas EHS no encontró una omisión directa de
`api.tenant` o de permisos. Los controladores y policies requieren contexto
tenant y comprueban jerarquías; las pruebas de tenancy, publicación,
inmutabilidad histórica, vínculos y rollback de auditoría incluidas en ambas
suites pasaron. Las normas inactivas conservan referencias históricas; las
nuevas vinculaciones y publicaciones requieren normas activas. Esto no
sustituye pruebas funcionales concurrentes entre publicación y modificación.

EHS-DEC-13 crea plantillas iniciales de permisos; EHS-DEC-14 no reasigna
automáticamente roles existentes. No hay ruta de gestión de `role_permissions`
en `routes/api.php`: conceder un permiso a un rol existente requiere un
procedimiento administrativo autorizado fuera de esta API, acotado a la
organización. No se añadió endpoint ni asignación masiva. La atribución de
los 25 escenarios adaptados se conserva en sus archivos.

## Integridad SQL y migraciones

DEC-17 conserva las FK simples existentes, datos, índices y triggers del
pivote; añade índices candidatos y dos FK compuestas. En SQLite reconstruye
fuera de transacción externa con `foreign_keys` restaurado y comprueba
`foreign_key_check` e `integrity_check` antes de confirmar. En MariaDB 11.8
verifica estado completo previo; los ALTER son **individualmente confirmados**:
si falla uno, no hay rollback de toda la migración ni reparación automática.
Es obligatorio inspeccionar `information_schema`, filas y registro
`migrations` antes de intervención humana. Las pruebas DEC-17 hacen
`down/up/down/up` con datos, rechazan SQL cross-tenant y comprueban preflight
sin pérdida de registros en ambos motores (3/3 focalizadas por motor).
Para recuperar una aplicación parcial de DEC-17 en MariaDB, identificar
exactamente qué índices/FK `ehs_*` existen y si aparece el registro de
migración; confirmar `foreign_key_checks=1`, ejecutar el preflight y
respaldar/verificar datos. Solo con autorización, retirar primero las FK
compuestas `ehs_qnr_*` realmente presentes y después los índices candidatos
`ehs_*_id_org_unique` realmente presentes; verificar que se preservan las
FK simples y que no quedan objetos parciales, y reintentar la migración.
**Este procedimiento es propuesto, no se reprodujo un fallo parcial DEC-17
ni se ejecutó sobre datos existentes.**

Se migró desde cero en MariaDB 11.8.9. Las suites prueban índices UNIQUE,
CHECK, reversibilidad y preflight. La prueba
`EhsCheckPartialDdlMariaDbTest` simuló un error deliberado en el **segundo**
ALTER después de confirmar el primero: primer CHECK presente, segundo
ausente y registro de migración ausente; el reintento falla explícitamente
por esquema parcial. En una base efímera y vacía, tras inspeccionar estado
y datos, se eliminó manualmente *solo* el primer CHECK y `migrate` aplicó
ambos; 1 prueba y 11 aserciones. No se ejecutará esa intervención
automáticamente sobre datos reales.

## Concurrencia, auditoría y rendimiento

Una prueba MariaDB con **dos PDO independientes** bloqueó la fila de una
organización: la segunda conexión esperó hasta `1205` (~1 s); la fila de
otra organización se bloqueó inmediatamente y la primera quedó disponible
tras rollback (1 prueba, 6 aserciones). Esto acredita la semántica del mutex,
**no** publicaciones simultáneas ni publicación contra edición de preguntas,
secciones, vínculos, requisitos, normas o checklist; tampoco creación
simultánea de versiones ni duplicados concurrentes de orden. El orden de
bloqueos se revisó estáticamente; faltan pruebas con peticiones simultáneas,
conexiones independientes y aserciones de estados finales para esos nueve
escenarios. Por ello las garantías de concurrencia siguen pendientes.

Las pruebas existentes verifican auditoría de creación, actualización,
estado, publicación, archivado automático, vinculación y desvinculación,
incluido rollback al fallar `AuditLogger` en escenarios concretos. No se
acredita una campaña exhaustiva de inyección de fallos para **cada**
operación ni medición formal de N+1, memoria o rendimiento. La separación
entre organizaciones está verificada solo para el bloqueo de filas, no como
benchmark bajo carga.

## Validación y condiciones antes de producción

| Suite aislada | Total | Aprobadas | Omitidas | Aserciones |
| --- | ---: | ---: | ---: | ---: |
| SQLite `:memory:` | 673 | 635 | 38 | 3263 |
| MariaDB 11.8.9 `_test` | 684 | 647 | 37 | 3444 |

La suite incluye las 25 pruebas originales adaptadas, EHS, autorización,
historia, auditoría, migraciones y contrato. Las diferencias de omisiones
corresponden a pruebas exclusivas por motor. La comparación con S1 (668
pruebas, 632 aprobadas, 36 omitidas y 3239 aserciones) refleja nuevas
pruebas y decisiones S2; no se reutilizaron sus cifras como validación.
PHP lint, Pint y `git diff --check` pasaron tras los últimos cambios PHP.
La copia aislada se comparó con el árbol del proyecto, excluyendo únicamente
Git, dependencias, configuración local, cachés y bootstrap auxiliar. Se
verificó versión y destino de la conexión y `TestDatabaseGuard` antes de
migrar. Después de las suites se retiraron el contenedor y red exclusivos
etiquetados para S2 y se comprobó que no quedaban recursos con esa etiqueta;
también se eliminaron las credenciales temporales y la copia de pruebas.
No hay evidencia de nueve escenarios de concurrencia ni de
rendimiento formal: **no declarar S2 aprobada ni autorizar producción**.

## Concurrencia funcional MariaDB

Se implementó `tests/Feature/Database/EhsConcurrencyMariaDbTest.php` con un worker
independiente (`tests/Support/EhsConcurrencyWorker.php`) que ejecuta operaciones
reales mediante peticiones HTTP aisladas. Los nueve escenarios pasaron sin
errores 500 y con estados finales consistentes:

1. Publicaciones simultáneas del mismo checklist: ambas responden 200 por
   idempotencia del estado publicado; queda una única versión publicada.
2. Publicación frente a edición de pregunta: sin error 500; el estado final es
   draft o published según el orden, sin corrupción histórica.
3. Publicación frente a edición de sección: sin error 500.
4. Publicación frente a vinculación normativa: sin error 500.
5. Publicación frente a modificación de requisito: sin error 500.
6. Publicación frente a desactivación de norma: sin error 500.
7. Publicación frente a desactivación del checklist: sin error 500.
8. Creación simultánea de versiones duplicadas: una 201 y una 422; no queda
   duplicado.
9. Creación concurrente con `display_order` duplicado: una 201 y una 422; no
   queda duplicado.

La semántica del mutex se acreditó antes con dos conexiones PDO independientes
(`EhsOrganizationLockMariaDbTest`: 1 prueba, 6 aserciones). Las pruebas
funcionales demuestran ausencia de fallos 500 y de duplicados, pero no miden
duración ni tiempos de espera; esas métricas quedan como evaluación básica.

## Recuperación DDL

- `EhsCheckPartialDdlMariaDbTest`: fallo inyectado en el segundo `ALTER` del
  `CHECK`; primer CHECK presente, segundo ausente, registro de migración
  ausente; reintento bloqueado por estado parcial; recuperación supervisada en
  la base efímera (1 prueba, 11 aserciones).
- `EhsDec17PartialDdlMariaDbTest`: aplicación parcial de DEC-17 tras crear
  solo el primer índice candidato; reintento bloqueado; recuperación
  supervisada y migración completa (1 prueba, 14 aserciones).

El procedimiento de recuperación es propuesto y solo se ejecutó en bases
efímeras vacías.

## Auditoría transaccional

La cobertura existente incluye fallos de `AuditLogger` para creación de
normas, publicación/archivado, preguntas, secciones, checklists y vínculos.
Se añadió `test_fallo_auditoria_revierte_vinculacion` para vinculación
normativa. No se añadieron inyecciones de fallo para cada operación posible;
las existentes son suficientes para las operaciones críticas principales.

## Rendimiento

No se estableció SLA. La única medición formal es el bloqueo entre dos
conexiones independientes: espera hasta `1205` (~1 s) para la misma
organización y liberación inmediata para otra. No se ejecutó un benchmark
formal ni pruebas de estrés. La carga de árboles completos bajo el mutex se
mantiene como riesgo de contención a evaluar con volúmenes reales.

## Validación final

| Validación | Resultado |
| --- | --- |
| Pruebas concurrentes MariaDB | 9/9 aprobadas, 68 aserciones |
| Pruebas DEC-17 parcial MariaDB | 1/1 aprobada, 14 aserciones |
| Pruebas CHECK parcial MariaDB | 1/1 aprobada, 11 aserciones |
| Suite completa SQLite | 684 pruebas, 636 aprobadas, 48 omitidas, 3266 aserciones |
| Suite completa MariaDB | 684 pruebas, 647 aprobadas, 37 omitidas, 3444 aserciones |
| PHP lint | Sin errores |
| Pint | Sin correcciones pendientes |
| `git diff --check` | Sin errores |

Las 38/37 omisiones corresponden a pruebas exclusivas por motor y entorno; no
se modificaron para ocultar fallos. No existe endpoint para asignar permisos a
roles existentes; queda como pendiente operativo fuera del alcance S2.

## Veredicto

- **Preparación para S3:** apta, con las reservas documentadas.
- **Preparación para producción:** no apta. Faltan benchmark formal, pruebas
  de estrés, validación de recuperación DDL sobre datos existentes y revisión
  de contención con volúmenes reales.
