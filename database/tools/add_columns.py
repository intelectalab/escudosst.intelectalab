"""Agrega columnas nuevas (de una migración) al catálogo del modelo y sus etiquetas en español, para que build_registry.py
las publique en el registro de pantallas. Uso desde otro script:

    from add_columns import add
    add('corrective_actions', [('original_due_on', 'date', 'Fecha meta original'), ...])
    add('event_notifications', [], values={'status': ['pending', 'sent', 'overdue']}, value_labels={'overdue': 'Vencida'})

Cada columna: (nombre, tipo, etiqueta[, valores permitidos]). Tipos como en model.json: text, integer, numeric, boolean, date,
timestamptz, uuid, jsonb. Es idempotente: no duplica columnas ni etiquetas.
"""
import json
import os

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
MODEL = os.path.join(ROOT, 'database', 'catalogs', 'model.json')
LABELS = os.path.join(ROOT, 'database', 'catalogs', 'labels_es.json')


def add(table, columns, values=None, value_labels=None, refs=None):
    model = json.load(open(MODEL, encoding='utf8'))
    labels = json.load(open(LABELS, encoding='utf8'))
    t = next(x for x in model if x['table'] == table)
    names = {c['name'] for c in t['columns']}
    for col in columns:
        name, typ, label = col[0], col[1], col[2]
        allowed = col[3] if len(col) > 3 else None
        if name not in names:
            # Antes de las columnas estándar del final (created_at…), para que el orden del formulario sea natural.
            pos = next((i for i, c in enumerate(t['columns']) if c['name'] in ('created_at', 'idempotency_key')), len(t['columns']))
            t['columns'].insert(pos, {'name': name, 'type': typ, 'not_null': False, 'default': None, 'values': allowed,
                                      'ref': (refs or {}).get(name), 'encrypted': False, 'generated': None, 'comment': None})
        labels['columns'].setdefault(name, label)
    for name, allowed in (values or {}).items():
        c = next(c for c in t['columns'] if c['name'] == name)
        c['values'] = allowed
    for k, v in (value_labels or {}).items():
        labels['values'].setdefault(k, v)
    json.dump(model, open(MODEL, 'w', encoding='utf8'), ensure_ascii=False, indent=1)
    json.dump(labels, open(LABELS, 'w', encoding='utf8'), ensure_ascii=False, indent=1)
