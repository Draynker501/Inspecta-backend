# Informe de cierre M2.5 — Revocación de membresías

> **Estado histórico de validación local.** M2.5 se integró mediante PR #32, commit `becf03e971403cdfe17b7743ef4a6e9f10a5b70a` (2026-11-12). La limitación de MariaDB indicada abajo corresponde a esa ejecución local; el PR integrado cuenta con checks PHP 8.4/8.5 y MariaDB 11.8 satisfactorios en GitHub Actions. Ver `09-acta-cierre-m2.md`.

## A. Veredicto técnico

M2.5 implementada y validada localmente. Revocación organizacional bloquea el dominio en la siguiente petición sin invalidar tokens globales. Usuario globalmente desactivado recibe 401 `unauthenticated` con token previo; login conserva 403 `user_inactive`.

## B. Estado de Git

- Rama: `feat/m2-5-revocacion-membresias`.
- Base: `origin/master` `95ff7db` (PR #30 integrado).
- Sin commit, push, PR ni merge.

## C. Decisiones implementadas

- D-44: Implementada (M2.5) con Alternativa A.
- D-06: conservada (sin revocación masiva de tokens).
- D-20: conservada.
- D-31b/D-31c: conservadas.

## D. Cambios de código

- `app/Http/Middleware/EnsureApiAuthenticated.php`: token de usuario inactivo → 401 `unauthenticated`.
- `app/Exceptions/MembershipRevocationException.php`: nuevo.
- `app/Support/Tenancy/MembershipService.php`: nuevo. `revoke()` con autorización (`memberships.manage` + membresía activa), transacción, bloqueo de fila, idempotencia, auditoría y flush de caché.
- No se añadieron endpoints HTTP.
- No se implementó reactivación.

## E. Comportamiento 401/403 verificado

- Login de usuario inactivo: 403 `user_inactive`.
- Token previo de usuario desactivado: 401 `unauthenticated`. Token no eliminado.
- Membresía revocada: 403 `organization_access_denied`.

## F. Servicio de revocación y autorización

- Autorización explícita por `memberships.manage` y membresía activa. Sin bypass por nombre de rol. Sin acceso implícito a operadores.
- `TenantContext::runAs()` no se considera autorización.
- Revocación de la propia membresía del actor bloqueada.
- Idempotente: llamadas repetidas no duplican auditoría.

## G. Integración de AuditLogger

- Evento `membership.revoked` registrado una sola vez por transición real.
- Incluye membership_id, organization_id, estados y revoked_at.
- Atómico con el cambio de estado (misma transacción).
- Actor de auditoría = usuario que realiza la operación.

## H. Matriz de pruebas HTTP

20 pruebas en `MembershipRevocationTest`, todas aprobadas. Cubren acceso activo, bloqueo, token válido, `/auth/me`, `/auth/organizations`, otra organización, última membresía, usuario desactivado, login inactivo, aislamiento de contexto, permisos no sobreviven, idempotencia, `revoked_at`, auditoría única, tokens no eliminados, autorización, atomicidad y auto-revocación bloqueada.

## I. Resultados de mutaciones

- Omisión de validación de membresía activa en `ResolveTenantContext`: detectada (cambia `organization_access_denied` a `forbidden`).
- Omisión de autorización del actor en `MembershipService`: detectada (no lanza excepción).

Mutaciones cubiertas por pruebas existentes (no ejecutadas individualmente):
- Acceso del usuario globalmente desactivado.
- Pérdida de aislamiento entre organizaciones.
- Revocación accidental de tokens.
- Revocación repetida con auditoría duplicada.
- Inconsistencia entre `status` y `revoked_at`.
- Pérdida de atomicidad entre revocación y auditoría.

## J. Validaciones

SQLite:
- 429 pruebas.
- 420 aprobadas.
- 9 omitidas.
- 1293 aserciones.
- 0 fallos.

MariaDB 11.8: no ejecutado por falta de credenciales de base aislada en este entorno (access denied para root@localhost). Pendiente de ejecución en entorno con credenciales válidas.

Pint: aprobado.
Composer validate: válido.
Composer audit: sin vulnerabilidades.
git diff --check: OK.

## K. Cambios OpenAPI

- Versión 2.2.0 → 2.3.0.
- Texto de `user_inactive` y `unauthenticated` actualizado.
- Sin endpoints nuevos.

## L. Riesgos residuales y salvedades

1. Validación de autorización previa a la transacción (ventana TOCTOU teórica).
2. Membresía del actor sin bloqueo de fila.
3. Reactivación diferida con posible recuperación de permisos previos.
4. Ausencia de endpoints HTTP de membresías.
5. Ausencia de pruebas de concurrencia.
6. Tokens sin expiración pendiente de decisión (D-44).
7. `Auth::setUser($actor)` dentro de la transacción no se revierte automáticamente si la operación falla posteriormente; el impacto se limita a la petición actual.

## M. Archivos modificados y nuevos

Modificados:
- `app/Http/Middleware/EnsureApiAuthenticated.php`
- `tests/Feature/Api/ApiErrorCodesTest.php`
- `openapi.yaml`
- `docs/M2/01-modelo-de-autorizacion.md`
- `docs/M1.5/avance/ESTADO.md`
- `docs/M1.5/decisiones/REGISTRO_DECISIONES.md`

Nuevos:
- `app/Exceptions/MembershipRevocationException.php`
- `app/Support/Tenancy/MembershipService.php`
- `tests/Feature/Tenancy/MembershipRevocationTest.php`
- `docs/M2/06-informe-cierre-m2-5.md`

## N. No ejecutado / no verificado

- Commit, push, PR, merge.
- Validación completa SQLite/MariaDB (Etapa 7 pendiente).
- Pruebas de concurrencia.
