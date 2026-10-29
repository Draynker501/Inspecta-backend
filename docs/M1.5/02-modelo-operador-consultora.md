# 02 — La consultora como operador diferenciado

Última actualización: 2026-10-28 (D-22, D-23; alineado con el código de P1)

**Decisión propuesta:** `organizations.type = 'operator'`.
**Estado:** pendiente de aprobación (D-01). Nada implementado.

---

## 1. El problema

INSPECTA es operado por una consultora de Seguridad e Higiene Laboral. Sus consultores atienden a
varias empresas cliente. Los datos operativos pertenecen a cada cliente; los consultores son
personal de la consultora y no “pertenecen” a ningún cliente en particular.

Hay que decidir **dónde viven los usuarios de la consultora, sus roles y la capacidad de
administrar membresías**, sin convertirlos en un tenant operativo más.

### 1.1 Restricciones del código actual que condicionan la respuesta

| Restricción | Consecuencia |
|---|---|
| `users.organization_id` es `NOT NULL` con FK a `organizations` | Todo usuario necesita una organización existente, hoy. |
| `roles` y `user_roles` tienen `organization_id NOT NULL` | Todo rol, incluido el rol administrativo de la consultora, necesita una organización propietaria. |
| El catálogo de roles es por organización (decisión aprobada 3) | No existe un “rol global” donde colgar el rol de plataforma. |
| `TenantPolicy::isTenantAdmin()` deriva de `User::isAdmin()`, que lee roles del contexto | La capacidad administrativa es organizacional, no de plataforma. |
| `organizations.code` es único global | El operador necesita un código reservado. |
| Las FK de dominio a `organizations` son `restrictOnDelete` | El operador no puede borrarse si tuviera datos; la invariante debe impedir que los tenga. |

**Conclusión de partida:** mientras `users.organization_id` sea `NOT NULL` y los roles requieran
organización, la consultora **necesita una fila en `organizations`** o hay que abrir una bifurcación
de esquema (columna nullable + catálogo global de roles). La pregunta real no es “¿fila o no?”,
sino **cómo evitar que esa fila se comporte como un tenant**.

---

## 2. Alternativas evaluadas

### Alternativa A — `organizations.type = 'operator'`

Una fila de `organizations` con `type = 'operator'`, código reservado, sin datos operativos. Los
consultores tienen `users.organization_id = <operador>` y una membresía en el operador que porta su
rol de plataforma.

### Alternativa B — Operador fuera de `organizations`

Tabla `consultancies` (o configuración) separada; los usuarios de la consultora tendrían
`users.organization_id` nullable o una segunda FK `consultancy_id`.

### Alternativa C — Operador como organización cliente normal

Una fila más de `organizations` sin discriminador. Los consultores son “miembros” de esa
organización y administran clientes mediante lógica especial.

### Alternativa D — Sin fila de operador

Administración de plataforma definida por configuración (lista de emails o variable de entorno) y
usuarios de la consultora adscritos a la organización de un cliente.

---

## 3. Comparación

| Criterio | A (`type`) | B (fuera) | C (cliente normal) | D (config) |
|---|---|---|---|---|
| Migración de esquema | 1 columna + 1 índice | Tabla nueva o columna nullable + catálogo global de roles | Ninguna | Ninguna |
| `users.organization_id NOT NULL` | Se mantiene | Debe hacerse nullable o añadir FK | Se mantiene | Se mantiene, pero adscribe consultores a clientes |
| Dónde viven los roles de la consultora | Organización operadora | Catálogo global (contradice decisión 3) o nueva tabla | Organización “cliente” | Ninguno; se pierde el modelo de roles |
| Riesgo de confundir operador con tenant | Medio, mitigable | Bajo | **Alto** | Alto (consultores adscritos a clientes) |
| Selector de empresas | Filtra `type = 'client'` | Sin cambio | Debe excluir por código o nombre | Sin cambio |
| Auditoría del administrador | `user_roles` en operador, auditable | Nueva raíz de autorización | Igual que A pero sin discriminador | Fuera de BD, no auditable |
| Futuro multi-consultora | `type` + `operator_id` o nueva tabla | Natural | Ambiguo | Inviable |
| Complejidad de pruebas | Baja | Media-alta | Baja | Media |
| Coste ahora | Bajo | Alto | Bajo, pero deuda alta | Bajo, con deuda de seguridad |

