# 07 — Contradicciones y decisiones pendientes

**Estado:** propuesta. Nada implementado.

---

## 1. Contradicciones detectadas y su resolución

Cada contradicción se detectó cruzando el orden de fases, las dependencias reales del código y los
criterios de aceptación.

### C1. Los índices estaban después de los seeders multiempresa

**Contradicción.** El plan anterior sembraba roles multiempresa en F4 y cambiaba
`roles_name_unique` en F5. Es imposible: mientras `roles.name` sea único global, el seeder no puede
crear “Administrador” en dos organizaciones.

**Resolución.** Los índices pasan a **P2**, antes de cualquier dato multiempresa. Además es seguro:
hoy los índices globales garantizan que no hay duplicados por organización, así que el nuevo índice
compuesto no puede fallar.

**Consecuencia.** P2 no depende de P1 más allá de que la tabla de membresías exista; podría
ejecutarse incluso en la misma ventana que A1/A2.

---

### C2. `/auth/me` cambiaba de middleware en P3 pero su prueba se adaptaba en P4

**Contradicción.** El plan anterior movía `/auth/me` a `api.auth` en F3 y actualizaba
`TenantRoutingTest` en F4. El gate de P3 habría fallado.

**Resolución.** Cada fase adapta **en el mismo cambio** las pruebas que cubre. P3 incluye
`AuthTest`, `TenantRoutingTest`, `TenantIsolationTest` y `TenantModelTest`.

---

### C3. P1 decía “no romper nada” pero también “factories mínimos”

**Contradicción.** Cambiar el comportamiento por defecto de `UserFactory` (crear organización
automática) en P1 habría roto pruebas existentes que asumen ese comportamiento.

**Resolución.** En P1 los cambios de factory son **aditivos**: se añaden estados
(`withMembership()`, `operatorHome()`) sin modificar el estado por defecto. El cambio de
comportamiento por defecto ocurre en P4, cuando los seeders y fixtures ya son multiempresa.

---

### C4. Fail-closed del scope contra seeders, factories y consola

**Contradicción.** Se pidió que las consultas sin contexto fallen de forma segura. Si
`OrganizationScope` lanza excepción cuando no hay contexto, se rompen seeders, factories,
migraciones y consola en el mismo cambio que introduce el contexto (P3).

**Resolución.** Dos etapas:

- **P3:** fail-closed en fronteras — `TenantContext::requireId()`, `TenantController`, cobertura de
  rutas, jobs y comandos con contexto obligatorio.
- **P5:** modo estricto del `OrganizationScope`, cuando todo el código ya declara su intención con
  `withoutTenant('motivo')` y existe allowlist verificada.

**Riesgo residual aceptado:** entre P3 y P5, un `Model::query()` fuera de un servicio con contexto
puede devolver todas las organizaciones. Se mitiga con pruebas de arquitectura y revisión.

---

### C5. El backfill crea membresías en la organización operadora

**Contradicción.** Si los consultores tienen `users.organization_id = <operador>`, el backfill les
crea una membresía en el operador. Sin una regla explícita, esa membresía los habilitaría como
tenant operativo y el operador podría recibir datos de dominio.

**Resolución.**

- `ResolveTenantContext` rechaza `type = 'operator'` (403 `organization_not_tenant`).
- El selector `/auth/organizations` filtra `type = 'client'`.
- El backfill reporta cuántas membresías de operador creó.
- Pruebas T41, T42 y T56.

---

### C6. `users.organization_id NOT NULL` contra “preparar usuarios de clientes”

**Contradicción.** La decisión 9 pide preparar el modelo para futuros usuarios de empresas
cliente, pero mantener `users.organization_id NOT NULL` obliga a que todo usuario tenga una
organización de origen, incluso si mañana un empleado de cliente se registra sin adscripción previa.

**Resolución.** No se hace nullable en M1.5. La decisión 8 (mantenerla temporalmente) prevalece.
El modelo queda preparado en lo que importa: identidad global + membresías. La nulabilidad se
evalúa en P5, cuando se decida si existirán usuarios sin organización de origen.

---

### C7. `RoleSeeder` usa UUID fijos

