# S1.2-C — Entrada HTTP y auditoría de plantas

## Precisión de CAT-TEC-03

**Aprobada por el responsable del proyecto el 12 de noviembre de 2026:** exclusivamente para `POST /api/plants`, cualquier parámetro query o archivo adjunto se rechaza con HTTP 422 `validation_failed`, incluso si el body JSON es válido. La validación y normalización de atributos utilizan únicamente el body JSON. Esta precisión no se extiende a otros endpoints ni modifica las decisiones originales.

## Auditoría y privacidad

**Estado histórico de S1.2-C:** `plant.created` registraba `old_values` vacío y `new_values` con `name`, `code`, `address`, `description` y `status`. `address` y `description` son texto libre: la redacción del logger identifica claves sensibles, no secretos escritos dentro de esos valores.

La propuesta de minimizar `new_values` a `name`, `code` y `status`, excluyendo `address` y `description`, fue **aprobada posteriormente como CAT-TEC-08 en S1.4**. Desde S1.4 ese es el criterio vigente; no se pretende detectar secretos mediante heurísticas ni se alteran registros históricos. Evaluar necesidades de trazabilidad, acceso y retención por separado.
