# INS-02 — consolidación S3

## Revisión independiente

La revisión focalizada inicial detectó una clasificación falsa de FK de una
tabla cuyo nombre contenía `evidences` y una rama inalcanzable en la prueba
de concurrencia de evidencias. Se corrigieron comparando la tabla hija exacta
en MariaDB y leyendo el resultado de cada proceso por separado; se añadió el
caso `archived_evidences` y un desenlace de borrado primero determinista.
La revisión posterior del árbol corregido fue **aprobada con salvedades**:
SQLite 29 pruebas y 469 aserciones; MariaDB 11.8.9 12 pruebas y 224 aserciones.
No certificó por separado todos los puntos de la revisión inicial.

La carrera no fuerza la ventana exacta entre la consulta previa de evidencias
y el DELETE: no demuestra por sí sola que la traducción de la excepción 1451
se ejecute. SQLite no informa la tabla infractora y la inferencia por filas
referenciantes no identifica de manera inequívoca una FK concreta cuando hay
varias relaciones. La prueba PUT/DELETE observa alguna espera en InnoDB,
pero no demuestra que ambos procesos esperasen simultáneamente. La revocación
durante la espera y los errores 1205/1213 no se indujeron. Estas garantías no
deben declararse exhaustivamente probadas.

## Decisiones y DDL

El texto solo compuesto por espacios se rechaza sin modificar valores válidos.
`per_page` de respuestas tiene predeterminado y máximo 100; el parámetro
compartido conserva 15. La visibilidad precede a la validación de paginación.
El recorte se exceptúa solo en PUT de respuestas; los errores SQL ajenos a
evidencias y 1205/1213 no se traducen a 422.

En MariaDB 11.8.9 la sustitución de FK en `up` y `down` se agrupó en un
`ALTER TABLE`: se verificaron las acciones referenciales y que un ADD inválido
revierte los DROP de esa sentencia. Las demás sentencias DDL de la migración
no forman una transacción conjunta. Se ensayaron estados parciales
representativos, no todas las fronteras; no hay recuperación automática.
Antes de una migración persistente se requiere ventana aprobada de exclusión
de escrituras, respaldo verificado, preflight y procedimiento supervisado de
recuperación y verificación de datos, índices y FK.

## Validación final

- SQLite en memoria: 772 pruebas, 685 aprobadas, 87 omitidas, 4125 aserciones,
  salida 0.
- MariaDB 11.8.9 efímera: PHPUnit directo, 772 pruebas, 720 aprobadas, 52
  omitidas, 4936 aserciones, salida 0. Una ejecución mediante `artisan test`
  informó las mismas cifras pero devolvió salida 1; no se interpreta como éxito.
- Las suites incluyen concurrencia, migraciones y contrato. La explicación de
  omisiones por motor está en [la matriz](08-matriz-pruebas.md).

Esto no autoriza producción, publicación del contrato, consumidores, merge ni
despliegue. Permanecen las condiciones y riesgos de
[riesgos residuales](09-riesgos-residuales.md).
