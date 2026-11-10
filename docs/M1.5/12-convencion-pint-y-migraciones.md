# Convención de Pint y migraciones (P5.5c)

## Pint bloqueante
- `vendor/bin/pint --test` debe pasar en verde sobre todo el repositorio antes de
  cada commit y en CI (workflow `.github/workflows/style.yml`, job "Pint", bloqueante).
- Ejecutar `vendor/bin/pint` (sin `--test`) para aplicar el formato automáticamente.

## Migraciones excluidas
- El directorio `database/migrations` está excluido de Pint de forma permanente
  mediante `pint.json` (`"exclude": ["database/migrations"]`).
- Motivo: las migraciones son historial inmutable; reformatearlas puede alterar
  su contenido y complicar la revisión de cambios. No se modifican manualmente ni
  por Pint.

## Cómo ejecutar
```bash
vendor/bin/pint --test   # verificar
vendor/bin/pint          # aplicar formato
```

## Alcance
- Preset: `laravel`.
- Los archivos nuevos deben pasar Pint antes de incluirse en un commit.
- Reactivar el formateo de migraciones requeriría una decisión explícita y la
  actualización de esta convención.
