# 03 — Política de ciclo de vida: usuarios, membresías y organizaciones

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1)

**Objetivo:** definir qué se elimina, qué se desactiva, qué se conserva y cómo se preserva la
auditoría, sin destruir historia ni permitir accesos residuales.
**Estado:** propuesta. Nada implementado.

---

## 1. Principios

1. **Nada que haya participado en una operación se borra físicamente.** Se desactiva o se archiva.
2. **La autorización es dinámica.** Un token válido no concede acceso: cada petición valida
   usuario, organización y membresía.
3. **La auditoría es append-only.** No se borra, no se reescribe, no depende de que la membresía
   del actor siga activa.
4. **La historia no se revalida.** Editar un registro histórico no exige que sus responsables
   sigan teniendo acceso.
5. **La revocación no destruye configuración.** Las asignaciones a plantas y áreas se conservan
   inactivas, no se borran.

---

## 2. Evidencia del código que fundamenta la política

| Hecho | Evidencia | Consecuencia |
|---|---|---|
| `users` tiene `status` pero no `deleted_at` | `2026_10_10_000004_create_users_table.php` | La desactivación se hace con `status`; añadir soft delete sería un cambio adicional. |
| `audit_logs.user_id` es nullable con FK `nullOnDelete` | `2026_10_10_0200268_create_audit_logs_table.php:13,21` | Un `DELETE` de usuario **borra al actor** de todo el histórico. |
| Pivotes con FK `user_id` `cascadeOnDelete` | migraciones `000006`, `000007`, `000008` | Un `DELETE` de usuario borra sus asignaciones sin dejar rastro. |
| `organizations` tiene `status` y `deleted_at` | `2026_10_22_000001_create_organizations_table.php` | Suspender y archivar ya son posibles sin migración. |
| FK de dominio a `organizations` es `restrictOnDelete` | `2026_10_22_000002_...php:51-55` | El borrado físico de una organización con datos ya falla. |
| Solo `Organization` usa `SoftDeletes` | `app/Models/Organization.php:14` | No existe borrado lógico en usuarios ni en dominio. |
| `ResolveTenantContext` rechaza organizaciones `trashed()` | `app/Http/Middleware/ResolveTenantContext.php:38-52` | El archivado ya corta el acceso; hay que conservarlo. |

---

## 3. Usuarios

### 3.1 Reglas

| Operación | Permitida | Mecanismo | Efecto |
|---|---|---|---|
| Desactivar | Sí | `users.status = false` | Login y peticiones rechazados; membresías y asignaciones intactas |
| Reactivar | Sí | `users.status = true` | Recupera acceso según membresías vigentes |
| Borrar físicamente | **No** | Prohibido en API, consola y políticas | — |
| Anonimizar (solicitud legal) | Futuro | Fase posterior, con aprobación | Requiere resolver `users.email` único |
| Cambiar email | Sí, con validación | Único global | Identidad estable |

### 3.2 Por qué no se borra

- `audit_logs.user_id` es `nullOnDelete`: el histórico perdería al actor.
- Los pivotes tienen `cascadeOnDelete`: se perderían asignaciones y su trazabilidad.
- Los registros de dominio referencian al usuario (`inspections.user_id`, `incidents.reported_by`,
  `evidences.uploaded_by`, `corrective_actions.assigned_to`, etc.). Un borrado físico obligaría a
  nulear columnas o a bloquear la operación.
- La identidad global debe sobrevivir a la relación laboral.

### 3.3 Qué ocurre al desactivar un usuario

1. `EnsureApiAuthenticated` rechaza el token (ya valida usuario activo).
2. Sus membresías **no** se revocan automáticamente: se conserva el registro de que perteneció.
3. Sus asignaciones a plantas y áreas se conservan.
4. Sus registros históricos siguen visibles para quien tenga acceso a la organización.
5. No se puede asignar a nuevos registros (validación de FK en creación).
6. Sus tokens existentes pueden revocarse de forma masiva como endurecimiento opcional.

### 3.4 Email y reutilización

`users.email` sigue siendo único global, incluso para usuarios desactivados. Consecuencia: no se
puede crear una cuenta nueva con el email de una persona desactivada. La alternativa es reactivar
la cuenta existente. Si en el futuro se exige reutilización (por ejemplo, un email corporativo que
cambia de persona), será necesario anonimizar la cuenta anterior, lo que es una decisión legal y de
producto, no técnica.

