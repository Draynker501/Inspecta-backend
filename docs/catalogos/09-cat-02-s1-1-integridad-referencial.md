# CAT-02 — S1.1-A/A2: integridad referencial Área → Planta

**Estado:** investigación y prototipo aislado documentados a partir de los informes de sesión disponibles. No es una migración definitiva validada ni evidencia de despliegue. La revisión documental se reconstruyó el 2026-11-14; no se encontró copia recuperable del informe original.

## 1. S1.1-A: bloqueo inicial

La FK histórica `areas.plant_id → plants.id` se define en `2026_10_10_000002_create_areas_table.php` con `ON DELETE RESTRICT ON UPDATE CASCADE`; el nombre Laravel convencional es `areas_plant_id_foreign`. La propuesta CAT-02-INT-01 añade una FK compuesta con `RESTRICT` en ambas acciones.

Una prueba SQL reducida en SQLite `:memory:` constató que, al conservar ambas restricciones, una actualización del ID de la planta se rechazó con un orden de declaración y se aceptó con el orden inverso. En la variante que la aceptó, el valor de `plant_id` del área fue propagado por `CASCADE`. Esto bloqueó la afirmación de que conservar ambas FK garantizara `ON UPDATE RESTRICT`.

Una exploración posterior sobre el DDL generado por Laravel rechazó la actualización en el orden observado y después de una reconstrucción adicional. Ese resultado acotado no resolvía la incertidumbre del orden ni demostraba preservación integral, rollback u objetos dependientes. S1.1-A se detuvo sin crear una migración.

## 2. Alternativas evaluadas

1. **Conservar ambas FK.** Mantiene la conducta histórica `CASCADE`, pero la prueba reducida evidenció interacción dependiente del orden con `RESTRICT`.
2. **Sustituir la FK histórica.** Retira exclusivamente `areas_plant_id_foreign` y deja `areas_plant_org_fk` como protección de planta y tenant. Cambia el comportamiento: un cambio del ID de una planta referenciada ya no se propaga al área y queda restringido.

La búsqueda en código y pruebas del checkout CAT-01 disponible no encontró referencias por nombre a la FK histórica ni una operación explícita que actualice IDs de plantas. Esto no descarta consumidores externos o dependencias no presentes en ese checkout. CAT-DEC-04 respalda integridad de la jerarquía, pero por sí sola no autorizaba modificar la FK histórica.

## 3. S1.1-A2: prototipo SQLite

Se ejecutó un prototipo sobre SQLite `:memory:` con las migraciones históricas completas. Antes de migrar se comprobaron `APP_ENV=testing`, conexión efectiva SQLite a `:memory:`, `TestDatabaseGuard`, `DB_URL` vacío, flag de acceso externo desactivado y claves foráneas activas. No se usaron bases persistentes ni MariaDB.

El prototipo reconstruyó `areas` para sustituir la FK y luego hizo la operación inversa. Los resultados informados fueron satisfactorios para:

- inserción válida y rechazo de relación entre tenants, planta inexistente, cambios de ID/organización prohibidos y borrado físico restringido;
- conservación de filas de áreas activas y soft-deleted, UUID/timestamps y datos de líneas, `user_areas`, inspecciones e incidencias;
- conservación de los objetos inspeccionados, incluidos índices existentes, un trigger y una vista de prueba; ambos objetos de prueba siguieron funcionando tras `up` y `down`;
- restauración del catálogo SQLite observado tras `down`, retiro del índice nuevo y restauración de la FK histórica `ON UPDATE CASCADE`;
- `PRAGMA foreign_key_check` sin resultados e `integrity_check` igual a `ok` antes, durante y después de los ciclos.

La reconstrucción temporal suspendió la aplicación de FK fuera de la transacción, verificó integridad antes de iniciar y antes/después del commit, y la reactivó al terminar. Un primer intento falló porque una vista seguía apuntando a `areas`; al retirar y recrear la vista dentro de la transacción, el ciclo pasó. Por tanto, la reconstrucción debe inventariar y conservar vistas, triggers e índices; la prueba no justifica omitir esas comprobaciones en una futura migración.

## 4. CAT-02-INT-02 — decisión aprobada

**Decisión aprobada por el responsable del proyecto:** sustituir la FK histórica simple por `areas_plant_org_fk` con `ON DELETE RESTRICT ON UPDATE RESTRICT`.

Justificación registrada:

