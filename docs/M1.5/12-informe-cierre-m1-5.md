# Informe de cierre — M1.5 de INSPECTA

Fecha: 2026-11-10. Rama: `docs/cierre-m1-5`. HEAD: `3edf5cb2506780f918de9af84a5dd8f6e7c96bcc`.

## 1. Alcance de M1.5

M1.5 cubre el aislamiento organizacional multi-tenant, la identidad global de usuario, las membresías, la auditoría, el modo estricto de scope, los seeders multiempresa, el contrato OpenAPI y el cierre de regresión (P5). No incluye controladores ni endpoints de dominio (diferidos a M2).

## 2. Bloques fusionados (PRs)

| PR | Bloque | Evidencia |
|----|--------|-----------|
| #2 | fix(openapi): credenciales de demo | OpenAPI saneado |
| #3 | feature/inspecta-auth-seeders | Auth + seeders base |
| #4 | feature/inspecta-m15-p1-p2 | Membresías, backfill, índices, FK compuestas |
| #5 | ci/php-minimo-8-4 | PHP 8.4 mínimo, CI en 8.4/8.5 |
| #6 | feature/inspecta-p3-fk-usuarios | FK a usuario por membresía (D-07), AuditLog exento (D-11) |
| #7 | feature/inspecta-p3-auditoria | AuditLog append-only, AuditLogger, identidad global (D-21) |
| #8 | feature/inspecta-p4-seeders-openapi | Seeders multiempresa, OpenAPI 1.4.0, CORS |
| #9 | feature/inspecta-p3.5-strict-scope | Modo estricto de scope (D-08) |
| #10 | feature/inspecta-p3-error-codes | `code` en rechazos de contexto (D-31) |
| #11 | docs/estado-2026-11-08 | Estado real tras P3/P4 |
| #12 | feature/inspecta-p5-1-ci-mariadb | Job MariaDB 11.8 en CI (D-25) |
| #13 | feature/inspecta-p5-2-http-flag-withouttenant | Flag HTTP y motivos de bypass (D-36, D-08) |
| #14 | feature/inspecta-p5-3-seeder-por-entorno | Seeders por entorno (D-24) |
| #15 | feature/inspecta-p5-4a-codigos-error | Códigos uniformes (D-31b, D-31c) |
| #16 | feature/inspecta-p5-4b-openapi-2 | OpenAPI 2.0.0 (D-16) |
| #17 | feature/inspecta-p5-5a-seguridad | Superficie de rutas, audit bloqueante |
| #18 | feature/inspecta-p5-5b-contrato-ejemplos | Ejemplos saneados, rutas planeadas |
| #19 | feature/inspecta-p5-5c-pint | Pint en verde, convención de migraciones |

Verificación en MariaDB 11.8: cada bloque con migraciones se validó con `migrate:fresh --seed` y `db:seed` idempotente. Sensibilidad por mutación: cada bloque de P5 incluyó copia temporal en `/tmp` con mutaciones que hicieron fallar la prueba correspondiente (enum, `$ref`, versión, superficie de rutas, `x-status: planned`, correos/contraseñas, formato).

## 3. Revisión punto por punto de la lista de cierre de P5

| Punto de cierre | Estado | Evidencia |
|-----------------|--------|-----------|
| Pruebas completas en SQLite y MariaDB | CUMPLIDO | 307 tests, 0 fallos en ambos motores (878/906 aserciones) |
| Formato (Pint) | CUMPLIDO | `vendor/bin/pint --test` exit 0; `database/migrations` excluido permanentemente |
| Aislamiento multi-tenant | CUMPLIDO | `ResolveTenantContext` fail-closed; 8 rechazos 400/403; sin fallback a `users.organization_id` |
| Identidad global | CUMPLIDO | `User` sin `BelongsToOrganization`; `organization_id` fuera de Fillable |
| Autorización por membresías | CUMPLIDO | `OrganizationMembership` activa requerida; FK compuestas verificadas en MariaDB |
| Integridad de base de datos | CUMPLIDO | 44 migraciones; 6 FK compuestas; unique por organización en roles/plants/norms |
| Migraciones y backfills | CUMPLIDO | Backfill idempotente; `migrate:reset` + `migrate` verificado en CI |
| Seeders | CUMPLIDO | 2 clientes + operadora INSPECTA-OP; contraseñas fijas solo local/testing |
| Contrato OpenAPI | CUMPLIDO | 2.0.0; 11 códigos; validado con `openapi-spec-validator 0.8.4` (OK) |
| CORS | CUMPLIDO CON SALVEDAD | `X-Organization-Id` permitido; `supports_credentials=false` (cumplido). Salvedad: el origen por defecto es `*` (D-18 parcial) y debe restringirse por entorno antes de cualquier despliegue |
| Decisiones finales | CUMPLIDO CON SALVEDAD | Registro consolidado de D-01 a D-38 (41 decisiones). Salvedades: D-18 parcial; D-27 y D-32 a D-35 diferidas a M2; D-38 propuesta aprobada; discrepancias del registro resueltas o documentadas (ver la sección de discrepancias de REGISTRO_DECISIONES.md) |
| Riesgos residuales | ABIERTO | Ver sección 5 |

