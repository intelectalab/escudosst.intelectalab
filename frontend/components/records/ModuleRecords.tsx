"use client";

// Pantalla común de los módulos SST: una pestaña por cada registro del módulo, con lista, búsqueda,
// filtros y un formulario que se arma con la descripción que envía el backend (/records/{módulo}).

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ApiError } from "@/lib/api";
import { displayValue, records, statusTone, toLocalInput, type ModuleSchema, type RecordField, type RecordPage, type RecordResource, type RecordRow } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Switch } from "@/components/ui/Switch";
import { Toast } from "@/components/ui/Toast";

export function ModuleRecords({ code, readOnly }: { code: string; readOnly: boolean }) {
  const [schema, setSchema] = useState<ModuleSchema | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<string | null>(null);

  useEffect(() => {
    setSchema(null);
    setError(null);
    records
      .schema(code)
      .then((s) => {
        setSchema(s);
        const fromUrl = typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("t") : null;
        setTab(s.resources.find((r) => r.table === fromUrl)?.table ?? s.resources[0]?.table ?? null);
      })
      .catch((e: ApiError) =>
        setError(e.status === 404 ? "Este módulo se gestiona desde su propia pantalla, que se habilitará en las próximas etapas." : (e.detail ?? e.title)),
      );
  }, [code]);

  const choose = (t: string) => {
    setTab(t);
    const url = new URL(window.location.href);
    url.searchParams.set("t", t);
    window.history.replaceState(null, "", url);
  };

  if (error) return <p className="rounded-xl border border-line bg-surface p-6 text-muted">{error}</p>;
  if (!schema) return <p className="text-muted">Cargando…</p>;
  if (schema.resources.length === 0)
    return <p className="rounded-xl border border-line bg-surface p-6 text-muted">Tu rol no tiene acceso a los registros de este módulo.</p>;

  const current = schema.resources.find((r) => r.table === tab) ?? schema.resources[0];

  return (
    <div className="space-y-4">
      {schema.resources.length > 1 && (
        <div role="tablist" aria-label="Registros del módulo" className="flex gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1">
          {schema.resources.map((r) => (
            <button
              key={r.table}
              role="tab"
              aria-selected={r.table === current.table}
              onClick={() => choose(r.table)}
              className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
                r.table === current.table ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"
              }`}
            >
              {r.tab}
            </button>
          ))}
        </div>
      )}
      <ResourcePanel key={current.table} code={code} res={current} readOnly={readOnly} />
    </div>
  );
}

function ResourcePanel({ code, res, readOnly }: { code: string; res: RecordResource; readOnly: boolean }) {
  const [q, setQ] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [data, setData] = useState<RecordPage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<RecordRow | "new" | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const columns = res.fields.filter((f) => f.list);
  // Filtros: el estado y hasta dos opciones más (tipo, nivel…).
  const filterFields = useMemo(() => {
    const enums = res.fields.filter((f) => f.type === "enum");
    const status = enums.find((f) => f.name === "status");
    return [...(status ? [status] : []), ...enums.filter((f) => f !== status)].slice(0, 3);
  }, [res]);

  useEffect(() => {
    const t = setTimeout(() => {
      setQuery(q);
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [q]);

  const load = useCallback(() => {
    setError(null);
    records
      .list(code, res.table, { q: query, page, filters })
      .then(setData)
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [code, res.table, query, page, filters]);

  useEffect(load, [load]);

  const canCreate = res.can.create && !readOnly;
  const active = query !== "" || Object.values(filters).some(Boolean);

  return (
    <section className="space-y-4" aria-label={res.tab}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">{res.tab}</h2>
          <p className="text-sm text-muted">{res.title}.</p>
          {res.hybrid && <p className="text-sm text-muted">Incluye el catálogo general de EscudoSST (solo lectura) y los registros propios de tu empresa.</p>}
          {res.immutable && <p className="text-sm text-muted">Registro inalterable: una vez guardado no se edita ni se borra.</p>}
        </div>
        {canCreate && (
          <Button variant="primary" icon="plus" onClick={() => setEditing("new")}>
            Nuevo
          </Button>
        )}
      </div>

      <FilterBar
        count={data ? `${data.meta.total} registro(s)` : undefined}
        onClear={active ? () => { setQ(""); setFilters({}); } : undefined}
      >
        <SearchInput value={q} onChange={setQ} placeholder="Buscar por código, nombre o texto…" label={`Buscar en ${res.tab}`} />
        {filterFields.map((f) => (
          <FilterSelect
            key={f.name}
            label={f.label}
            value={filters[f.name] ?? ""}
            onChange={(v) => {
              setFilters((x) => ({ ...x, [f.name]: v }));
              setPage(1);
            }}
            options={[["", "Todos"], ...(f.options ?? []).map((o) => [o.value, o.label] as [string, string])]}
          />
        ))}
      </FilterBar>

      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}

      {data && data.data.length === 0 && !error && (
        <EmptyState
          icon={active ? "search" : "clipboard"}
          text={active ? "No hay registros con esos filtros." : canCreate ? "Aún no hay registros. Usa «Nuevo» para crear el primero." : "Aún no hay registros."}
          onClear={active ? () => { setQ(""); setFilters({}); } : undefined}
        />
      )}

      {data && data.data.length > 0 && (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs uppercase tracking-wide text-muted">
              <tr>
                {columns.map((f) => (
                  <th key={f.name} scope="col" className="px-4 py-3 font-semibold">
                    {f.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.data.map((row) => (
                <tr
                  key={row.id}
                  tabIndex={0}
                  onClick={() => setEditing(row)}
                  onKeyDown={(e) => e.key === "Enter" && setEditing(row)}
                  className="cursor-pointer border-b border-line last:border-0 hover:bg-brand-soft/40 focus-visible:bg-brand-soft/40 focus-visible:outline-none"
                >
                  {columns.map((f, i) => (
                    <td key={f.name} className={`px-4 py-3 ${i === 0 ? "font-medium" : ""}`}>
                      <Cell f={f} row={row} />
                      {i === 0 && res.hybrid && !row.own && <span className="ml-2 text-xs text-muted">(catálogo general)</span>}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && data.meta.pages > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Anterior
          </Button>
          <span className="text-muted">
            Página {data.meta.page} de {data.meta.pages}
          </span>
          <Button size="sm" disabled={page >= data.meta.pages} onClick={() => setPage((p) => p + 1)}>
            Siguiente
          </Button>
        </div>
      )}

      {editing && (
        <RecordForm
          code={code}
          res={res}
          row={editing === "new" ? null : editing}
          readOnly={readOnly}
          onClose={() => setEditing(null)}
          onSaved={(msg) => {
            setEditing(null);
            setToast(msg);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </section>
  );
}

function Cell({ f, row }: { f: RecordField; row: RecordRow }) {
  const v = row[f.name];
  if (f.type === "enum" && v) return <Chip tone={f.name === "status" ? statusTone(String(v)) : "na"}>{displayValue(f, row)}</Chip>;
  return <>{displayValue(f, row)}</>;
}

type Values = Record<string, unknown>;

function RecordForm({
  code,
  res,
  row,
  readOnly,
  onClose,
  onSaved,
}: {
  code: string;
  res: RecordResource;
  row: RecordRow | null;
  readOnly: boolean;
  onClose: () => void;
  onSaved: (msg: string) => void;
}) {
  const [full, setFull] = useState<RecordRow | null>(row);
  const [values, setValues] = useState<Values>(() => initial(res, null));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [detail, setDetail] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  // El detalle trae todos los campos (la lista solo los visibles).
  useEffect(() => {
    if (!row) return;
    records.get(code, res.table, row.id).then((r) => {
      setFull(r.data);
      setValues(initial(res, r.data));
    });
  }, [code, res, row]);

  const isNew = row === null;
  const foreign = !!full && res.hybrid && !full.own;
  const canEdit = !readOnly && !foreign && (isNew ? res.can.create : res.can.update);
  const editable = res.fields.filter((f) => !f.readonly && f.type !== "json");
  const computed = res.fields.filter((f) => (f.readonly || f.type === "json") && !isNew);

  const save = async () => {
    setBusy(true);
    setErrors({});
    setDetail(null);
    try {
      const body: Values = {};
      for (const f of editable) {
        const v = values[f.name];
        if (isNew && (v === "" || v === null || v === undefined)) continue;
        if (!isNew && full && same(f, v, full[f.name])) continue;
        body[f.name] = f.type === "tags" ? String(v ?? "").split(",").map((s) => s.trim()).filter(Boolean) : v === "" ? null : v;
      }
      if (isNew) await records.create(code, res.table, body);
      else await records.update(code, res.table, full!.id, body);
      onSaved(isNew ? "Registro creado." : "Cambios guardados.");
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setDetail(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    setBusy(true);
    try {
      await records.remove(code, res.table, full!.id);
      onSaved("Registro archivado.");
    } catch (e) {
      const err = e as ApiError;
      setDetail(err.detail ?? err.title);
      setConfirmDelete(false);
    } finally {
      setBusy(false);
    }
  };

  const title = isNew ? `Nuevo · ${res.tab}` : (full && (full[res.label] as string)) || res.title;

  return (
    <Modal
      title={String(title)}
      subtitle={isNew ? "Los campos con * son obligatorios." : canEdit ? "Edita y guarda los cambios." : "Solo lectura."}
      icon={isNew ? "plus" : "pen"}
      onClose={onClose}
      size="lg"
      footer={
        <div className="flex w-full flex-wrap items-center justify-between gap-2">
          <div>
            {!isNew && canEdit && res.can.delete && full && (
              confirmDelete ? (
                <span className="flex items-center gap-2 text-sm">
                  ¿Archivar este registro?
                  <Button size="sm" variant="danger" loading={busy} onClick={remove}>
                    Sí, archivar
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setConfirmDelete(false)}>
                    No
                  </Button>
                </span>
              ) : (
                <Button variant="danger-ghost" icon="trash" onClick={() => setConfirmDelete(true)}>
                  Archivar
                </Button>
              )
            )}
          </div>
          <div className="flex gap-2">
            <Button onClick={onClose}>{canEdit ? "Cancelar" : "Cerrar"}</Button>
            {canEdit && (
              <Button variant="primary" loading={busy} onClick={save} disabled={!isNew && !full}>
                {isNew ? "Crear" : "Guardar"}
              </Button>
            )}
          </div>
        </div>
      }
    >
      <div className="space-y-4">
        {foreign && <p className="rounded-lg bg-bg px-4 py-3 text-sm text-muted">Es parte del catálogo general de EscudoSST: puedes consultarlo, pero no editarlo. Crea uno propio si necesitas cambiarlo.</p>}
        {detail && (
          <p role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">
            {detail}
          </p>
        )}
        {!isNew && !full ? (
          <p className="text-muted">Cargando…</p>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            {editable.map((f) => (
              <div key={f.name} className={f.type === "longtext" || f.type === "tags" ? "sm:col-span-2" : ""}>
                <Input
                  code={code}
                  table={res.table}
                  f={f}
                  value={values[f.name]}
                  initialLabel={full ? (full[`${f.name}__label`] as string | undefined) : undefined}
                  disabled={!canEdit}
                  error={errors[f.name]?.[0]}
                  onChange={(v) => setValues((x) => ({ ...x, [f.name]: v }))}
                />
              </div>
            ))}
          </div>
        )}
        {full && computed.length > 0 && (
          <div className="grid gap-3 rounded-xl bg-bg p-4 text-sm sm:grid-cols-2">
            {computed.map((f) => (
              <div key={f.name}>
                <p className="text-xs font-semibold text-muted">{f.label}</p>
                <p>{displayValue(f, full)}</p>
              </div>
            ))}
          </div>
        )}
      </div>
    </Modal>
  );
}

function initial(res: RecordResource, row: RecordRow | null): Values {
  const out: Values = {};
  for (const f of res.fields) {
    const v = row ? row[f.name] : f.default;
    if (f.type === "datetime") out[f.name] = toLocalInput(v);
    else if (f.type === "tags") out[f.name] = Array.isArray(v) ? v.join(", ") : "";
    else if (f.type === "bool") out[f.name] = v === undefined || v === null ? false : !!v;
    else out[f.name] = v ?? "";
  }
  return out;
}

/** ¿El valor del formulario es igual al guardado? (para enviar solo lo que cambió) */
function same(f: RecordField, v: unknown, old: unknown): boolean {
  if (f.type === "datetime") return v === toLocalInput(old);
  if (f.type === "tags") return v === (Array.isArray(old) ? old.join(", ") : "");
  if (f.type === "date") return String(v ?? "") === String(old ?? "").slice(0, 10);
  if (f.type === "bool") return !!v === !!old;
  return String(v ?? "") === String(old ?? "");
}

function Input({
  code,
  table,
  f,
  value,
  initialLabel,
  disabled,
  error,
  onChange,
}: {
  code: string;
  table: string;
  f: RecordField;
  value: unknown;
  initialLabel?: string;
  disabled: boolean;
  error?: string;
  onChange: (v: unknown) => void;
}) {
  const label = `${f.label}${f.required ? " *" : ""}`;
  const common = { disabled, "aria-invalid": error ? true : undefined, className: "input" } as const;
  const str = value === null || value === undefined ? "" : String(value);

  if (f.type === "bool") {
    return (
      <div className="flex h-full items-center justify-between gap-3 rounded-lg border border-line px-4 py-3">
        <span className="text-sm font-semibold">{f.label}</span>
        <Switch checked={!!value} onChange={onChange} disabled={disabled} label={f.label} />
      </div>
    );
  }

  let control: React.ReactNode;
  switch (f.type) {
    case "enum":
      control = (
        <select {...common} value={str} onChange={(e) => onChange(e.target.value)}>
          <option value="">{f.required ? "Elige una opción" : "—"}</option>
          {f.options?.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      );
      break;
    case "ref":
      control = <RefSelect code={code} table={table} f={f} value={str} initialLabel={initialLabel} disabled={disabled} invalid={!!error} onChange={onChange} />;
      break;
    case "longtext":
      control = <textarea {...common} rows={3} value={str} onChange={(e) => onChange(e.target.value)} className="input min-h-[5rem] py-2" />;
      break;
    case "int":
    case "number":
      control = <input {...common} type="number" step={f.type === "int" ? 1 : "any"} inputMode="decimal" value={str} onChange={(e) => onChange(e.target.value)} />;
      break;
    case "date":
      control = <input {...common} type="date" value={str.slice(0, 10)} onChange={(e) => onChange(e.target.value)} />;
      break;
    case "datetime":
      control = <input {...common} type="datetime-local" value={str} onChange={(e) => onChange(e.target.value)} />;
      break;
    case "time":
      control = <input {...common} type="time" value={str.slice(0, 5)} onChange={(e) => onChange(e.target.value)} />;
      break;
    default:
      control = <input {...common} type="text" maxLength={f.max ?? (f.type === "tags" ? 2000 : 500)} value={str} onChange={(e) => onChange(e.target.value)} />;
  }

  return (
    <FormField
      label={label}
      error={error}
      hint={f.type === "tags" ? "Separa los valores con comas." : f.encrypted ? "Se guarda cifrado." : undefined}
    >
      {control}
    </FormField>
  );
}

/** Selector con búsqueda para una referencia (sede, área, trabajador…). */
function RefSelect({
  code,
  table,
  f,
  value,
  initialLabel,
  disabled,
  invalid,
  onChange,
}: {
  code: string;
  table: string;
  f: RecordField;
  value: string;
  initialLabel?: string;
  disabled: boolean;
  invalid: boolean;
  onChange: (v: unknown) => void;
}) {
  const [label, setLabel] = useState(initialLabel ?? "");
  const [text, setText] = useState(initialLabel ?? "");
  const [open, setOpen] = useState(false);
  const [opts, setOpts] = useState<{ id: string; label: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (initialLabel !== undefined) {
      setLabel(initialLabel);
      setText(initialLabel);
    } else if (value && !label) {
      records.options(code, table, f.name, { id: value }).then((r) => {
        const l = r.data[0]?.label ?? "";
        setLabel(l);
        setText(l);
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialLabel]);

  useEffect(() => {
    if (!open) {
      setLoading(true);
      return;
    }
    const t = setTimeout(() => {
      records
        .options(code, table, f.name, { q: text === label ? "" : text })
        .then((r) => setOpts(r.data))
        .finally(() => setLoading(false));
    }, 250);
    return () => clearTimeout(t);
  }, [open, text, label, code, table, f.name]);

  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) {
        setOpen(false);
        setText(label);
      }
    };
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [label]);

  const pick = (o: { id: string; label: string } | null) => {
    onChange(o ? o.id : null);
    setLabel(o?.label ?? "");
    setText(o?.label ?? "");
    setOpen(false);
  };

  return (
    <div ref={box} className="relative">
      <input
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={`${f.name}-options`}
        aria-label={f.label}
        aria-invalid={invalid || undefined}
        disabled={disabled}
        placeholder="Escribe para buscar…"
        value={text}
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setText(e.target.value);
          setOpen(true);
        }}
        onKeyDown={(e) => {
          if (e.key === "Escape") {
            setOpen(false);
            setText(label);
          }
        }}
        className="input pr-8"
      />
      {value && !disabled && (
        <button type="button" aria-label={`Quitar ${f.label}`} onClick={() => pick(null)} className="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-md text-muted hover:bg-bg">
          ×
        </button>
      )}
      {open && !disabled && (
        <ul id={`${f.name}-options`} role="listbox" className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-line bg-surface py-1 shadow-lg">
          {loading && <li className="px-3 py-2 text-sm text-muted">Buscando…</li>}
          {!loading && opts.length === 0 && <li className="px-3 py-2 text-sm text-muted">Sin resultados. Si falta, créalo primero en su módulo.</li>}
          {!loading &&
            opts.map((o) => (
              <li key={o.id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={o.id === value}
                  onClick={() => pick(o)}
                  className={`block w-full px-3 py-2 text-left text-sm hover:bg-brand-soft ${o.id === value ? "font-semibold text-brand-strong" : ""}`}
                >
                  {o.label}
                </button>
              </li>
            ))}
        </ul>
      )}
    </div>
  );
}