- la coexistencia de `CASCADE` y `RESTRICT` mostró dependencia del orden de declaración en SQLite;
- S1.1-A2 demostró la viabilidad de la sustitución y del rollback en un prototipo SQLite aislado;
- la sustitución evita esa interacción contradictoria y aplica la integridad compuesta aprobada en CAT-02-INT-01.

La aprobación exige que una migración **nueva** conserve las demás FK e índices, datos activos y soft-deleted, referencias entrantes y que su `down` restaure la FK histórica y su comportamiento `CASCADE`. La aprobación original no autorizaba crear ni ejecutar la migración; posteriormente S1.1-A3.2 autorizó su implementación sin ejecutarla. No se autoriza editar migraciones antiguas ni aplicar DDL en bases persistentes.

## 5. Límites y trabajo pendiente

- La evidencia corresponde a SQLite `:memory:` y a un prototipo, no a una migración Laravel final ni a una prueba automatizada mantenida.
- La lista completa de triggers/vistas de una instalación real no puede inferirse del prototipo; debe inspeccionarse en el entorno objetivo mediante un procedimiento autorizado, antes de cualquier cambio persistente.
- No se ejecutó MariaDB 11.8 ni Docker. Compatibilidad de DDL, tipos, índices, colaciones, bloqueos, validación de datos y recuperación de una operación interrumpida permanece pendiente.
- No se acredita disponibilidad para producción, despliegue ni compatibilidad de datos reales.

## 6. Continuidad acordada

1. **S1.1-A3.1 — recuperación documental:** restaurar/reconstruir registros CAT-02 y consolidar evidencia y decisiones. Este documento forma parte de esa fase.
2. **S1.1-A3.2 — migración:** diseñar e implementar una migración nueva y reversible, sin ejecutar sobre bases persistentes.
3. **S1.1-A3.3 — pruebas automatizadas SQLite:** verificar integridad, conservación, referencias entrantes, `up/down` y fallos controlados en `:memory:`.
4. **S1.1-B — validación MariaDB:** revisar y probar separadamente DDL y comportamiento en MariaDB 11.8.
5. **S1.2 — HTTP:** implementar el endpoint conforme al contrato CAT-02 aprobado.
6. **S1.3 — auditoría, OpenAPI y regresiones:** revisar auditoría, contrato y suites afectadas; no se adelanta OpenAPI.
7. **S1.4 — integración:** integrar únicamente tras superar revisiones y gates acordados.

Estas fases describen continuidad, no autorización para ejecutar DDL persistente, desplegar o publicar cambios.

## 7. Preparación de pruebas S1.1-A3.3

Antes de considerar validada una futura migración, ejecutar en SQLite `:memory:` y con `APP_ENV=testing`, conexión efectiva y `TestDatabaseGuard` verificados:

- `up` y `down` sobre el esquema completo con áreas activas y soft-deleted, comparando todas sus columnas, UUID y timestamps.
- Plantas del mismo y de distintos tenants; rechazar planta inexistente, pareja inconsistente y cambios o borrados prohibidos.
- Verificar las FK entrantes y los datos de líneas, inspecciones, incidencias y `user_areas`; conservar índices, triggers y vistas compatibles y comprobar su funcionamiento.
- Comprobar `foreign_key_check`, `integrity_check`, ausencia de objetos residuales y restauración efectiva de `ON UPDATE CASCADE` después de `down`.
- Introducir datos históricos inconsistentes y objetos dependientes no compatibles; confirmar que el preflight aborta sin alterar datos ni esquema.
- Provocar fallos en distintos puntos y comprobar rollback de DDL, restauración de `PRAGMA foreign_keys` y rechazo de una transacción externa incompatible.

La ejecución posterior de estas pruebas y sus límites se registran en la sección 8. MariaDB 11.8 requiere además pruebas independientes de DDL, integridad, concurrencia, bloqueo y recuperación.

## 8. Implementación incremental SQLite

En esta fase la migración nueva `2026_11_14_000001_replace_areas_plant_foreign_key.php` implementó la sustitución y su inversa **solo en SQLite**. La ampliación posterior para MariaDB se documenta en la sección 9; no es integrable ni publicable hasta validarla en ese motor.

La reconstrucción usa las columnas explícitas del esquema histórico, comprueba las FK e índices esperados, la clave candidata padre, todas las relaciones área–planta y los controles de integridad. Ante columnas, índices, vistas o triggers inesperados aborta, sin eliminar esos objetos. La política actual rechaza incluso vistas o triggers compatibles: su preservación automática no está implementada.

