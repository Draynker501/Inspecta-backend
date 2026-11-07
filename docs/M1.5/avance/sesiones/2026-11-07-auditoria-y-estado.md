# Bitácora de sesión — 2026-11-07 — Auditoría integral y estado real

## Objetivo

Auditoría de solo lectura del estado real del proyecto frente a los informes previos,
más actualización de la documentación de estado.

## Hechos VERIFICADOS (comandos ejecutados)

| Dato | Valor | Evidencia |
|---|---|---|
| master (local = origin) | `b2a27b7` (Merge PR #9) | `git rev-parse master` |
| Suite actual | 243 pruebas, 571 aserciones, 0 fallos | `vendor/bin/phpunit` |
| Migraciones | 44 | `ls database/migrations \| wc -l` |
| `composer validate` | válido | `composer validate --no-check-publish` |
| Pint (global) | 42 archivos con diferencias | `vendor/bin/pint --test` |
| `require.php` | `^8.4` | `composer.json:9` |
| CI matriz | PHP 8.4 y 8.5, fail-fast false | `.github/workflows/tests.yml` |

## Ramas y PRs

- `master` = `b2a27b7` (PR #7, #8 y #9 fusionados).
- `feature/inspecta-p3-error-codes` = `c463c0d` (PR #10 abierto, NO fusionado): añade `code` a rechazos de `ResolveTenantContext` (D-31) + `TenantErrorCode` + prueba.
- Ramas P3 anteriores (`p3-integracion`, `p3-fk-usuarios`, `p3-auditoria`) totalmente integradas en master.
- Ramas P4 (`p4-seeders-openapi`) y P3.5 (`p3.5-strict-scope`) fusionadas.

## Hallazgos principales

1. La documentación de estado (`ESTADO.md`) estaba desactualizada: declaraba HEAD `f3e529f` y "sin push"; hoy master es `b2a27b7` con PR #7, #8 y #9 fusionados.
2. `feature/inspecta-p3-integracion` NO contiene `0b85193` ni `69c5d54` (viven en `p3-fk-usuarios`); la rama está detrás de master.
3. La suite creció de 205/497 (informe previo) a 243/571 sin regresiones.
4. Migraciones: 43 → 44 (P4 añadió la de norms con unique por organización).
5. `TenantController` y factories de dominio siguen ausentes (diferidos a M2), como estaba previsto.
6. `AuditLogger`, `PlatformOperatorPolicy`, `ResolveTenantContext` con `X-Organization-Id` y `AuditLog` append-only implementados (P3.1–P3.4, P3.5, P4).
7. Deuda de estilo: 42 archivos con diferencias de Pint (global, preexistente).
8. Sin secretos ni archivos sensibles trackeados; `docs/` solo versiona `docs/M1.5/`.

## Discrepancias documentación vs código

- `ESTADO.md`: HEAD y estado de PRs desactualizados.
- `README.md` de M1.5: declaraba "Nada implementado" y PHP `^8.3`; hoy PHP `^8.4` y P1–P4 implementados.
- Decisiones D-16 (OpenAPI 1.4.0 aditiva) y D-18 (CORS) resueltas en P4; D-31 (códigos de error) implementado en rama pendiente de fusión (PR #10).

## Pendientes

- Fusión de PR #10 (error-codes, D-31).
- P5: regresión y cierre de M1.5 (allowlist de `withoutTenant`, auditoría de cobertura, documentación de excepciones).
- M2 bloqueada hasta cerrar P5.

## Acciones de esta sesión

- Auditoría de solo lectura ejecutada (comandos listados en el informe anterior).
- No se modificó código. Solo se creó/actualizó documentación.
EOF
echo done