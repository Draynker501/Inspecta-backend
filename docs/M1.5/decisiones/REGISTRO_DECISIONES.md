# Registro oficial de decisiones — M1.5 de INSPECTA

Fuente de verdad para las decisiones D-01 a D-36 y la sección de discrepancias. Transcrito sin reinterpretar.

**Estados:** *Aprobada* (decidida y sin código verificable) · *Implementada* (aprobada y con código) · *Parcial* · *Propuesta* (nadie la ha aprobado).

## D-01 a D-18 (registro de P0)

| ID | Decisión | Estado | Evidencia o nota |
|---|---|---|---|
| D-01 | Organización de la consultora con `organizations.type = 'operator'` | Implementada | Columna `type` en P1; `INSPECTA-OP` creada en los seeders de P4 |
| D-02 | Membresías sin soft delete: `status` y `revoked_at` | Implementada | P1; `status` es string `active`/`revoked` (D-22) |
| D-03 | No se permite el borrado físico de usuarios | Aprobada | FK `RESTRICT` desde membresías; sin prueba (T45 sin cubrir) |
| D-04 | Suspender y archivar organizaciones sin eliminar datos | Aprobada | El middleware rechaza organizaciones inactivas o eliminadas; no hay operación para suspender o archivar |
| D-05 | Retención indefinida, sin purga automática | Aprobada | Sin código asociado |
| D-06 | Sin revocación masiva de tokens en M1.5 | Implementada | El token no lleva organización; la membresía se evalúa en cada petición |
| D-07 | FK a usuario validada por membresía activa, solo si el campo cambia | Implementada | P3.3.6 (`0b85193`, PR #6); `AuditLog.user_id` exento |
| D-08 | Falla cerrada en las fronteras (P3) y scope estricto (P5) | Parcial | Frontera HTTP y modo estricto activo por defecto (PR #9), con la consola exenta; no hay lista de motivos para `withoutTenant` |
| D-09 | Únicos por organización en `roles` y `plants` | Implementada | P2; verificada en MariaDB |
| D-10 | Expand/contract con doble escritura temporal | Implementada | La doble escritura se retiró en P3.2 sin registrar la enmienda |
| D-11 | Auditoría append-only con `AuditLogger` | Implementada | P3.4: `AuditLogger` y asignaciones auditadas |
| D-12 | Separación A/B/C y orden de fases P0 a P5 | Aprobada | Las fases se ejecutaron en otro orden y con otros alcances |
| D-13 | MariaDB 11.8 LTS como versión objetivo | Aprobada | Para desarrollo (11.8.9 verificada); la ventana de mantenimiento queda para producción |
| D-14 | Preflight de solo lectura y backup verificable | Aprobada | Se aplica antes de producción; el backup no se ha verificado |
| D-15 | Membresía en asignaciones de roles, plantas y áreas (opción A: FK compuestas) | Implementada | P2; rechazo verificado en MariaDB y por pruebas |
| D-16 | Contrato OpenAPI | Aprobada | **2.0.0** (7 nov 2026); el archivo sigue en 1.4.0 |
| D-17 | Único de `norms (code, version)` por organización | Implementada | P4; el `down()` rechaza revertir con duplicados entre organizaciones |
| D-18 | CORS permite `X-Organization-Id` | Parcial | `config/cors.php` lo permite; el origen por defecto es `*` |

## D-19 a D-36 (surgidas en el proyecto)

| ID | Decisión | Estado | Evidencia o nota |
|---|---|---|---|
| D-19 | Métricas entre clientes por vía separada, con consentimiento por organización | Aprobada | Diferida a un hito posterior |
| D-20 | Roles, plantas y áreas se evalúan contra la organización activa, con membresía activa, en un único punto | Implementada | P3.5a: `findActive` y scopes en `OrganizationMembership` |
| D-21 | `User` fuera del scope de organización | Implementada | `#[Fillable]` sin `organization_id` y sin `BelongsToOrganization` (P3.2) |
| D-22 | `organization_memberships.status` es `string(20)` | Implementada | P1 |
| D-23 | Sin `joined_at`; `created_at` es la fecha de alta | Implementada | P1 |
| D-24 | `UserSeeder` usa contraseñas fijas solo en entorno local | Propuesta | Sin evidencia |
| D-25 | Job de CI con MariaDB 11.8 que ejecute `migrate:fresh --seed` | Propuesta | No implementada |
| D-26 | PHP 8.4 como versión mínima | Implementada | PR #5; la CI corre en 8.4 y 8.5 |
| D-27 | Fijar `config.platform.php` en 8.4.1 | Propuesta | No implementada |
| D-28 | Cabecera `X-Organization-Id` obligatoria | Implementada | 400 sin cabecera o con UUID inválido; sin fallback a la columna legada |
| D-28b | Sin endpoint de "seleccionar organización"; `login` y `me` añaden la lista de membresías | Propuesta | `login` y `me` no listan membresías hoy |
| D-29 | Retirar `organization_id` del fillable de `User` | Superada | Ya se retiró en P3.2, antes de lo planeado |
| D-30 | Login permitido sin membresía activa | Aprobada de hecho | 3 pruebas; no la confirmaron explícitamente |
| D-31 | Campo `code` en los rechazos 400 y 403 del contexto | Implementada | PR #10; en la rama, falta mergear |
| D-31b | Unificar los mensajes del 403 | Propuesta | Sin decidir |
| D-31c | Códigos para los demás errores (`unauthenticated`, `invalid_credentials`, `user_inactive`, `validation_failed`) | Propuesta | Sin decidir |
| D-32 | `viewMemberships` exige también el rol de plataforma | Propuesta | Hoy basta ser miembro activo de la operadora |
| D-33 | FK compuestas a membresías en las tablas de dominio | Propuesta | Para el inicio de M2 |
| D-34 | El responsable asignado debe tener `users.status` activo | Propuesta | Pregunta abierta |
| D-35 | Diferir a M2 las pruebas de jobs y comandos con organización (T20 a T22) | Propuesta | Sin decidir |
| D-36 | Marcar "dentro de una petición HTTP" con un flag del middleware global en vez de `runningInConsole()` | Propuesta | Sin decidir |

**Fuera de la numeración:** P33-D07, diferir `TenantController` a M2, está aprobada (según tus documentos `PLAN_TRABAJO_PENDIENTE.md` y `CHECKLIST_HU_06_07_10.md`).

## Discrepancias que conviene resolver

1. **Dos numeraciones en tus documentos.** Tu checklist del 2 de noviembre usa D-20 para "unicidad organizacional" y D-21 para "identidad global". Aquí D-20 es la evaluación contra la organización activa y D-21 es `User` fuera del scope. Y `PLAN_TRABAJO_PENDIENTE.md` marca "D-21 pendiente". Quien lea esos archivos mezclará decisiones distintas con el mismo ID.
2. **D-30 y D-31 no tienen aprobación explícita.** Las trato como aprobadas porque se implementaron y siguieron adelante, pero no hay constancia de cuándo y quién las aprobó.
3. **D-10 tiene una enmienda sin registrar.** El hook de doble escritura se retiró en P3.2. Un usuario creado fuera de las fixtures y de los seeders no recibe membresía automática.
4. **D-12 describe un orden de fases que ya no es el real.** Las fases se ejecutaron con otros alcances.
5. **La versión 2.0.0 de D-16 está aprobada y no aplicada.** Falta mergear D-31 y hacer la pasada de OpenAPI.