---

## 4. Membresías

### 4.1 Reglas

| Operación | Permitida | Mecanismo | Efecto |
|---|---|---|---|
| Crear | Sí (operador) | Insertar fila | Acceso a esa organización si todo lo demás está activo |
| Suspender / revocar | Sí (operador) | `status = 'revoked'`, `revoked_at = now()` | 403 en esa organización; otras membresías siguen vigentes |
| Reactivar | Sí (operador) | `status = 'active'`, `revoked_at = null` | Recupera acceso. Operación explícita que se implementa en P3; el hook actual de P1 **nunca** reactiva una membresía revocada. |
| Borrar fila | **No** | Prohibido | — |
| Soft delete | **No** | No existe columna | — |

### 4.2 Por qué sin soft delete

- MariaDB **no** soporta índices únicos parciales (`WHERE deleted_at IS NULL`).
- Con soft delete y `unique (user_id, organization_id)`, una membresía “borrada” impediría
  re-conceder acceso a la misma persona en la misma empresa.
- La alternativa (borrar de verdad o añadir una columna `active_key` artificial) añade complejidad
  y pierde historia.
- `status` + `revoked_at` cubre el caso real: la relación existe, el acceso no.

### 4.3 Por qué las FK son `restrictOnDelete`

`cascadeOnDelete` sobre `user_id` destruiría la membresía si alguien borra un usuario. Aunque el
borrado de usuarios está prohibido, la restricción debe estar en la base de datos, no solo en la
aplicación. Igual para `organization_id`: una organización no puede borrarse dejando membresías
huérfanas.

### 4.4 Revocación y asignaciones

Al revocar una membresía:

- **No** se borran `user_roles`, `user_plants` ni `user_areas`.
- Esas filas quedan **dormidas**: no conceden nada porque la autorización exige membresía activa.
- Si se reactiva la membresía, la configuración anterior vuelve a estar disponible, lo que es
  deseable operativamente y coherente con “no destruir historia”.

### 4.5 Revocar la última membresía

- El usuario **no** se desactiva automáticamente.
- El token sigue siendo válido criptográficamente.
- Toda petición de dominio responde 403 `organization_access_denied`.
- `/auth/me` sigue respondiendo 200 con identidad y lista de organizaciones vacía, para que el
  cliente pueda mostrar “sin empresas asignadas” en lugar de un error opaco.
- La revocación de tokens es opcional; la garantía es la validación por petición.

---

## 5. Organizaciones

| Operación | Permitida | Quién | Efecto |
|---|---|---|---|
| Suspender (`status = false`) | Sí | Operador | 403 inmediato para todos sus usuarios; datos intactos |
| Reactivar | Sí | Operador | Acceso restaurado según membresías |
| Archivar (`deleted_at`) | Solo consola, con auditoría | Operador | 403; datos conservados; requiere 0 membresías activas |
| Borrar físicamente | **No** | — | Impedido por FK `restrictOnDelete` |
| Suspender/archivar el operador | **No** | — | Invariante de §4.1 de `02` |

Reglas adicionales:

- Archivar exige que no existan membresías activas. No hay cascada automática de revocación: la
  decisión es explícita y auditada.
- Una organización archivada puede reactivarse (restaurar `deleted_at`), pero no en M1.5 por API.
- Los datos de una organización archivada siguen siendo consultables solo por consola auditada, no
  por HTTP.

---

## 6. Acceso efectivo: fórmula única

```text
acceso(peticion) =
      token válido
  AND users.status = true
  AND organizations.status = true
  AND organizations.deleted_at IS NULL
  AND organizations.type = 'client'
  AND membership.status = 'active'
```

Esta fórmula se implementa **una sola vez** en `ResolveTenantContext` y se reutiliza en
`TenantPolicy` y en la validación de FK a usuario. No debe duplicarse en controladores.

### 6.1 Peticiones en vuelo

Si una membresía se revoca mientras una petición está en curso, esa petición termina con el
contexto ya validado. La siguiente petición falla. Es un comportamiento aceptable y documentado; no
se justifica un bloqueo distribuido por el costo y la complejidad.

---

## 7. Auditoría

### 7.1 Qué se conserva