**Contradicción.** El seeder hace `updateOrCreate(['id' => UUID_FIJO])`. Con roles por
organización, los mismos tres IDs solo pueden pertenecer a una organización: el resto quedaría sin
roles, o se sobrescribiría la organización del rol.

**Resolución.** En P4, IDs deterministas (UUIDv5 de `organization_id + name`) o clave
`(organization_id, name)` con `updateOrCreate`. Se elimina el uso de `Organization::first()`.

---

### C8. “No cambiar índices” contra `plants.code` único por organización

**Contradicción.** La restricción de esta solicitud dice “no modificar índices”; la decisión
aprobada 4 exige que `plants.code` sea único por organización, lo que es un cambio de índice.

**Interpretación.** La restricción prohíbe **ejecutar** cambios ahora. El cambio forma parte del
plan (P2) y no se ejecutará sin aprobación explícita.

---

### C9. Revalidar todas las FK en update contra conservación histórica

**Contradicción.** `assertTenantParents()` se ejecuta en cada `updating` y revalida todas las FK.
Si el inspector de una inspección de 2025 pierde su membresía, la inspección se vuelve inmutable
aunque solo se cambie el estado.

**Resolución.** En P3 se valida solo lo *dirty*. La inmutabilidad de `organization_id` se mantiene.

---

### C10. Validación de FK a usuario contra la escritura de auditoría

**Contradicción.** Si `AuditLog.user_id` se valida por membresía activa, es imposible auditar la
revocación de esa misma membresía: la escritura falla.

**Resolución.** `AuditLogger` escribe la auditoría con una excepción documentada para `user_id`,
manteniendo la validación de organización. Prueba T43.

---

### C11. Token global sin expiración

**Contradicción.** `sanctum.expiration` es `null` y `EnsureApiAuthenticated` no valida expiración ni
abilities. Un token robado tendría vigencia indefinida sobre todas las organizaciones del usuario.

**Resolución.** Se acepta en M1.5: la contención es la validación por petición de usuario,
organización y membresía. La expiración, las abilities y el token por organización quedan como
endurecimiento posterior. Se documenta como riesgo residual, no como decisión resuelta.

---

### C12. Carrera entre backfill y creación de usuarios

**Contradicción.** El backfill se ejecuta sobre una base viva. Un usuario creado después del lote
que lo habría incluido queda sin membresía.

**Resolución.** Expand/contract: desplegar doble escritura **antes** del backfill, o usar ventana de
solo lectura. Ver `04` §8. Prueba T47.

---

### C13. Versión del contrato OpenAPI

**Contradicción.** El contrato 1.3.0 declara `AuthUser.organization_id` como obligatorio y login
devuelve ese campo. M1.5 cambia la semántica: el usuario puede tener varias organizaciones y la
selección pasa a una cabecera. Si hay consumidores, es una ruptura.

**Resolución.** Decisión pendiente D-16. Recomendación: **1.4.0 aditiva** si no hay consumidores
externos confirmados (mantener `organization_id` como campo legacy y añadir `organizations`);
**2.0.0** si los hay.

---

### C14. Unicidad de `norms (code, version)`

**Contradicción.** Hoy es única global. Con varias empresas, dos clientes no podrán tener la misma
norma con el mismo código y versión. Es el mismo problema que `plants.code`, pero afecta a M2.

**Resolución.** Se difiere a M2 (decisión D-17). No bloquea M1.5 porque no hay seeders de normas
multiempresa en esta fase. Se documenta para no descubrirlo tarde.

---

### C15. `TenantPolicy::isTenantAdmin()` contra el operador

**Contradicción.** `isTenantAdmin()` deriva de `User::isAdmin()`, que lee roles del contexto. Si el
consultor tiene rol administrativo solo en la organización operadora, no sería admin del cliente.
Si se le da rol administrativo en cada cliente, se duplica la configuración y se le trata como
miembro del cliente.

**Resolución.** Dos capacidades distintas:

- `TenantPolicy::isTenantAdmin()` — administrador **de esa organización** (rol en esa organización).
- `PlatformOperatorPolicy` — capacidad **de plataforma**, derivada del rol en el operador, que no
  concede acceso a datos de dominio por sí misma.

