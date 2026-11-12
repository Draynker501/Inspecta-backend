# Informe de revisión de cierre local — M2.2 (núcleo de autorización)

> **Estado histórico al 2026-11-11.** Este informe se cerró antes de la integración de M2.2. PR #27 fue fusionado el 2026-11-12 (`be37d736237faf3424c7bc01532f6cd17ccb03a8`). Los veredictos y pasos de publicación que siguen describen esa revisión local, no el estado vigente. Ver `09-acta-cierre-m2.md`.

**Fecha de revisión:** 2026-11-11
**Rama:** `feat/m2-2-nucleo-permisos`
**HEAD:** `6846f7db94ee6f357cdd68c62c071a7991fb099e`
**Base del diff de M2.2:** `0919383` (merge PR #26, docs M2.1). 10 commits locales sobre la base.
**Estado del árbol:** limpio antes de esta revisión; cambios locales limitados a documentación bajo `docs/` y una prueba propia de M2.2.
**Veredicto:** LISTA CON SALVEDADES (validada localmente, pendiente de publicación, PR e integración).

## 1. Alcance revisado

M2.2 incorpora el núcleo de autorización: enum `Permission` (14 permisos), modelo `RolePermission`, migración `role_permissions`, `User::hasPermission()`, caché en `TenantContext` y helper `TenantPolicy::hasPermission()`. No conecta policies de dominio ni rutas de producción; no modifica OpenAPI.

## 2. Verificaciones ejecutadas

| # | Comando | Entorno | Resultado | Cifras |
|---|---|---|---|---|
| 1 | `vendor/bin/phpunit --filter 'PermissionCatalogTest\|PermissionAuthorizationTest\|PermissionCacheHttpTest\|RolePermissionSchemaTest\|NormsMigrationReversibilityTest'` | SQLite | OK | 29 pruebas, 75 aserciones |
| 2 | `vendor/bin/phpunit` | SQLite | OK | 377 pruebas, 368 aprobadas, 9 omitidas, 1177 aserciones, 0 fallos |
| 3 | `vendor/bin/phpunit -c phpunit.mariadb.xml` | MariaDB 11.8 aislado (127.0.0.1:3321) | OK | 377 pruebas, 369 aprobadas, 8 omitidas, 1206 aserciones, 0 fallos |
| 4 | `vendor/bin/phpunit -c phpunit.mariadb.xml --filter 'RolePermissionSchemaMariaDbTest\|NormsMigrationReversibilityMariaDbTest'` | MariaDB 11.8 | OK | 5 pruebas, 19 aserciones |
| 5 | `vendor/bin/pint --test` | local | OK | 0 diferencias |
| 6 | `composer validate --no-check-publish` | local | OK | válido |
| 7 | `composer audit --locked` | local | OK | 0 advisories |
| 8 | `git diff --check` | local | OK | sin errores de whitespace |
| 9 | Inspección de rutas y OpenAPI | local | OK | sin rutas de producción nuevas; OpenAPI 2.1.0 sin cambios |

Entorno MariaDB: contenedor desechable `inspecta-m22-mariadb` (mariadb:11.8), puerto 127.0.0.1:3320, base `inspecta_test`, credenciales de prueba en línea. No se reutilizaron volúmenes del proyecto.

## 3. Revisión técnica del núcleo

1. **Catálogo:** 14 valores únicos con formato `recurso.accion`; rechazo de valores desconocidos al crear y al actualizar (`RolePermission::assertPermissionInCatalog`), cubierto por pruebas.
2. **Esquema:** índice único `role_permissions_role_org_perm_unique` y FK compuesta `(role_id, organization_id) -> roles(id, organization_id)` con `ON DELETE RESTRICT`, `ON UPDATE CASCADE`; equivalente verificada en SQLite y MariaDB.
3. **Evaluación:** denegación por defecto; requiere contexto organizacional y membresía activa; sin privilegios implícitos de plataforma.
4. **Roles:** combinación de permisos de varios roles mediante `whereIn` + `unique()`; aislamiento entre usuarios y organizaciones verificado.
5. **Caché:** clave `user_id:organization_id`; no se filtra entre usuarios u organizaciones; limpieza en `clear()` y entre peticiones mediante `ResetTenantContext`; invalidación por eventos `saved`/`deleted` de `RolePermission` y `created`/`updated`/`deleted` de `UserRole`; revalidación de membresía en cada llamada.
6. **Escrituras masivas:** `DB::table()->insert()` / `insertOrIgnore` omiten eventos y dejan la caché stale; el diseño exige usar `RolePermission::create` o llamar `TenantContext::flushPermissionCache()` explícitamente. No se implementa aprovisionamiento (M2.3).
7. **Ciclo de vida:** `TenantContext` es singleton; `ResetTenantContext` limpia antes y al terminar cada petición; en pruebas HTTP el contenedor recrea el singleton entre peticiones, por lo que la omisión del middleware no es detectable en ese entorno (ver salvedad).
8. **Alcance:** policies existentes, rutas de producción y contrato mantienen comportamiento previo; no se añadieron rutas ni cambios en OpenAPI.
9. **Reversibilidad:** el ajuste de `NormsMigrationReversibility` calcula `--step` dinámicamente; no debilita la aserción sobre norms ni oculta migraciones pendientes.

## 4. Contradicciones resueltas

1. **"12 mutaciones detectadas" vs "mutación A no detectable":** se reconstruyó un inventario de 13 mutaciones. 12 son detectadas por pruebas; la mutación que omite `TenantContext::clear()` en `ResetTenantContext` no es detectable en el entorno de pruebas porque el singleton se recrea entre peticiones. No se contabiliza como detectada. La mutación equivalente en el método `TenantContext::clear()` sí es detectada.
2. **D-27:** aparecía como "Propuesta" en `docs/M2/01-modelo-de-autorizacion.md`; corregido a "Implementada" (config.platform.php fijado a 8.4.1).
3. **D-38 y P33-D07 asociados al PR #24:** no verificable con el historial disponible más allá de los commits locales; se mantiene la referencia documentada sin inventar números de PR adicionales.
4. **Cifras de ESTADO.md:** actualizadas a las mediciones finales (SQLite 376/367/1174; MariaDB 376/368/1203).
5. **"31 pruebas nuevas" y "4 ajustadas":** el bloque añadió 2 archivos de prueba nuevos (`PermissionAuthorizationTest`, `PermissionCacheHttpTest`) más `PermissionCatalogTest` y `RolePermissionSchemaTest`/`RolePermissionSchemaMariaDbTest`; los métodos crecieron de 0 a 33 en `PermissionAuthorizationTest` tras esta revisión (2 nuevos). La diferencia frente a cifras anteriores se explica por datasets y pruebas condicionales por motor.
6. **Auditoría interna vs endpoints de consulta:** la auditoría de asignaciones está implementada (`AuditLogger`); los endpoints de consulta de auditoría siguen pendientes para bloques posteriores.
7. **Alcance org-wide y `reports.view_own`:** siguen abiertos para bloques posteriores conforme al diseño aprobado (D-42 supuesto a confirmar; `reports.view_own` POR CONFIRMAR).

## 5. Tabla de mutaciones

| # | Mutación | Regla que intenta vulnerar | Detectada | Evidencia |
|---|---|---|---|---|
| 1 | `TenantContext::clear()` no vacía `permissionCache` | Limpieza de caché entre peticiones | Sí | `test_cache_no_persiste_entre_peticiones_simuladas`, `test_cache_http_clear_entre_peticiones_tras_cambio_directo_en_bd` |
| 2 | `ResetTenantContext` omite `clear()` | Limpieza entre peticiones en procesos persistentes | Sí (middleware) | `ResetTenantContextTest::test_reset_tenant_context_limpia_cache_de_permisos` |
| 3 | `hasPermission()` no verifica membresía activa | Denegación por membresía | Sí | `test_membresia_revocada_deniega`, `test_membresia_ausente_deniega` |
| 4 | `hasPermission()` devuelve `true` por defecto | Denegación por defecto | Sí | múltiples pruebas de denegación |
| 5 | Sin contexto organizacional no falla cerrado | Fail-closed | Sí | `test_sin_contexto_fail_closed` |
| 6 | Caché keyed solo por usuario u organización | Aislamiento de caché | Sí | `test_cache_no_se_filtra_entre_contextos_misma_instancia`, `test_cache_http_cambio_de_organizacion_entre_peticiones` |
| 7 | `RolePermission` no invalida caché al guardar/borrar | Invalidación ante cambios | Sí | `test_cache_refleja_cambio_de_permiso_del_rol_misma_instancia`, `test_cache_http_cambio_de_permiso_del_rol_entre_peticiones` |
| 8 | `UserRole` no invalida caché al crear/borrar | Invalidación ante asignaciones | Sí | `test_cache_refleja_cambio_de_asignacion_de_rol_misma_instancia` |
| 9 | `UserRole` no invalida caché de org anterior al cambiar `organization_id` | Invalidación en cambios de org | Sí (tras añadir prueba) | `test_cache_invalida_org_anterior_al_mover_user_role` |
| 10 | `hasPermission()` consulta caché antes de revalidar membresía | Revalidación post-caché | Sí | `test_cache_refleja_revocacion_de_membresia_misma_instancia` |
| 11 | `loadPermissions()` sin filtro de organización | Aislamiento de datos | Equivalente demostrada | FK compuesta `(role_id, organization_id) -> roles(id, organization_id)` y `roles.id` PK global hacen redundante el filtro; se conserva como defensa adicional |
| 12 | `RolePermission` acepta permiso desconocido al crear/actualizar | Validación de catálogo | Sí | `test_permiso_desconocido_rechazado_al_crear`, `test_permiso_desconocido_rechazado_al_actualizar` |
| 13 | Migración sin índice único / sin FK compuesta | Integridad de esquema | Sí (MariaDB) | `RolePermissionSchemaMariaDbTest` |

## 6. Cambios realizados en esta revisión

- `tests/Feature/Tenancy/PermissionAuthorizationTest.php`: +2 pruebas propias de M2.2 (`test_cache_refleja_cambio_de_organizacion_en_user_role_misma_instancia`, `test_cache_invalida_org_anterior_al_mover_user_role`) para cubrir invalidación de caché al mover una asignación entre organizaciones.
- `docs/M2/01-modelo-de-autorizacion.md`: corregida referencia de D-27 (Propuesta → Implementada).
- `docs/M1.5/decisiones/REGISTRO_DECISIONES.md`: actualizada evidencia de D-40 con cifras y mutaciones re-medidas.
- `docs/M1.5/avance/ESTADO.md`: cifras, migraciones y estado de M2 actualizados.
- `docs/M2/02-informe-cierre-m2-2.md`: creado (este informe).

No se modificó código de producción ni pruebas anteriores a M2.2.

## 7. Hallazgos

- **Resuelto:** la mutación que omite `TenantContext::clear()` en `ResetTenantContext` se cubre ahora con `ResetTenantContextTest` (nivel middleware, misma instancia singleton), que falla al aplicar la mutación. La prueba HTTP anterior no la detectaba porque `ResolveTenantContext` también invoca `clear()`.
- **Informativo:** `loadPermissions()` sin filtro de organización es equivalente bajo las invariantes del esquema (FK compuesta + PK global de roles); el filtro se conserva como defensa adicional.
- **Informativo:** `reports.view_own` y el alcance org-wide de Admin/Supervisor siguen abiertos por diseño (M2.3+).

## 8. No verificado / no ejecutado

- No se ejecutó contra producción ni se tocaron datos reales.
- No se realizó push, PR, merge ni integración.
- No se verificó en GitHub (solo lectura no disponible en esta sesión); las referencias a PR #24/#26 se mantienen documentadas sin confirmación adicional.

## 9. Pasos exactos restantes para publicar y cerrar (sin ejecutarlos)

1. Revisar `git diff` completo (incluidos archivos nuevos) y confirmar que solo se incluyen archivos de M2.2 y que no hay secretos.
2. Seleccionar explícitamente los archivos de M2.2 con `git add <archivo>` (nunca `git add .` ni `git add -A`).
3. Ejecutar commit autorizado con mensaje descriptivo.
4. Confirmar `git status` limpio y registrar el hash resultante (`git rev-parse HEAD`).
5. `git push -u origin feat/m2-2-nucleo-permisos` (requiere autorización explícita).
6. Abrir PR hacia `master`, verificar checks de CI y obtener aprobación antes de integrar.
7. Tras aprobación del responsable, merge e integración.
8. Iniciar M2.3 (policies de dominio) únicamente tras integración.

Nota: un push no incluye cambios sin commit; los cambios locales deben estar confirmados en la rama antes de publicar.

**M2.2 queda: validada localmente, pendiente de publicación, PR e integración.**

## 10. Resolución de pendientes de cierre

### Pendiente 1 — Filtro organizacional de `loadPermissions()`

Implementación original:

```php
return RolePermission::query()
    ->where('organization_id', $organizationId)
    ->whereIn('role_id', function ($query) use ($organizationId) {
        $query->select('role_id')
            ->from('user_roles')
            ->where('user_id', $this->id)
            ->where('organization_id', $organizationId);
    })
    ->pluck('permission')...
```

Mutación aplicada en copia temporal: eliminar `->where('organization_id', $organizationId)` en `RolePermission::query()` y `->where('organization_id', $organizationId)` en el subquery de `user_roles`.

Conclusión: la mutación es equivalente bajo las invariantes vigentes:
- `roles.id` es PK global (UUID), por lo que un `role_id` pertenece a una única organización.
- `user_roles` tiene FK compuesta `(role_id, organization_id) -> roles(id, organization_id)`.
- `role_permissions` tiene FK compuesta `(role_id, organization_id) -> roles(id, organization_id)`.

Por tanto, un permiso de `role_permissions` solo puede coincidir con una asignación de `user_roles` si ambas filas comparten el mismo `role_id` y, por la FK compuesta, la misma organización. El filtro organizacional se conserva como defensa adicional; no se retira.

### Pendiente 2 — Limpieza del contexto por middleware

`ResolveTenantContext::handle()` también invoca `TenantContext::clear()` antes de establecer el contexto, por lo que las pruebas HTTP no detectaban la omisión en `ResetTenantContext`. Se añadió `ResetTenantContextTest`, que conserva la misma instancia singleton, carga la caché mediante `User::hasPermission()` y ejecuta el middleware real; la mutación que omite `clear()` hace fallar la prueba. Nivel cubierto: integración del middleware, no un servidor persistente real.

### Pendiente 3 — Versión real de OpenAPI

`info.version` en `openapi.yaml` es `2.1.0` (D-39). La referencia anterior a `2.0.0` como versión actual era incorrecta; `2.0.0` se conserva como hito histórico (P5.4b, PR #16). No se modificó el contrato.

### Pendiente 4 — Estado de Git y publicación

Cambios actuales: 4 archivos modificados y 1 nuevo, todos bajo `docs/` o pruebas propias de M2.2; sin secretos. El procedimiento de publicación se amplió para incluir revisión de diff, selección explícita de archivos, commit autorizado, confirmación de árbol/hash, push autorizado, PR, checks y aprobación. No se ejecutó ninguna operación de escritura en Git.