Recuento: 9 cumplidos, 2 cumplidos con salvedad, 1 abierto (12 puntos).

## 4. Correcciones de rumbo honestas

- **Antes de D-36**, las pruebas HTTP quedaban exentas del modo estricto porque el scope usaba `runningInConsole()`, que devolvía `false` en peticiones HTTP pero las pruebas no marcaban el contexto. Se corrigió con `MarkHttpRequest` (PR #13) y se activó `TENANCY_STRICT_MODE` en CI.
- **Hasta D-25**, las pruebas de esquema solo se ejecutaban en SQLite y no verificaban nombres de constraints de MariaDB. Se añadió el job MariaDB 11.8 con verificación de FK y reglas ON DELETE/ON UPDATE (PR #12).
- **D-28b** se propuso inicialmente como "login y me añaden la lista de membresías" y se rechazó en P5.4b: login y me no listan membresías; la identidad de la sesión depende de `X-Organization-Id`.
- **D-10** tiene una enmienda sin registrar: la doble escritura se retiró en P3.2; un usuario creado fuera de fixtures/seeders no recibe membresía automática.

## 5. Riesgos residuales y pendientes de despliegue

| Riesgo | Motivo | Acción recomendada |
|--------|--------|--------------------|
| No hay forma de crear el primer usuario en producción | D-38 pendiente | Implementar comando de consola (primer bloque de M2) |
| La ruta `/` sirve la vista `welcome` | Superficie innecesaria en producción | Revisar antes de desplegar |
| `serve => false` implica decidir el disco de evidencias | Evidencias no implementadas aún | Decidir disco y firmas al implementar evidencias |
| Errores fuera del catálogo (403 policies, 405, 500) sin `code` | Excepción documentada en D-16 | Añadir `code` cuando se implementen policies en M2 |
| CORS con origen por defecto `*` | D-18 parcial | Restringir orígenes en producción |
| Backups no verificados | D-14 | Verificar backup antes de producción |
| Protección de rama `master` y checks requeridos | NO VERIFICABLE desde el repo | Configurar manualmente en GitHub (lista en sección 6) |
| 45 paths del contrato planeados | Se implementarán en M2 | Mantener `x-status: planned` actualizado |
| Documentos locales sin versionar bajo `docs/` | `docs/ACCESOS_Y_PERMISOS.md` y `docs/.obsidian/` (config de Obsidian), excluidos por `.git/info/exclude` | Respaldar fuera del repositorio (no se leyó su contenido) |

## 6. Checks requeridos (nombres exactos, para protección de `master`)

Leídos de `.github/workflows/`:
- `Tests / PHP 8.4`, `Tests / PHP 8.5`
- `Tests (MariaDB 11.8) / PHP 8.4 (MariaDB 11.8)`, `Tests (MariaDB 11.8) / PHP 8.5 (MariaDB 11.8)`
- `Security / Composer audit`
- `Style / Pint`

## 7. Primeros bloques recomendados de M2

1. **D-38**: comando de consola para crear el primer usuario de plataforma en producción.
2. **Servicio de aprovisionamiento**: roles y normas por cliente al crear una organización.
3. **Código de error `forbidden`** para los 403 de policies (añadir al catálogo).
4. **Catálogo de roles y permisos** (base para autorización de dominio).
5. **TenantController base** (diferido por P33-D07).
6. **Decisiones diferidas**: D-27 (fijar `config.platform.php`), D-32, D-33, D-34, D-35.

## 8. Conclusión

M1.5 está técnicamente completo y verificado (307 pruebas, 0 fallos, Pint en verde, audit limpio). La transición a M2 requiere la aprobación formal del usuario mediante el acta `docs/M1.5/13-acta-aprobacion-m2.md`. El agente no aprueba la transición.

---
*Fin del informe de cierre.*