`withinTransaction=false` evita una transacción automática de migración; también se rechaza una transacción externa. Se comprueba que `foreign_keys` esté activo, se suspende fuera de la transacción propia, se hacen las comprobaciones antes de confirmar y se restaura en `finally`. `down` reconstruye la FK histórica `RESTRICT`/`CASCADE` y elimina el índice añadido. No usa `writable_schema`.

En SQLite `:memory:`, con entorno y `TestDatabaseGuard` verificados, las pruebas focalizadas cubrieron el migrador desde cero, datos activos y soft-deleted, aislamiento tenant, rechazo de actualización/borrado del padre, referencias desde líneas, `user_areas`, inspecciones e incidencias, conservación de índices y filas en `up/down`, `CASCADE` restaurado y ambos controles de integridad. También se probaron la ausencia de la clave candidata, datos inconsistentes, vistas/triggers desconocidos, una transacción externa, driver no soportado y un fallo inducido tras `DROP TABLE`, verificando rollback y restauración de `foreign_keys`.

Las pruebas históricas de rollback de normas se adaptaron a `migrate:fresh` en SQLite en memoria sin la transacción envolvente de `RefreshDatabase`, incompatible con la reconstrucción CAT-02. Se conservaron sus verificaciones originales.

Resultado final: prueba focalizada CAT-02 **9 pruebas, 114 aserciones, todas aprobadas**; suite completa SQLite **495 pruebas, 486 aprobadas, 9 omitidas, 1808 aserciones**. En una ejecución anterior la suite detectó dos fallos en las pruebas de rollback de normas por la transacción envolvente; tras adaptar su aislamiento, la ejecución final pasó. Las omisiones no constituyen evidencia de MariaDB.

## 9. S1.1-B — implementación MariaDB antes de validación real

La misma migración implementa una rama para drivers Laravel `mysql` y `mariadb`, verificando que el servidor efectivo sea MariaDB 11.8. Otros servidores/drivers se rechazan antes de DDL. El preflight verifica `foreign_key_checks` activo, ausencia de transacción externa/prefijo, InnoDB, tipos y colaciones, índices y FK esperados, disponibilidad de nombres y coherencia de todas las áreas. No repara datos.

Cada dirección propone un `ALTER TABLE` agrupado: `up` retira `areas_plant_id_foreign` y agrega el índice y la FK compuestos; `down` retira estos últimos y restaura la FK simple `RESTRICT`/`CASCADE`. No se desactiva `foreign_key_checks`. La documentación de MariaDB indica que añadir una FK validando filas puede requerir `ALGORITHM=COPY`; en esta fase no se había probado el `ALTER`. La validación posterior y sus límites constan en la sección 10. No se confía en una transacción Laravel para deshacer DDL.

Se preparó `AreaPlantTenantMigrationMariaDbTest` para instalación completa, integridad tenant, conservación de datos y referencias, `up/down`, `CASCADE`, índices, datos inconsistentes y un `ALTER` deliberadamente inválido. Su ejecución posterior en MariaDB consta en la sección 10. Los resultados SQLite no sustituyen esas pruebas.

Regresión SQLite posterior a la ampliación: **500 pruebas, 487 aprobadas, 13 omitidas, 1822 aserciones**. Las cuatro pruebas nuevas MariaDB se omitieron en SQLite. La selección focalizada obtuvo **16 pruebas: 12 aprobadas, 4 omitidas, 142 aserciones**. La simulación de un servidor MySQL no MariaDB verificó su rechazo antes de DDL, sin conectarse al servidor.

### Diagnóstico de fallos y recuperación

DDL puede completar antes de que Laravel registre la migración, por lo que ni un error ni la tabla `migrations` bastan para conocer el estado real. Detener escrituras y no reintentar automáticamente. Con autorización y acceso apropiado, cotejar `SHOW CREATE TABLE areas`, índices y FK en `information_schema`, `@@SESSION.foreign_key_checks`, filas inconsistentes y el registro de migración; contrastar con el estado histórico o el compuesto completo, incluido el índice. En caso parcial, ambiguo o de discrepancia con `migrations`, detenerse y acordar una recuperación específica, con respaldo y revisión del responsable; no ejecutar reparaciones automáticas ni editar registros por mera suposición.

### Gate de pruebas MariaDB — requiere autorización expresa

El gate descrito a continuación se preparó antes de la autorización y ejecución registradas en la sección 10: preparar una instancia MariaDB 11.8 exclusiva y efímera, red y contenedor exclusivos identificados para la ejecución, base temporal con sufijo `_test`, usuario limitado a esa base y puerto asignado aleatoriamente en `127.0.0.1`; sin volúmenes persistentes. No reutilizar servicios existentes. Mantener las credenciales temporales fuera de Git, documentación y logs.