### Por qué se descarta B para M1.5

- Obliga a hacer `users.organization_id` nullable o a introducir una segunda FK, y a decidir qué
  significa “usuario sin organización” en todo el código (factories, seeders, validaciones, login).
- El rol de plataforma no tendría organización propietaria: habría que crear un catálogo global de
  roles, lo que contradice la decisión aprobada 3 y obliga a remapear `user_roles.role_id`.
- Introduce **dos raíces de autorización** (organización y consultora) en una fase que ya cambia la
  raíz de autorización de usuario a membresía.
- Su ventaja —pureza conceptual— no se materializa hasta que existan varias consultoras o la
  consultora necesite datos propios (facturación, SLA, expedientes). Ese escenario no está
  confirmado.

Se reconsidera si aparece cualquiera de estas condiciones: más de una consultora operando la
plataforma; la consultora necesita entidad facturable con datos propios; requisito legal de separar
identidad de negocio de identidad de tenant.

### Por qué se descarta C

- Sin discriminador, la organización operadora aparece en el selector de empresas y puede recibir
  datos de dominio por error.
- Las policies no pueden distinguir “administrador de la consultora” de “administrador de un
  cliente”: ambos serían `isAdmin()` de su organización.
- El aislamiento depende de convención, no de una invariante verificable.

### Por qué se descarta D

- La autorización de plataforma quedaría fuera de la base de datos: no auditable, no revocable por
  API, dependiente de configuración.
- Los consultores quedarían adscritos a un cliente concreto, lo que reproduce el problema de M1 y
  contamina la adscripción de origen.
- No hay dónde almacenar el rol administrativo sin inventar un catálogo global.

---

## 4. Recomendación definitiva: Alternativa A con invariantes estrictas

### 4.1 Esquema

```text
organizations
  id            char(36) PK
  name          varchar(150)
  code          varchar(50)  UNIQUE          -- reservado: INSPECTA-OP
  type          varchar(20)  NOT NULL DEFAULT 'client'   -- 'client' | 'operator'
  status        boolean      DEFAULT true
  deleted_at    timestamp    NULL
  ...

index (type, status)
```

Reglas:

1. Exactamente **una** fila con `type = 'operator'`.
2. Su `code` es un valor reservado y documentado (`INSPECTA-OP`). Los seeders y la API rechazan
   crear organizaciones cliente con ese código.
3. `status = true` y `deleted_at IS NULL` de forma permanente. Sin endpoint de suspensión ni
   archivado para el operador.
4. **Cero** registros de dominio: sin plantas, áreas, líneas, inspecciones, incidencias,
   evidencias, acciones correctivas, checklists, normas, auditoría operativa.
5. No aparece en `/auth/organizations` ni en ningún selector de empresa.
[Nota 2026-10-28] La organización operadora (`INSPECTA-OP`) **no está creada** en P1: solo existe la
columna `organizations.type` y las constantes. Se crea en P4 con los seeders de varias organizaciones.

### 4.2 Autorización de plataforma

- El rol `Administrador de plataforma` se define **en la organización operadora**.
- `User::isPlatformOperator(): bool` comprueba que exista un `user_roles` del usuario con un rol
  llamado `Administrador de plataforma` cuya organización sea la operadora, y que la membresía del
  usuario en el operador esté activa.
- **Importante:** esa comprobación **no** puede depender del `TenantContext` vigente, porque el
  contexto será la organización cliente y `Role`/`UserRole` tienen `OrganizationScope`. Debe
  ejecutarse dentro de `TenantContext::withoutTenant('platform-role-check')` o mediante una consulta
  que no use los modelos con scope. Es un detalle de implementación que, si se pasa por alto,
  produce un falso negativo silencioso.
