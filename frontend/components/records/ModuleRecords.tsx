"use client";

// Pantalla común de los módulos SST: una pestaña por cada registro del módulo, con lista, búsqueda,
// filtros y un formulario que se arma con la descripción que envía el backend (/records/{módulo}).

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { NO_CREATE, OneTimeSecret, PanelActions, RecordActions, TraceStrip, describeFilter, lockedReason } from "./Process";
import { JSON_EDITORS } from "./JsonEditors";
import { ModuleKpis } from "./ModuleKpis";
import { SignaturePanel } from "./SignaturePanel";
import { api, ApiError } from "@/lib/api";
import { displayValue, downloadFile, records, statusTone, toLocalInput, uploadFile, type ModuleSchema, type RecordField, type RecordPage, type RecordResource, type RecordRow } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FilterBar, FilterSelect, SearchInput } from "@/components/ui/FilterBar";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Switch } from "@/components/ui/Switch";
import { Toast } from "@/components/ui/Toast";
import { session } from "@/lib/session";
import { CHILDREN, HIDDEN, NEW_LABEL, NO_SIGN, TAB_LABEL, TAB_ORDER } from "@/lib/moduleScreens";

export function ModuleRecords({ code, readOnly }: { code: string; readOnly: boolean }) {
  const [schema, setSchema] = useState<ModuleSchema | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<string | null>(null);
  const params = useSearchParams();
  const urlTab = params.get("t");
  const urlFilters = useMemo(() => filtersFromUrl(params), [params]);
  const urlOpen = params.get("open");

  useEffect(() => {
    setSchema(null);
    setError(null);
    records
      .schema(code)
      .then(setSchema)
      .catch((e: ApiError) =>
        setError(e.status === 404 ? "Este módulo se gestiona desde su propia pantalla, que se habilitará en las próximas etapas." : (e.detail ?? e.title)),
      );
  }, [code]);

  // Pestañas: solo los registros principales (los hijos se ven dentro de su padre), en el orden del prototipo.
  const tabs = useMemo(() => {
    if (!schema) return [];
    const order = TAB_ORDER[code.toUpperCase()] ?? [];
    const pos = (t: string) => (order.includes(t) ? order.indexOf(t) : order.length + schema.resources.findIndex((r) => r.table === t));
    return schema.resources
      .filter((r) => !HIDDEN.has(r.table) && !(r.table in CHILDREN && schema.resources.some((p) => p.table === refTarget(r, CHILDREN[r.table]))))
      .sort((a, b) => pos(a.table) - pos(b.table))
      .map((r) => ({ ...r, tab: TAB_LABEL[r.table] ?? r.tab }));
  }, [schema, code]);
  const childrenOf = useCallback(
    (table: string) => (schema?.resources ?? []).filter((r) => r.table in CHILDREN && refTarget(r, CHILDREN[r.table]) === table && !HIDDEN.has(r.table)).map((r) => ({ res: r, fk: CHILDREN[r.table] })),
    [schema],
  );

  // La pestaña sigue a la URL (un enlace de otra pantalla puede cambiarla sin recargar).
  useEffect(() => {
    if (schema) setTab(tabs.find((r) => r.table === urlTab)?.table ?? tabs[0]?.table ?? null);
  }, [schema, tabs, urlTab]);

  const choose = (t: string) => {
    setTab(t);
    const url = new URL(window.location.href);
    url.search = "";
    url.searchParams.set("t", t);
    window.history.replaceState(null, "", url);
  };

  if (error) return <p className="rounded-xl border border-line bg-surface p-6 text-muted">{error}</p>;
  if (!schema) return <p className="text-muted">Cargando…</p>;
  if (tabs.length === 0)
    return <p className="rounded-xl border border-line bg-surface p-6 text-muted">Tu rol no tiene acceso a los registros de este módulo.</p>;

  // Si un enlace pide un registro hijo, se abre en la pestaña de su padre.
  const current = tabs.find((r) => r.table === tab) ?? tabs[0];

  return (
    <div className="space-y-4">
      <ModuleKpis code={code} />
      {tabs.length > 1 && (
        <div role="tablist" aria-label="Registros del módulo" className="flex gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1">
          {tabs.map((r) => (
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
      <ResourcePanel
        key={`${current.table}|${params.toString()}`}
        code={code}
        res={current}
        readOnly={readOnly}
        initialFilters={current.table === urlTab ? urlFilters : {}}
        openId={current.table === urlTab ? urlOpen : null}
        childrenOf={childrenOf}
      />
    </div>
  );
}

/** Filtros en la URL: f[campo]=valor, f[campo__lt]=today… (los usan el tablero y la trazabilidad). */
export function filtersFromUrl(params: URLSearchParams): Record<string, string> {
  const out: Record<string, string> = {};
  params.forEach((v, k) => {
    const m = k.match(/^f\[([a-z0-9_]+)\]$/);
    if (m && v) out[m[1]] = v;
  });
  return out;
}

export function ResourcePanel({
  code,
  res,
  readOnly,
  initialFilters = {},
  openId = null,
  fixed,
  nested = false,
  childrenOf,
}: {
  code: string;
  res: RecordResource;
  readOnly: boolean;
  initialFilters?: Record<string, string>;
  openId?: string | null;
  /** Valores fijos (registro hijo dentro de su padre): filtran la lista y se ponen solos al crear. */
  fixed?: Record<string, string>;
  /** Lista dentro de la ficha del padre: cabecera compacta. */
  nested?: boolean;
  childrenOf?: (table: string) => { res: RecordResource; fk: string }[];
}) {
  const [q, setQ] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const memoKey = `escudo.filters.${session.getCompanyId() ?? ""}.${res.table}`;
  const [filters, setFilters] = useState<Record<string, string>>(() => {
    if (fixed || Object.keys(initialFilters).length) return { ...initialFilters, ...(fixed ?? {}) };
    try {
      return JSON.parse(localStorage.getItem(memoKey) ?? "{}");
    } catch {
      return {};
    }
  });
  useEffect(() => {
    if (fixed) return;
    try {
      localStorage.setItem(memoKey, JSON.stringify(filters));
    } catch {
      /* sin almacenamiento: no se recuerda */
    }
  }, [filters, fixed, memoKey]);
  // Un trabajador se abre en su ficha 360° (SCR-TRB-02); los demás registros, en su ficha de la lista.
  const router = useRouter();
  const openRow = (row: RecordRow) => (code.toUpperCase() === "TRB" && res.table === "workers" ? router.push(`/m/trb/${row.id}`) : setEditing(row));
  // Selección para acciones sobre varios registros.
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulkBusy, setBulkBusy] = useState(false);
  const [data, setData] = useState<RecordPage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<RecordRow | "new" | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [secret, setSecret] = useState<{ table: string; value: string } | null>(null);

  // Un enlace puede pedir abrir un registro concreto (?open=id).
  useEffect(() => {
    if (openId) setEditing({ id: openId, own: true } as RecordRow);
  }, [openId]);

  const columns = (res.columns?.length ? res.columns.map((n) => res.fields.find((f) => f.name === n)!).filter(Boolean) : res.fields.filter((f) => f.list))
    .filter((f) => !(fixed && f.name in fixed));
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

  const canCreate = res.can.create && !readOnly && !NO_CREATE.has(res.table);
  // Filtros que no se ven en los selectores (vienen de un enlace): se muestran como chips que se pueden quitar.
  const extraFilters = Object.entries(filters).filter(
    ([k, v]) => v && !(fixed && k in fixed) && !filterFields.some((f) => f.name === k && (f.options ?? []).some((o) => o.value === v)),
  );
  const active = query !== "" || Object.entries(filters).some(([k, v]) => v && !(fixed && k in fixed));
  const clearAll = () => { setQ(""); setFilters({ ...(fixed ?? {}) }); };

  return (
    <section className="space-y-4" aria-label={res.tab} data-records={res.table} data-nested={nested ? "1" : undefined}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className={nested ? "text-base font-semibold" : "text-lg font-semibold"}>{TAB_LABEL[res.table] ?? res.tab}</h2>
          {!nested && res.hybrid && <p className="text-sm text-muted">Incluye el catálogo general de EscudoSST (solo lectura) y los registros propios de tu empresa.</p>}
          {res.immutable && <p className="text-sm text-muted">Registro inalterable: una vez guardado no se edita ni se borra.</p>}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {!readOnly && !nested && <PanelActions table={res.table} onDone={load} />}
          {!nested && data && data.meta.total > 0 && (
            <Button icon="download" onClick={() => exportCsv(code, res, query, filters)}>
              Exportar
            </Button>
          )}
          {canCreate && (
            <Button variant={nested ? "secondary" : "primary"} size={nested ? "sm" : undefined} icon="plus" onClick={() => setEditing("new")}>
              {nested ? "Agregar" : (NEW_LABEL[res.table] ?? "Nuevo")}
            </Button>
          )}
        </div>
      </div>

      {!nested && <FilterBar
        count={data ? `${data.meta.total} registro(s)` : undefined}
        onClear={active ? clearAll : undefined}
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
      </FilterBar>}

      {extraFilters.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-xl bg-brand-soft px-4 py-2 text-sm text-brand-strong" role="status">
          <span className="font-semibold">Mostrando solo:</span>
          {extraFilters.map(([k, v]) => (
            <button
              key={k}
              onClick={() => setFilters((x) => ({ ...x, [k]: "" }))}
              className="inline-flex items-center gap-1 rounded-full bg-surface px-3 py-1 text-xs font-semibold shadow-sm hover:bg-bg"
              title="Quitar este filtro"
            >
              {describeFilter(res, k, v)} <span aria-hidden="true">×</span>
            </button>
          ))}
        </div>
      )}

      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}

      {data && data.data.length === 0 && !error && (
        <EmptyState
          icon={active ? "search" : "clipboard"}
          text={active ? "No hay registros con esos filtros." : canCreate ? "Aún no hay registros. Usa «Nuevo» para crear el primero." : "Aún no hay registros."}
          onClear={active ? clearAll : undefined}
        />
      )}

      {/* Celular: tarjetas (docs/07 §3.1). */}
      {data && data.data.length > 0 && (
        <ul className="space-y-2 sm:hidden">
          {data.data.map((row) => {
            const status = columns.find((f) => f.name === "status");
            const rest = columns.filter((f, i) => i > 0 && f !== status).slice(0, 3);
            return (
              <li key={row.id}>
                <button type="button" onClick={() => openRow(row)} className="w-full rounded-xl border border-line bg-surface p-3 text-left active:bg-brand-soft/40">
                  <span className="flex items-start justify-between gap-2">
                    <b className="min-w-0 text-sm">{columns[0] ? displayValue(columns[0], row) : row.id}</b>
                    {status && row.status ? <Cell f={status} row={row} /> : null}
                  </span>
                  {rest.map((f) => (
                    <span key={f.name} className="mt-0.5 block text-xs text-muted">
                      {f.label}: <span className="text-fg">{displayValue(f, row) || "—"}</span>
                    </span>
                  ))}
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {data && data.data.length > 0 && (
        <div className="hidden space-y-2 sm:block">
          {selected.size > 0 && (
            <div className="flex flex-wrap items-center gap-2 rounded-xl bg-brand-soft px-4 py-2 text-sm text-brand-strong" role="status">
              <b>{selected.size} seleccionado(s)</b>
              <Button size="sm" icon="download" onClick={() => exportCsv(code, res, query, filters, data.data.filter((r) => selected.has(r.id)))}>Exportar seleccionados</Button>
              {res.can.delete && !readOnly && !nested && (
                <Button size="sm" variant="danger-ghost" icon="trash" loading={bulkBusy} onClick={async () => {
                  if (!window.confirm(`¿Archivar ${selected.size} registro(s)? Se quitan de la lista y quedan en la bitácora.`)) return;
                  setBulkBusy(true);
                  let ok = 0;
                  const fails: string[] = [];
                  for (const id of selected) {
                    try {
                      await records.remove(code, res.table, id);
                      ok++;
                    } catch (e) {
                      fails.push((e as ApiError).detail ?? (e as ApiError).title);
                    }
                  }
                  setBulkBusy(false);
                  setSelected(new Set());
                  setToast(fails.length ? `${ok} archivado(s). ${fails.length} no se pudieron: ${fails[0]}` : `${ok} registro(s) archivado(s).`);
                  load();
                }}>Archivar seleccionados</Button>
              )}
              <button type="button" className="ml-auto text-xs font-semibold hover:underline" onClick={() => setSelected(new Set())}>Quitar selección</button>
            </div>
          )}
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs uppercase tracking-wide text-muted">
              <tr>
                {!nested && (
                  <th scope="col" className="w-10 px-3 py-3">
                    <input type="checkbox" aria-label="Seleccionar todos" checked={data.data.length > 0 && data.data.every((r) => selected.has(r.id))}
                      onChange={(e) => setSelected(e.target.checked ? new Set(data.data.map((r) => r.id)) : new Set())} />
                  </th>
                )}
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
                  onClick={() => openRow(row)}
                  onKeyDown={(e) => e.key === "Enter" && openRow(row)}
                  className="cursor-pointer border-b border-line last:border-0 hover:bg-brand-soft/40 focus-visible:bg-brand-soft/40 focus-visible:outline-none"
                >
                  {!nested && (
                    <td className="w-10 px-3 py-3" onClick={(e) => e.stopPropagation()}>
                      <input type="checkbox" aria-label="Seleccionar" checked={selected.has(row.id)}
                        onChange={(e) => setSelected((x) => { const n = new Set(x); if (e.target.checked) n.add(row.id); else n.delete(row.id); return n; })} />
                    </td>
                  )}
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
          fixed={fixed}
          childrenOf={childrenOf}
          onClose={() => {
            setEditing(null);
            load();
          }}
          onSaved={(msg, saved) => {
            setEditing(null);
            setToast(msg);
            if (typeof saved?.secret === "string") setSecret({ table: res.table, value: saved.secret });
            load();
          }}
        />
      )}
      {secret && <OneTimeSecret table={secret.table} secret={secret.value} onClose={() => setSecret(null)} />}
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

export function RecordForm({
  code,
  res,
  row,
  readOnly,
  onClose,
  onSaved,
  hide: hideProp = [],
  fixed,
  childrenOf,
}: {
  code: string;
  res: RecordResource;
  row: RecordRow | null;
  readOnly: boolean;
  onClose: () => void;
  onSaved: (msg: string, saved?: RecordRow) => void;
  /** Campos que no se muestran en este formulario (los maneja otra pantalla). */
  hide?: string[];
  /** Valores fijos del registro hijo (el padre): se ponen solos y no se muestran. */
  fixed?: Record<string, string>;
  childrenOf?: (table: string) => { res: RecordResource; fk: string }[];
}) {
  const hide = fixed ? [...hideProp, ...Object.keys(fixed)] : hideProp;
  const [full, setFull] = useState<RecordRow | null>(row);
  const [values, setValues] = useState<Values>(() => initial(res, null));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [detail, setDetail] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const [version, setVersion] = useState(0);
  const [ftab, setFtab] = useState("resumen");

  // El detalle trae todos los campos (la lista solo los visibles). Se vuelve a leer tras una acción del proceso.
  useEffect(() => {
    if (!row) return;
    records
      .get(code, res.table, row.id)
      .then((r) => {
        setFull(r.data);
        setValues(initial(res, r.data));
      })
      .catch((e: ApiError) => setDetail(e.detail ?? e.title));
  }, [code, res, row, version]);

  const isNew = row === null;
  const foreign = !!full && res.hybrid && !full.own;
  const locked = !isNew && full ? lockedReason(res.table, full) : null;
  const canEdit = !readOnly && !foreign && !locked && (isNew ? res.can.create : res.can.update);
  const editable = res.fields.filter((f) => !f.readonly && (f.type !== "json" || isList(res.table, f)) && f.type !== "source" && !hide.includes(f.name));
  const computed = res.fields.filter((f) => (f.readonly || (f.type === "json" && !isList(res.table, f))) && !isNew && !hide.includes(f.name));

  const save = async () => {
    setBusy(true);
    setErrors({});
    setDetail(null);
    try {
      const body: Values = isNew && fixed ? { ...fixed } : {};
      for (const f of editable) {
        const v = values[f.name];
        if (isNew && (v === "" || v === null || v === undefined)) continue;
        if (!isNew && full && same(f, v, full[f.name], res.table)) continue;
        body[f.name] =
          f.type === "tags" ? String(v ?? "").split(",").map((s) => s.trim()).filter(Boolean)
          : f.type === "json" ? (JSON_EDITORS[`${res.table}.${f.name}`] ? v : String(v ?? "").split("\n").map((s) => s.trim()).filter(Boolean))
          : v === "" ? null : v;
      }
      const saved = isNew ? await records.create(code, res.table, body) : await records.update(code, res.table, full!.id, body);
      onSaved(isNew ? "Registro creado." : "Cambios guardados.", saved.data);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setDetail(err.detail ?? err.title);
      if (err.errors && Object.keys(err.errors).length) setFtab("detalle");
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
  const children = childrenOf?.(res.table) ?? [];
  const signable = !["signature_requests", "signatures"].includes(res.table) && !NO_SIGN.has(res.table);
  const fichaTabs: [string, string][] = [
    ["resumen", "Resumen"],
    ["detalle", "Detalle"],
    ...children.map(({ res: c }) => [c.table, TAB_LABEL[c.table] ?? c.tab] as [string, string]),
    ...(signable ? [["firmas", "Firmas"] as [string, string]] : []),
    ["historial", "Historial"],
  ];
  const summaryFields = (res.columns?.length ? res.columns.map((n) => res.fields.find((f) => f.name === n)).filter((f): f is RecordField => !!f) : res.fields.filter((f) => f.list))
    .filter((f) => !hide.includes(f.name));

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
        {locked && <p className="rounded-lg bg-bg px-4 py-3 text-sm text-muted">{locked}</p>}
        {foreign && <p className="rounded-lg bg-bg px-4 py-3 text-sm text-muted">Es parte del catálogo general de EscudoSST: puedes consultarlo, pero no editarlo. Crea uno propio si necesitas cambiarlo.</p>}
        {detail && (
          <p role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">
            {detail}
          </p>
        )}
        {/* Ficha (docs/07 §3.2): Resumen · Detalle · registros que le pertenecen · Firmas · Historial. */}
        {!isNew && full && (
          <div role="tablist" aria-label="Partes de la ficha" className="-mx-1 flex gap-1 overflow-x-auto border-b border-line px-1">
            {fichaTabs.map(([k, label]) => (
              <button key={k} type="button" role="tab" aria-selected={ftab === k} onClick={() => setFtab(k)}
                className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-semibold transition ${ftab === k ? "border-brand text-brand-strong" : "border-transparent text-muted hover:text-fg"}`}>
                {label}
              </button>
            ))}
          </div>
        )}
        {!isNew && !full && <p className="text-muted">Cargando…</p>}

        {full && !isNew && ftab === "resumen" && (
          <div className="space-y-4">
            <TraceStrip table={res.table} module={code} id={full.id} version={version} />
            <RecordActions code={code} res={res} row={full} readOnly={readOnly} onChanged={() => setVersion((v) => v + 1)} />
            <dl className="grid gap-3 rounded-xl border border-line p-4 text-sm sm:grid-cols-2">
              {[...summaryFields, ...computed.filter((f) => !summaryFields.includes(f))].map((f) => (
                <div key={f.name}>
                  <dt className="text-xs font-semibold text-muted">{f.label}</dt>
                  <dd>{displayValue(f, full) || "—"}</dd>
                </div>
              ))}
            </dl>
            {canEdit && <p className="text-xs text-muted">Para cambiar datos, abre la pestaña «Detalle».</p>}
          </div>
        )}

        {(isNew || ftab === "detalle") && (isNew || full) && (
          <>
            <div className="grid gap-4 sm:grid-cols-2">
              {editable.map((f) => (
                <div key={f.name} className={f.type === "longtext" || f.type === "tags" || f.type === "json" ? "sm:col-span-2" : ""}>
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
          </>
        )}

        {/* Registros que pertenecen a este (ítems, brechas, pasos, asistencias…): cada uno en su pestaña. */}
        {full && !isNew && children.map(({ res: child, fk }) =>
          ftab === child.table ? (
            <ResourcePanel key={child.table} code={code} res={child} readOnly={readOnly} fixed={{ [fk]: full.id }} nested childrenOf={childrenOf} />
          ) : null,
        )}

        {full && !isNew && ftab === "firmas" && <SignaturePanel table={res.table} id={full.id} version={version} />}
        {full && !isNew && ftab === "historial" && <RecordHistory code={code} table={res.table} id={full.id} version={version} />}
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
    else if (f.type === "json") out[f.name] = JSON_EDITORS[`${res.table}.${f.name}`] ? (v ?? []) : listText(v);
    else if (f.type === "bool") out[f.name] = v === undefined || v === null ? false : !!v;
    else out[f.name] = v ?? "";
  }
  return out;
}

/** Campos JSON que se editan como lista (uno por línea); los demás JSON los calcula el sistema. */
const LIST_JSON = new Set(["management_reviews.participants"]);
const isList = (table: string, f: RecordField) => f.type === "json" && (LIST_JSON.has(`${table}.${f.name}`) || `${table}.${f.name}` in JSON_EDITORS);
function listText(v: unknown): string {
  if (!Array.isArray(v)) return "";
  return v.map((x) => (typeof x === "string" ? x : x && typeof x === "object" ? String((x as Record<string, unknown>).name ?? JSON.stringify(x)) : String(x))).join("\n");
}

/** ¿El valor del formulario es igual al guardado? (para enviar solo lo que cambió) */
function same(f: RecordField, v: unknown, old: unknown, table = ""): boolean {
  if (f.type === "json" && JSON_EDITORS[`${table}.${f.name}`]) return JSON.stringify(v ?? []) === JSON.stringify(old ?? []);
  if (f.type === "datetime") return v === toLocalInput(old);
  if (f.type === "tags") return v === (Array.isArray(old) ? old.join(", ") : "");
  if (f.type === "json") return String(v ?? "").trim() === listText(old);
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
    case "file":
      control = <FileInput code={code} value={str} initialLabel={initialLabel} disabled={disabled} onChange={onChange} />;
      break;
    case "json":
      if (JSON_EDITORS[`${table}.${f.name}`]) {
        const Editor = JSON_EDITORS[`${table}.${f.name}`];
        control = <Editor value={value} onChange={onChange} disabled={!!disabled} />;
        break;
      }
      control = <textarea {...common} rows={4} value={str} onChange={(e) => onChange(e.target.value)} className="input min-h-[6rem] py-2" placeholder={"Gerente general\nJefe SST\nRepresentante de los trabajadores"} />;
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
      hint={f.type === "json" ? "Escribe uno por línea." : f.type === "tags" ? "Separa los valores con comas." : f.encrypted ? "Se guarda cifrado." : f.type === "file" ? "PDF, imagen, Word o Excel; hasta 15 MB." : undefined}
    >
      {control}
    </FormField>
  );
}

/** Selector con búsqueda para una referencia (sede, área, trabajador…). */
export function RefSelect({
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
    if (initialLabel != null) {
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


/** Campo de archivo: sube a la empresa y guarda el id; muestra el nombre y permite abrirlo. */
function FileInput({ code, value, initialLabel, disabled, onChange }: { code: string; value: string; initialLabel?: string; disabled: boolean; onChange: (v: unknown) => void }) {
  const [name, setName] = useState(initialLabel ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (initialLabel) setName(initialLabel);
  }, [initialLabel]);
  return (
    <div className="space-y-1">
      {value && (
        <div className="flex items-center gap-2 text-sm">
          <button type="button" className="font-semibold text-brand hover:underline" onClick={() => downloadFile(`/files/${value}`, name || "archivo").catch((e: ApiError) => setError(e.detail ?? e.title))}>
            📎 {name || "Ver archivo"}
          </button>
          {!disabled && (
            <button type="button" className="text-xs text-muted hover:text-bad" onClick={() => { onChange(null); setName(""); }}>
              Quitar
            </button>
          )}
        </div>
      )}
      {!disabled && (
        <input
          type="file"
          className="input file:mr-3 file:rounded-lg file:border-0 file:bg-bg file:px-3 file:py-1.5 file:font-semibold"
          accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"
          disabled={busy}
          onChange={async (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            setBusy(true);
            setError(null);
            try {
              const r = await uploadFile(file, code);
              onChange(r.id);
              setName(r.original_name);
            } catch (err) {
              setError((err as ApiError).detail ?? (err as ApiError).title);
            } finally {
              setBusy(false);
              e.target.value = "";
            }
          }}
        />
      )}
      {busy && <p className="text-xs text-muted">Subiendo…</p>}
      {error && <p className="text-xs text-bad">{error}</p>}
    </div>
  );
}

/** Tabla a la que apunta un campo de referencia del registro. */
export function refTarget(res: RecordResource, field: string): string | undefined {
  return res.fields.find((f) => f.name === field)?.ref;
}

/** «Exportar»: descarga la lista filtrada (hasta 2 000 filas) en CSV para Excel, con los nombres de columna en español. */
async function exportCsv(code: string, res: RecordResource, q: string, filters: Record<string, string>, only?: RecordRow[]) {
  const cols = res.fields.filter((f) => f.list || f.required);
  const rows: RecordRow[] = only ? [...only] : [];
  for (let page = 1; !only && page <= 40; page++) {
    const r = await records.list(code, res.table, { q, page, filters, exporting: page === 1 });
    rows.push(...r.data);
    if (page >= r.meta.pages) break;
  }
  const esc = (v: string) => `"${v.replace(/"/g, '""')}"`;
  const csv = [cols.map((f) => esc(f.label)).join(";"), ...rows.map((row) => cols.map((f) => esc(displayValue(f, row))).join(";"))].join("\r\n");
  const url = URL.createObjectURL(new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = `${(TAB_LABEL[res.table] ?? res.tab).replace(/[^\p{L}\p{N}]+/gu, "-")}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

type HistoryItem = { occurred_at: string; action: string; actor: string; actor_role: string | null; support: boolean; changes: { field: string; from: unknown; to: unknown }[] };
const ACTION_LABEL: Record<string, string> = { create: "Creó el registro", update: "Cambió", delete: "Archivó el registro", restore: "Restauró el registro", sign: "Firmó", approve: "Aprobó" };

/** Pestaña «Historial»: línea de tiempo de la bitácora del registro (quién cambió qué y cuándo). */
function RecordHistory({ code, table, id, version }: { code: string; table: string; id: string; version: number }) {
  const [rows, setRows] = useState<HistoryItem[] | null>(null);
  useEffect(() => {
    api<{ data: HistoryItem[] }>(`/records/${code.toUpperCase()}/${table}/${id}/history`, { withCompany: true })
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
  }, [code, table, id, version]);
  if (rows === null) return <div className="h-24 animate-pulse rounded-lg bg-line/40" />;
  if (rows.length === 0) return <p className="rounded-lg bg-bg px-4 py-3 text-sm text-muted">Aún no hay cambios registrados para este registro.</p>;
  const fmt = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d));
  const val = (v: unknown) => (v === null || v === undefined || v === "" ? "—" : String(v));
  return (
    <ol className="relative space-y-4 border-l border-line pl-5">
      {rows.map((h, i) => (
        <li key={i} className="relative">
          <span className="absolute -left-[1.6rem] top-1 h-3 w-3 rounded-full border-2 border-surface bg-brand" aria-hidden="true" />
          <p className="text-sm">
            <b>{h.actor}</b> · {ACTION_LABEL[h.action] ?? h.action}
            {h.support && <span className="ml-2 rounded bg-amber-soft px-1.5 py-0.5 text-[11px] font-semibold text-amber-ink">Sesión de soporte</span>}
          </p>
          <p className="text-xs text-muted">{fmt(h.occurred_at)}</p>
          {h.changes.length > 0 && (
            <ul className="mt-1 space-y-0.5 text-xs text-muted">
              {h.changes.map((c, k) => (
                <li key={k}>
                  <span className="font-semibold text-fg">{c.field}:</span>{" "}
                  {c.from === null && c.to === null ? "cambió" : h.action === "create" ? val(c.to) : `${val(c.from)} → ${val(c.to)}`}
                </li>
              ))}
            </ul>
          )}
        </li>
      ))}
    </ol>
  );
}