Antes de migrar, comprobar versión, puerto/host efectivos, base `_test`, usuario, `APP_ENV=testing`, `DB_URL` vacío y `TestDatabaseGuard`. El archivo `phpunit.mariadb.xml` fuerza el driver MariaDB; proporcionar por entorno el host, puerto, nombre y credenciales temporales y `TESTING_DB_ALLOW_EXTERNAL=true`. Su `migrate:fresh` elimina tablas: ejecutar solo tras confirmar que el destino es el contenedor efímero autorizado. Ejecutar primero las pruebas focalizadas y después las regresiones autorizadas, registrando resultados y versión real. Finalmente retirar únicamente el contenedor, red y demás recursos creados para esa ejecución, verificando su limpieza incluso tras fallos; no eliminar recursos ajenos.

## 10. S1.1-B2 — validación real autorizada

Se utilizó MariaDB **11.8.9-MariaDB-ubu2404** en un contenedor y red exclusivos, con `/var/lib/mysql` en `tmpfs`, puerto aleatorio ligado a `127.0.0.1` y base efímera `inspecta_cat02_test`. Antes de migrar se verificaron identidad del contenedor, entorno `testing`, driver efectivo `mariadb`, usuario de la base de prueba, `TestDatabaseGuard` y `foreign_key_checks=1`.

Las pruebas focalizadas MariaDB y de rollback de normas pasaron: **8 pruebas y 96 aserciones**. Verificaron el `ALTER TABLE` agrupado, definiciones y acciones en `information_schema`, índice compuesto, altas y restricciones tenant, conservación de áreas activas y soft-deleted y referencias entrantes, `up/down/segundo up` y restauración de `CASCADE`. El DDL deliberadamente inválido y el ALTER equivalente con `ALGORITHM=INPLACE` rechazado con checks activos conservaron las restricciones e índices. Esto no simula una caída del servidor. Se comprobó también que una migración aplicada sin registro correspondiente aborta al reintentarse, sin reparación automática.

La primera suite completa detectó contaminación de pruebas por el estado estático de `RefreshDatabase` tras ejecutar `migrate:fresh` fuera de su transacción. Se corrigió el aislamiento de las pruebas específicas de reversibilidad reiniciando ese estado al terminar. Una ejecución adicional quedó invalidada por solapar dos procesos `migrate:fresh` en la misma base efímera; las repeticiones válidas se ejecutaron secuencialmente. No se modificó la migración histórica ni se relajaron las aserciones.

El ALTER productivo no fija `ALGORITHM`: su selección efectiva no fue medida; `INPLACE` forzado fue rechazado, coherente con la documentación sobre la necesidad de `COPY` al añadir FK con checks activos. No se han probado caída del servidor, volumen de producción, concurrencia o recuperación después de una interrupción real.

La suite completa MariaDB, repetida secuencialmente tras corregir aislamiento, obtuvo **502 pruebas, 484 aprobadas, 18 omitidas y 1805 aserciones**. La suite SQLite posterior obtuvo **502 pruebas, 487 aprobadas, 15 omitidas y 1822 aserciones**, conservando las 487 aprobadas y 1822 aserciones del estado anterior. PHP lint, Pint y `git diff --check` pasaron.

Se retiraron y verificaron ausentes el contenedor y red exclusivos. El almacenamiento de MariaDB era `tmpfs` del contenedor, sin volúmenes Docker; también se eliminaron el archivo de configuración PHPUnit temporal, credenciales efímeras y caché temporal. No se eliminaron recursos Docker preexistentes. La ejecución no acredita despliegue ni autoriza DDL en bases persistentes.

## 11. Cierre técnico S1.1

La auditoría independiente concluyó **APROBADA CON SALVEDADES** y no identificó bloqueantes para iniciar S1.2. La evidencia final fue SQLite: 487 aprobadas, 15 omitidas y 1822 aserciones; MariaDB 11.8.9: 484 aprobadas, 18 omitidas y 1805 aserciones.

Antes de desplegar permanecen pendientes la revisión del esquema y los datos reales, el inventario de vistas y triggers SQLite, un respaldo y plan de recuperación autorizado, la evaluación de bloqueos y concurrencia en MariaDB y el diagnóstico de posibles discrepancias entre DDL y `migrations`. No se probaron caídas reales del servidor ni se autoriza DDL en bases persistentes por este cierre.