La gestión de membresías usa la segunda. La administración de catálogos de un cliente usa la
primera.

---

### C16. `user_roles.organization_id` contra la organización de la membresía

**Contradicción.** Un `user_roles` podría apuntar a un rol de A con `organization_id = B` si se
inserta sin validación.

**Resolución.** Se mantiene la validación existente de que rol y pivote comparten organización, y se
añade la exigencia de membresía activa. Prueba V5 en `04` §7 y decisión D-15.

---

### C17. `Organization::users()` cambia de significado

**Contradicción.** `users()` es `hasMany` por `organization_id`. Tras M1.5, esa relación ya no
significa “miembros”, sino “usuarios con adscripción de origen”.

**Resolución.** Se conserva el método con documentación explícita y se añade `members()` como
relación de acceso. **Verificado:** `Organization::users()` no se usa en `app/`, `database/` ni
`tests/`, por lo que el cambio de semántica no rompe código existente.

---

### C18. Alcance de M1.5 contra “no implementar módulos de dominio”

**Contradicción.** M1.5 introduce `AuditLogger`. ¿Es un módulo de dominio?

**Resolución.** No: es infraestructura de trazabilidad necesaria para las operaciones de membresía.
No se implementan endpoints de auditoría, ni modelos de dominio nuevos, ni dispositivos, ni sync.

---

### Resumen

| # | Contradicción | Resuelta en |
|---|---|---|
| C1 | Índices después de seeders | P2 |
| C2 | Pruebas de `/auth/me` en fase posterior | P3 |
| C3 | Factories en P1 | P1 aditivo |
| C4 | Fail-closed vs seeders | P3 fronteras / P5 estricto |
| C5 | Membresía en el operador | P3 + T41/T42/T56 |
| C6 | `organization_id NOT NULL` | Diferido a P5 |
| C7 | UUID fijos en `RoleSeeder` | P4 |
| C8 | “No cambiar índices” | Interpretación + P2 |
| C9 | Revalidación total en update | P3 dirty-only |
| C10 | Auditoría vs revocación | P3 `AuditLogger` |
| C11 | Token sin expiración | Riesgo aceptado |
| C12 | Carrera del backfill | P1 doble escritura |
| C13 | Versión OpenAPI | D-16 |
| C14 | `norms` único global | M2 / D-17 |
| C15 | Admin de tenant vs operador | P3 policies separadas |
| C16 | Coherencia de pivotes | P3 + D-15 |
| C17 | `Organization::users()` | Documentado |
| C18 | Alcance de auditoría | Aclarado |

---

## 2. Decisiones que requieren aprobación

### Bloqueantes para P0/P1

| ID | Decisión | Recomendación |
|---|---|---|
| **D-01** | ¿La consultora es `organizations.type = 'operator'`? | **Sí**, con las invariantes de `02` §4.1 |
| **D-02** | ¿Membresías sin soft delete, con `status` + `revoked_at`? | **Sí** |
| **D-03** | ¿Usuarios sin borrado físico y sin `deleted_at` en M1.5? | **Sí** |
| **D-04** | ¿Organizaciones se suspenden/archivan; archivar solo por consola? | **Sí** |
| **D-05** | ¿Retención indefinida, sin purga automática en M1.5? | **Sí** |
| **D-10** | ¿Expand/contract con doble escritura, o ventana de solo lectura? | **Doble escritura** |
| **D-12** | ¿Se aprueba la separación A/B/C y el orden P0–P5? | **Sí** |
| **D-14** | ¿Se autoriza el preflight read-only y hay backup verificable? | **Sí** |

### Bloqueantes para P2

| ID | Decisión | Recomendación |
|---|---|---|
| **D-09** | ¿Índices de roles y plantas antes de seeders multiempresa? | **Sí** |
| **D-13** | ¿Ventana de mantenimiento, versión de MariaDB y DDL online confirmados? | APROBADA para desarrollo: MariaDB 11.8 LTS (11.8.9 verificada). Ventana de mantenimiento: pendiente para producción |
| **D-15** | ¿Se exige membresía a `user_roles`/`user_plants`/`user_areas`? | **Sí**, opción A: FK compuestas `(user_id, organization_id)` hacia `organization_memberships(user_id, organization_id)` y `(role_id|plant_id|area_id, organization_id)` hacia `roles`, `plants` y `areas`, en `user_roles`, `user_plants` y `user_areas`. La unicidad usa índices únicos `(id, organization_id)` en `roles`, `plants` y `areas`. |