| Campo | Valor | Nota |
|---|---|---|
| `organization_id` | Organización **afectada** (cliente) | Nunca la del operador para datos de dominio |
| `user_id` | Identidad **global** del actor | Puede estar desactivado o sin membresía |
| `action` | Verbo de negocio | `membership.created`, `membership.revoked`, `user.deactivated`, etc. |
| `auditable_type` / `auditable_id` | Entidad afectada | |
| `old_values` / `new_values` | Cambio | Sin secretos ni tokens |
| `created_at` | Momento | |

### 7.2 Reglas

- **Append-only.** Sin endpoint de borrado ni de edición.
- **Sin FK a membresías.** La historia no depende de una relación viva.
- **No se valida la membresía del actor al escribir auditoría.** Si se validara, sería imposible
  registrar la propia revocación: `assertTenantParents()` se ejecuta también en `creating`
  (`BelongsToOrganization.php:31-34`) y rechazaría un `AuditLog` cuyo `user_id` ya no tiene
  membresía activa. El `AuditLogger` es la excepción documentada a esa validación para `user_id`.
- **Se conserva al actor aunque esté desactivado.** No se nulea `user_id`.
- **No se cambia `nullOnDelete` a `cascadeOnDelete`** en la FK de `audit_logs.user_id`.

### 7.3 Quién ve la auditoría

- El administrador de una organización cliente ve la auditoría de **su** organización.
- El operador puede verla de forma agregada solo mediante una operación explícita y auditada
  (`PlatformOperatorPolicy`), nunca saltándose el `OrganizationScope` por defecto.
- En M1.5 no se implementan endpoints de auditoría; solo se define el contrato de escritura.

### 7.4 Eventos mínimos a auditar en M1.5

`membership.created`, `membership.revoked`, `membership.reactivated`, `user.deactivated`,
`user.reactivated`, `organization.suspended`, `organization.reactivated`,
`organization.archived`, `role.created`, `role.updated`, `plant.created`, `plant.updated`.

---

## 8. Retención

| Dato | Retención en M1.5 | Decisión futura |
|---|---|---|
| Usuarios | Indefinida | Anonimización por solicitud legal |
| Membresías | Indefinida | — |
| Organizaciones | Indefinida | Archivado permanente |
| Auditoría | Indefinida | Política legal de conservación |
| Registros de dominio | Fuera de alcance | M2 define borrado lógico de dominio |

No se implementa purga automática en M1.5. Cualquier purga requiere aprobación explícita y
auditoría previa.

---

## 9. Matriz de escenarios

| # | Escenario | Resultado esperado | Código |
|---|---|---|---|
| E1 | Usuario desactivado con token vigente | Rechazo en la siguiente petición | 403 |
| E2 | Usuario desactivado intenta login | Rechazo | 401/403 según contrato actual |
| E3 | Membresía revocada, token vigente | Rechazo solo en esa organización | 403 |
| E4 | Membresía revocada en A, activa en B | B sigue funcionando | 200 |
| E5 | Organización suspendida | Todas sus membresías rechazadas | 403 |
| E6 | Organización archivada | Rechazo; datos intactos | 403 |
| E7 | Reactivación de membresía | Acceso restaurado; asignaciones previas disponibles | 200 |
| E8 | Revocación de la última membresía | Dominio 403; `/auth/me` 200 con lista vacía | 403 / 200 |
| E9 | Intento de borrado físico de usuario | Operación inexistente o rechazada | 404/405 |
| E10 | Intento de borrado físico de organización con datos | Falla por FK | Error de BD controlado |
| E11 | Registro histórico con responsable revocado | Lectura y edición permitidas | 200 |
| E12 | Asignar responsable sin membresía activa | Rechazo en creación | 422 |
| E13 | Revocar membresía y escribir auditoría | Auditoría escrita correctamente | 200 |
| E14 | Operador intenta usar su propia organización como tenant | Rechazo | 403 |
| E15 | Re-crear membresía existente | Se reactiva la misma fila; sin duplicado | 200/201 | [Nota 2026-10-28] reactivar re-creando es una operación explícita que se implementa en P3; el hook de P1 no la realiza |

---

## 10. Decisiones solicitadas

- **D-03:** los usuarios nunca se borran físicamente; sin `deleted_at` en `users` durante M1.5.
- **D-04:** las organizaciones se suspenden y archivan; el archivado es solo por consola, auditado y
  con 0 membresías activas.
- **D-05:** retención indefinida en M1.5; sin purga automática; `actor_organization_id` diferido.
- **D-06:** sin revocación masiva de tokens en M1.5; la contención es la validación por petición.
