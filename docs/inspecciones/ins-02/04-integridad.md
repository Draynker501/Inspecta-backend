# INS-02 — integridad y migración

Migración: `database/migrations/2026_11_19_000001_enforce_inspection_answer_tenant_version_foreign_keys.php`.

## Esquema resultante

`inspection_answers` conserva sus columnas y añade al final:

| Columna | Tipo | Nulabilidad |
| --- | --- | --- |
| `checklist_version_id` | CHAR(36) | NOT NULL |
| `checklist_section_id` | CHAR(36) | NOT NULL |

El orden histórico se preserva: SQLite
`id, inspection_id, question_id, answer, observation, created_at, updated_at,
organization_id`; MariaDB `id, organization_id, inspection_id, question_id,
answer, observation, created_at, updated_at`. Las columnas nuevas se añaden al
final en ambos motores.

## Índices padre

| Tabla | Índice | Columnas |
| --- | --- | --- |
| `inspections` | `ins_answers_insp_version_org_unique` | `(id, checklist_version_id, organization_id)` único |
| `checklist_sections` | `ins_answers_sect_version_org_unique` | `(id, checklist_version_id, organization_id)` único |
| `questions` | `ins_answers_quest_sect_org_unique` | `(id, checklist_section_id, organization_id)` único |

## FK compuestas

| Nombre | Columnas | Referencia | DELETE |
| --- | --- | --- | --- |
| `ins_answers_insp_version_org_fk` | `(inspection_id, checklist_version_id, organization_id)` | `inspections(id, checklist_version_id, organization_id)` | CASCADE |
| `ins_answers_sect_version_org_fk` | `(checklist_section_id, checklist_version_id, organization_id)` | `checklist_sections(id, checklist_version_id, organization_id)` | RESTRICT |
| `ins_answers_quest_sect_org_fk` | `(question_id, checklist_section_id, organization_id)` | `questions(id, checklist_section_id, organization_id)` | RESTRICT |

Todas usan `ON UPDATE CASCADE`. En MariaDB se añaden además los índices hijos
`ins_answers_insp_version_org_idx`, `ins_answers_sect_version_org_idx` e
`ins_answers_quest_sect_org_idx`.

Se preservan:

- el unique histórico `(inspection_id, question_id)`;
- la FK simple a `organizations` con `RESTRICT` en borrado y `CASCADE` en
  actualización;
- la FK entrante `evidences.inspection_answer_id` con `RESTRICT`;
- los índices ajenos a INS-02, incluidas sus definiciones SQLite.

## Preflight

Antes de cualquier DDL se ejecuta `InspectionAnswerPreflight::assertValid()`.
Detecta respuestas cuya inspección, pregunta o sección no existen, pertenecen a
otra organización o a una versión distinta. **No repara datos**: aborta y deja
el esquema y las filas intactos. Un fallo exige recuperación supervisada.

## Backfill

El backfill es determinista: toma la versión de la inspección y la sección de
la pregunta, uniendo por organización. Después verifica que ninguna fila quede
con valores nulos y solo entonces aplica `NOT NULL`.

## Estados parciales

El estado esperado se valida antes y después del cambio: columnas, tipos,
nulabilidad, índices, FK y reglas referenciales. Un esquema intermedio
(columna suelta, índice faltante, FK alterada, tabla temporal, vista o trigger
en SQLite) se rechaza sin continuar. No se reintenta automáticamente ni se
eliminan objetos ajenos.

## Motores

- **SQLite**: exige `foreign_keys` activo y ausencia de transacción externa;
  reconstruye la tabla conservando índices ajenos, valida
  `foreign_key_check` e `integrity_check`, restaura `PRAGMA foreign_keys` y
  ensaya `up/down/up` con datos y evidencias.
- **MariaDB 11.8**: exige InnoDB, `foreign_key_checks` activo y esquema
  identificable; crea índices padre e hijos, sustituye las FK simples por las
  compuestas en un único `ALTER TABLE` atómico (sin ventana sin FK) y ensaya
  `up/down/up` con datos, evidencias e índices ajenos.

La reversión restaura las FK simples históricas y elimina las columnas e
índices propios. La migración no se ejecuta en producción en este corte.
