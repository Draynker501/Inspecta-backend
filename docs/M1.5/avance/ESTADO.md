# Estado del avance — M1.5 de INSPECTA (actualizado)

Última actualización: 2026-11-07

**Rama:** `master`
**HEAD:** `b2a27b7 Merge pull request #9 from Draynker501/feature/inspecta-p3.5-strict-scope`
**Sincronizada con origin/master:** sí
**Pruebas (VERIFICADO):** 243 pruebas, 571 aserciones, 0 fallos. Línea base M1: 63 pruebas, 215 aserciones (histórica).
**Migraciones:** 44.
**Composer:** `composer validate --no-check-publish` válido. `require.php = ^8.4`.
**Pint:** 42 archivos con diferencias (deuda global preexistente).

---

## 1. Estado por fase

| Fase | Estado | Alcance |
|------|--------|---------|
| M0 — Auth | Completada e integrada | Sanctum, login/logout/me, EnsureApiAuthenticated. |
| M1 — Aislamiento organizacional | Completada e integrada | TenantContext, OrganizationScope, BelongsToOrganization, middleware `api.tenant`. |
| M1.5 P0 — Decisiones y preflight | Completada (documental) | D-01 a D-35. |
| M1.5 P1 — Membresías, backfill, doble escritura | Cerrada e integrada | Tabla `organization_memberships`, `organizations.type`, backfill idempotente, hook en `User`. |
| M1.5 P2 — Índices de unicidad y FK compuestas | Cerrada e integrada | Únicos por organización en roles/plants; 6 FK compuestas en pivotes. |
| M1.5 P3.1 — Contexto por `X-Organization-Id` | Implementada e integrada (PR #7) | `ResolveTenantContext` fail-closed, sin fallback a `users.organization_id`. |
| M1.5 P3.2 — Identidad global y membresías | Implementada e integrada (PR #7) | `User` sin `BelongsToOrganization`, `organization_id` fuera de Fillable. |
| M1.5 P3.3.1 — Listado de organizaciones | Implementada e integrada | `GET /api/auth/organizations` (solo clientes activos, sin tenant). |
| M1.5 P3.3.2 — Selección de organización | Implementada e integrada | Pruebas de selección. |
| M1.5 P3.3.3 — PlatformOperatorPolicy | Implementada e integrada | Capacidad de plataforma fuera de TenantContext. |
| M1.5 P3.3.5 — Membresía activa centralizada | Implementada e integrada | D-20, login sin membresía activa (D-30). |
| M1.5 P3.3.6 — FK a usuario por membresía | Implementada e integrada | D-07, dirty-only, AuditLog exento (D-11). |
| M1.5 P3.4 — AuditLogger | Implementada e integrada | AuditLog append-only, AuditLogger con redacción, auditoría de asignaciones. |
| M1.5 P3.5 — Modo estricto de scope | Implementada e integrada (PR #9) | `strict_mode` por defecto `true`, allowlist consola, `withoutTenant` bypass. |
| M1.5 P3.3.4 — TenantController base | Diferido a M2 (P33-D07) | — |
| M1.5 P4 — Seeders, operadora, OpenAPI, CORS | Implementada e integrada (PR #8) | 2 clientes + operadora `INSPECTA-OP`, OpenAPI 1.4.0 aditiva, CORS con `X-Organization-Id`. |
| M1.5 P3 (error-codes, D-31) | Implementada, PR #10 abierto (no fusionado) | `TenantErrorCode` + `code` en rechazos de `ResolveTenantContext`. |
| M1.5 P5 — Regresión y cierre | Pendiente | Allowlist verificada, modo estricto en CI, documentación de excepciones. |
| M2 — Controladores y endpoints de dominio | Bloqueada | Depende del cierre de P5. |

---

## 2. Qué existe hoy (VERIFICADO)

- **Contexto de tenant:** `ResolveTenantContext` resuelve por `X-Organization-Id` + membresía activa; fail-closed (8 rechazos 400/403). Sin fallback a `users.organization_id`.
- **Identidad global:** `User` sin `BelongsToOrganization`, `organization_id` fuera de Fillable. Pertenencia solo por `organization_memberships`.
- **Organizaciones:** `GET /api/auth/organizations` lista clientes activos del usuario (sin tenant).
- **Platform operator:** `PlatformOperatorPolicy` + `User::isPlatformOperator()`/`hasPlatformRole()` (membresía activa en operadora única).
- **Auditoría:** `AuditLog` append-only (excepción dedicada), `AuditLogger` con contexto obligatorio y redacción; auditoría de asignaciones de rol/planta/área.
- **Modo estricto:** `OrganizationScope` lanza `MissingTenantContextException` en HTTP sin contexto cuando `strict_mode=true` (default); consola y `withoutTenant()` exentos; workers de cola no exentos.
- **Seeders:** 2 clientes (INSP-01, ORG-02) + operadora INSPECTA-OP; catálogo por organización; consultor de plataforma con membresía en operadora y clientes.
- **OpenAPI:** v1.4.0 (aditiva); cabecera `X-Organization-Id`, `/auth/organizations`, campo `type`.
- **CORS:** `config/cors.php` permite `X-Organization-Id`, `supports_credentials=false`, `max_age=600`.
- **Migraciones:** 44 (incluye unique de norms por organización).

## 3. Qué NO existe todavía

- `TenantController` base (diferido a M2).
- Factories de dominio (solo Organization, OrganizationMembership, User).
- Servicio de revocación/re-concesión de membresías (M2).
- Rutas de dominio (solo `/auth/*` implementadas).
- OpenAPI 2.0.0 (D-16 pendiente de decisión final; actual 1.4.0).

## 4. Decisiones relevantes

- **Resueltas:** D-07, D-09, D-15, D-20, D-21 (implementadas en código); D-16 (1.4.0 aditiva), D-18 (CORS).
- **Pendientes:** D-08 (fail-closed fronteras), D-11 (AuditLogger ya implementado, pendiente de cierre formal), D-24, D-25, D-27, D-29, D-31 (implementado en PR #10, pendiente de fusión), D-32 a D-35.
- **D-31:** implementado en rama `feature/inspecta-p3-error-codes` (PR #10, abierto).

## 5. Validaciones actuales

- Suite: 243 pruebas, 571 aserciones, 0 fallos.
- `composer validate --no-check-publish`: válido.
- `git diff --check`: limpio (en ramas publicadas).
- Pint: 42 archivos con diferencias (deuda global preexistente).

## 6. Alertas y riesgos vigentes

| Riesgo | Estado |
|--------|--------|
| Deuda de estilo global (42 archivos Pint) | ABIERTA |
| `TenantController` base ausente | DIFERIDO a M2 |
| Factories de dominio ausentes | DIFERIDO a M2 |
| Servicio de revocación de membresías ausente | DIFERIDO a M2 |
| OpenAPI 2.0.0 pendiente | ABIERTA (D-16) |
| M2 bloqueada hasta cerrar P5 | ABIERTA |

---

## 7. Restricciones permanentes

- Nunca push a `master`.
- Push de ramas de funcionalidad solo con autorización explícita y sin `--force`.
- No modificar migraciones existentes ni pruebas previas.
- No implementar P3/M2 sin autorización.
- No tocar `.env`.
- MariaDB solo en contenedor aislado con variables en línea.

---

## 8. Cómo retomar

1. Rama `master`, HEAD `b2a27b7`.
2. Suite esperada: 243 pruebas, 571 aserciones.
3. Leer este `ESTADO.md` antes de empezar.
4. Próximo bloque: P5 (regresión y cierre M1.5) o fusión de PR #10.

---

*Fin del documento ESTADO.md.*