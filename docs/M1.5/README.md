# M1.5 — Arquitectura multiempresa de INSPECTA

**Estado:** planificación cerrada, pendiente de aprobación. **Nada implementado.**
**Fecha:** 2026-10-27
**Repositorio auditado:** `Inspecta-backend` (rama `feature/inspecta-auth-seeders`, cambios locales sin commit)
**Stack confirmado en `composer.json`:** PHP `^8.3`, Laravel `^13.17`, Sanctum `^4.3`, MariaDB/MySQL en producción, SQLite `:memory:` en pruebas.

---

## Alcance de esta carpeta

Estos documentos son el plan revisado de la fase **M1.5** (adaptación de la infraestructura
multi-tenant de M1 al modelo de consultoría multiempresa). Son exclusivamente planificación.

Esta carpeta es el **único** artefacto creado. No se modificó ningún archivo de
`Inspecta-backend`, no se crearon migraciones, modelos, controladores, middleware ni pruebas,
no se ejecutaron migraciones, seeders ni backfills, no se escribió en MariaDB, no se cambió de
rama y no se crearon commits. El handler HTTP 429 no se tocó.

---

## Índice

| Documento | Contenido |
|---|---|
| [`01-plan-m1.5-revisado.md`](01-plan-m1.5-revisado.md) | Plan completo revisado: resumen ejecutivo, hallazgos verificados, diferencias M1→objetivo, esquema, plan de datos, cambios por componente, riesgos y fases. |
| [`02-modelo-operador-consultora.md`](02-modelo-operador-consultora.md) | Análisis de la consultora como operador diferenciado. Comparación `organizations.type='operator'` vs. fuera de `organizations`. **Recomendación definitiva.** |
| [`03-ciclo-de-vida-usuarios-membresias.md`](03-ciclo-de-vida-usuarios-membresias.md) | Política de eliminación, desactivación, revocación y conservación histórica. Preservación de auditoría. |
| [`04-migraciones-backfill-indices.md`](04-migraciones-backfill-indices.md) | Separación estricta: migraciones aditivas / backfill / índices. Prechecks read-only, condiciones de parada, rollback y carrera de despliegue. |
| [`05-contexto-tenant-y-fk-usuarios.md`](05-contexto-tenant-y-fk-usuarios.md) | Cómo se evitan consultas sin contexto en HTTP, jobs y consola. Validación de FK a usuarios en creación vs. actualización sin bloquear histórico. |
| [`06-fases-y-pruebas.md`](06-fases-y-pruebas.md) | Fases P0–P5 con límites, gates de pruebas por fase, matriz T01–T46, códigos HTTP y estrategia para mantener la suite verde. |
| [`07-contradicciones-y-decisiones-pendientes.md`](07-contradicciones-y-decisiones-pendientes.md) | Contradicciones detectadas entre orden de fases, dependencias y criterios de aceptación, con su resolución. Decisiones que requieren aprobación. |

---

## Decisiones ya aprobadas por el responsable del proyecto

1. Identidad global por usuario, correo único.
2. Membresías usuario–organización como fuente de autorización.
3. Roles por organización; varios roles por usuario y empresa.
4. `plants.code` único por organización.
5. Membresías administradas inicialmente por personal autorizado de la consultora.
6. Acceso entre empresas denegado por defecto; consultas consolidadas solo con autorización explícita.
7. Token Sanctum global inicialmente, validando membresía y estado de organización en cada petición.
8. `users.organization_id` se mantiene temporalmente, sin uso autorizante.
9. Modelo preparado para futuros usuarios de empresas cliente, sin implementarlos.
10. Módulos de dominio pendientes, dispositivos y sincronización offline fuera de M1.5.

## Resumen de lo que cambió respecto del plan anterior

| Punto | Plan anterior | Plan revisado |
|---|---|---|
| Orden de índices | Después de seeders multiempresa | **Antes** de cualquier dato multiempresa (P2). El orden anterior era imposible. |
| FK de membresías | `cascadeOnDelete` en `user_id` | **`restrictOnDelete`** en ambos lados, para no destruir historia. |
| Borrado de usuarios | No definido | **Nunca borrado físico**; desactivación por `status`. Confirmado por `audit_logs.user_id ON DELETE SET NULL`. |
| Membresías | Soft delete posible | **Sin soft delete**: `status` + `revoked_at`, fila única permanente. MariaDB no tiene índices únicos parciales. |
| Consultora | Ambigüedad operador/cliente | **`organizations.type` con recomendación definitiva** y reglas de rechazo en el resolver. |
| Backfill | Ejecutar y luego desplegar | **Expand/contract**: dual-write antes del backfill, para no perder usuarios creados durante la ventana. |
| Fail-closed | “El scope debe fallar” | **Aplicación en fronteras (P3)** y modo estricto del scope diferido a P5, con justificación. |
| Auditoría de revocación | No resuelto | `AuditLogger` con excepción documentada a la validación de FK a usuario. |

## Cómo leer

- Si solo dispone de 10 minutos: `01` §1 y §3, y `07` §2 (decisiones pendientes).
- Para aprobar el modelo de la consultora: `02`.
- Para aprobar el riesgo operativo de la migración: `03` y `04`.
- Para aprobar el orden de ejecución: `06` §1 y `07` §1.