- `PlatformOperatorPolicy` es la **única** puerta a operaciones multiempresa. No hereda de
  `TenantPolicy` y no concede acceso a datos de dominio por sí misma.

### 4.3 Gestión de membresías

- Crear, suspender y revocar membresías de clientes: **solo** `PlatformOperatorPolicy`.
- La operación nombra explícitamente la organización destino en el cuerpo o en la ruta
  (no se infiere del contexto) y se audita con `organization_id` = organización afectada y
  `user_id` = operador.
- Un administrador de cliente **no** puede crear membresías en M1.5 (decisión aprobada 5). Podrá
  hacerlo en una fase posterior, limitado a su propia organización.
- Un consultor sin rol de plataforma no gestiona membresías.

### 4.4 Cómo se evita que el operador sea un tenant

| Control | Dónde |
|---|---|
| `ResolveTenantContext` exige `type = 'client'`; si recibe el id del operador responde 403 `organization_not_tenant` | P3 |
| `/auth/organizations` filtra `type = 'client'` | P3 |
| Backfill crea membresías también para el operador, pero esas membresías no autorizan dominio | P1/P3 |
| Prueba T41: cabecera con id del operador ⇒ 403 | P3 |
| Prueba T42: `isPlatformOperator` no concede acceso a dominio | P3 |
| Prueba de integridad: ninguna tabla de dominio referencia la organización operadora | P4 |
| Seeder rechaza `code` reservado para clientes | P4 |
| `Organization` expone `scopeClients()` y `isOperator()` | P1 |

### 4.5 Riesgos residuales de A y su mitigación

| Riesgo | Mitigación |
|---|---|
| Un desarrollador usa `Organization::first()` y obtiene el operador | Seeders dejan de usar `first()` (H5/H6); seeder determinista por código |
| El operador recibe datos por un bug | Prueba de integridad por tabla; rechazo en resolver |
| El rol de plataforma se evalúa con el scope del cliente y falla | Comprobación explícita fuera del contexto, con prueba dedicada |
| Un administrador de cliente se confunde con operador | Nombres de rol distintos y policies separadas |
| El operador se suspende por error | Sin endpoint; guarda en modelo/consola; prueba |
| Multi-consultora futura | `type` permite evolucionar a `operator_id`; no se implementa ahora |

---

## 5. Impacto en artefactos

| Artefacto | Cambio |
|---|---|
| Migración `add_type_to_organizations_table` | `type` + índice `(type, status)` |
| `app/Models/Organization.php` | `isOperator()`, `scopeClients()`, `memberships()`, `members()` |
| `database/seeders/OrganizationSeeder.php` | Crea operador con código reservado y al menos un cliente |
| `database/seeders/RoleSeeder.php` | Crea `Administrador de plataforma` en el operador y los tres roles operativos en cada cliente |
| `database/seeders/UserSeeder.php` | Consultor con membresía en operador + membresías en clientes; roles por organización |
| `ResolveTenantContext` | Rechazo de `type = 'operator'` |
| `AuthController::organizations` | Filtra `type = 'client'` |
| `openapi.yaml` | `Organization` incluye `type`; el selector documenta que solo devuelve clientes |
| `tests/Support/TenantFixtures.php` | `operatorOrganization()`, `clientOrganization()` |

---

## 6. Decisión solicitada

**D-01:** aprobar `organizations.type = 'operator'` con las invariantes de §4.1 y la autorización
de plataforma de §4.2.

Alternativa si se prefiere no tocar `organizations`: posponer la administración de membresías a una
fase posterior y gestionarlas por consola en M1.5. Esto evitaría la columna `type`, pero dejaría la
gestión de membresías fuera de la API y no elimina la necesidad de una organización para los
consultores.
