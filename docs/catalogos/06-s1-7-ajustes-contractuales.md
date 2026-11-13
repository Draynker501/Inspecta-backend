# S1.7 — Hallazgos contractuales P2

## Código de planta

El middleware `TrimStrings` de Laravel aplica `Str::trim`, que recorta también caracteres Unicode e invisibles en los extremos; `StorePlantRequest` aplica después `strtoupper(trim(...))`. El resultado se valida con 1–50 caracteres ASCII `A-Z`, `0-9` y guion. Un valor bruto puede superar 50 caracteres por sus extremos recortables y usar minúsculas. La columna persistida sigue limitada a 50.

En OpenAPI 3.0.3, `maxLength` y `pattern` validan el valor bruto y no expresan una transformación previa. Un patrón ASCII simple o `maxLength: 50` rechazaría entradas válidas; replicar con una expresión compleja el conjunto Unicode e invisible de Laravel dependería del motor y de su versión y no ofrece una garantía portable demostrada. Se agrega `minLength: 1` como condición necesaria para el valor bruto y se conserva una descripción normativa precisa, sin patrón ni límite máximo bruto ni extensión propietaria: **la validación del esquema aislada no garantiza que el servidor acepte el código**, que sigue aplicando la regla completa. Las pruebas de contrato impiden introducir restricciones estructurales contradictorias; las HTTP contrastan minúsculas, extremos ASCII/Unicode, límite normalizado y caracteres inválidos.

## GET, regresiones y estado vigente

GET `/plants` valida `page` entero mínimo 1 y `per_page` entero entre 1 y 100; OpenAPI documenta ahora 422 `validation_failed` usando `Error`. Las pruebas contractuales comprueban la respuesta documentada y las HTTP verifican errores reales de paginación.

## Evidencia de regresión

**S1.8-B — SQLite `:memory:`:** suite completa, sin filtros: 486 pruebas, 477 aprobadas, 9 omitidas, 1690 aserciones, sin fallos ni errores. La ejecución presentó cuatro advertencias de `UserSeeder` por omitir usuarios con contraseñas fijas.

**S1.9 — MariaDB 11.8.9:** suite completa, sin filtros: 486 pruebas, 478 aprobadas, 8 omitidas, 1719 aserciones, sin fallos ni errores. La ejecución presentó las mismas cuatro advertencias de `UserSeeder`. Se realizó en un contenedor temporal aislado, con red propia, datos en `tmpfs`, puerto limitado a localhost y usuario restringido a una base temporal `_test`; contenedor, red y archivos temporales se eliminaron al finalizar.

Las suites verifican los escenarios cubiertos por sus pruebas; no acreditan concurrencia HTTP de dos solicitudes simultáneas ni las características del entorno destino.

## Estado y gates

La implementación de CAT-01 está completada localmente, las regresiones técnicas SQLite y MariaDB están aprobadas y OpenAPI está conciliado localmente. Esto permite preparar revisión técnica mediante PR, pero **no autoriza publicación contractual ni inicio de operaciones en producción**. El panel administrativo no se ha verificado; la transición PWA está pendiente de verificación funcional y no se declara funcional su modo remoto. La versión contractual requiere aprobación; OpenAPI mantiene 2.3.0, que no se presenta como versión nueva aprobada. La colación y los datos del entorno destino siguen sin verificarse. **CAT-01 no está publicada ni desplegada.**