### Bloqueantes para P3

| ID | Decisión | Recomendación |
|---|---|---|
| **D-07** | ¿FK a usuario por membresía y solo *dirty* en update? | PROPUESTA, pendiente de aprobación (P3) |
| **D-08** | ¿Fail-closed en fronteras (P3) y scope estricto en P5? | PROPUESTA, pendiente de aprobación (P3/P5) |
| **D-11** | ¿Auditoría append-only con `AuditLogger` como excepción? | PROPUESTA, pendiente de aprobación (P3) |
| **D-06** | ¿Sin revocación masiva de tokens en M1.5? | **Sí**. [Nota 2026-10-28] el token de Sanctum no lleva organización (verificado en P1). |

### Bloqueantes para P4

| ID | Decisión | Recomendación |
|---|---|---|
| **D-16** | ¿OpenAPI 1.4.0 aditiva o 2.0.0 con ruptura? | 1.4.0 si no hay consumidores; 2.0.0 si los hay. [Nota 2026-10-28] recomendación vigente: 2.0.0 con ruptura, porque panel y PWA aún no consumen `AuthUser`. |
| **D-18** | ¿CORS permite `X-Organization-Id`? | Verificar y, si no, actualizar la configuración |

### Puede posponerse sin comprometer el aislamiento

| ID | Decisión | Fase |
|---|---|---|
| **D-17** | Unicidad de `norms (code, version)` por organización | M2 |
| — | `membership_type` (`consultant` / `client_staff`) | Usuarios de clientes |
| — | `actor_organization_id` en auditoría | Multi-consultora |
| — | Token por organización / expiración / abilities | Endurecimiento posterior |
| — | Anonimización de usuarios | Requisito legal |
| — | Visibilidad consolidada multiempresa | Endpoints explícitos posteriores |
| — | `organization_id` en `devices` y `sync_operations` | Fase de sync |
| — | UI de selección de empresa | Frontend |
| **D-19** | Métricas entre clientes por vía separada (agregados o datos desidentificados), con consentimiento por organización; nunca relajando OrganizationScope. | APROBADA; implementación diferida a un hito posterior |
| **D-20** | Roles, plantas y áreas se evalúan contra la organización activa y exigen membresía activa, en un único punto de la aplicación. | APROBADA; se implementa en P3 |
| **D-21** | User sale del scope de organización; listados de usuarios de una organización parten de las membresías. | PROPUESTA, pendiente de aprobación (P3) |
| **D-22** | organization_memberships.status es string(20) con 'active' y 'revoked'. | APROBADA (2026-10-28) |
| **D-23** | No se añade joined_at; created_at es la fecha de alta. | APROBADA (2026-10-28) |

---

## 3. Lo que este plan no decide

- No define el catálogo de permisos granular: los roles actuales son nombres y descripciones, sin
  matriz de permisos.
- No define la política de retención legal de datos.
- No define el diseño de sincronización offline ni de dispositivos.
- No define la experiencia de usuario del selector de empresa.
- No define el proceso de invitación de empleados de clientes.
- No afirma que ninguna recomendación esté implementada.

---

## 4. Cierre

Con las decisiones de §2 aprobadas, el orden de ejecución es:

```text
P0  Decisiones + preflight read-only
P1  Membresías + backfill + doble escritura      (sin cambiar autorización)
P2  Índices de roles y plantas
P3  Contexto, auth, policies, FK, fail-closed
P4  Seeders, factories, fixtures, OpenAPI
P5  Deprecación y endurecimiento
M2  Catálogos y controladores de dominio
```

Nada de esto se ha implementado. Se solicita aprobación explícita antes de iniciar P0 y, en
particular, antes de cualquier escritura en la base de datos.
