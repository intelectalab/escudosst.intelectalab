"use client";

// Sitio web › Calculadora de multas (SCR-WEB-41): los valores que usa la calculadora pública (UIT, tablas de multas por
// tamaño de empresa y descuento por subsanación) y los cálculos que hace la gente. Los valores son parámetros legales:
// cada cambio crea una versión con fecha de vigencia y queda «a confirmar» hasta que un especialista lo confirme.

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Version = { id: string; value: string; valid_from: string; valid_to: string | null; to_confirm: boolean; confirmed_by: string | null; legal_reference: string | null };
type Param = { key: string; current: Version | null; history: Version[] };
type Sev = { min: number; max: number };
type Row = { workers_from: number | string; workers_to: number | string | null; minor: Sev; serious: Sev; very_serious: Sev };
type Data = {
  params: Record<"uit" | "micro" | "small" | "non_mype" | "discount", Param>;
  stats: { total: number; with_lead: number; last_30: number };
  recent: { created_at: string; company_size: string; affected_workers: number; severity: string; result_min_pen: string; result_max_pen: string; full_name: string | null; company_name: string | null }[];
};

const TABLES = [
  ["micro", "Microempresa"],
  ["small", "Pequeña empresa"],
  ["non_mype", "No MYPE (mediana o grande)"],
] as const;
const SEV = { minor: "Leve", serious: "Grave", very_serious: "Muy grave" } as const;
const SIZE = { micro: "Micro", small: "Pequeña", non_mype: "No MYPE" } as Record<string, string>;
const fmt = (d: string) => new Date(d.length === 10 ? d + "T12:00" : d).toLocaleDateString("es-PE");
const rowsOf = (p: Param) => {
  try {
    return (JSON.parse(p.current?.value ?? "{}").rows ?? []) as Row[];
  } catch {
    return [] as Row[];
  }
};

export default function CalculadoraPanelPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [d, setD] = useState<Data | null>(null);
  const [editing, setEditing] = useState<keyof Data["params"] | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Data }>("/internal/site/fine-calculator").then((r) => setD(r.data));
  }, []);
  useEffect(load, [load]);

  if (!d) return <div className="h-64 animate-pulse rounded-2xl bg-line/40" />;
  const ready = !!d.params.uit.current && TABLES.some(([k]) => rowsOf(d.params[k]).length > 0);

  const status = (p: Param) =>
    !p.current ? <Chip tone="bad">Sin valor vigente</Chip> : p.current.to_confirm ? <Chip tone="amber">A confirmar</Chip> : <Chip tone="ok">Confirmado</Chip>;

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Calculadora de multas"]]}
        icon="tag"
        title="Calculadora de multas SUNAFIL"
        description="Los valores que usa la calculadora pública y los cálculos que hace la gente."
        aside={
          <a href="/herramientas/calculadora-multas" target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold shadow-sm hover:border-brand">
            <Icon name="globe" size={15} /> Ver la calculadora
          </a>
        }
      />

      <section className={`flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm ${ready ? "border-ok/30 bg-ok-soft/60 text-ok" : "border-amber-ink/30 bg-amber-soft/70 text-amber-ink"}`}>
        <Icon name={ready ? "check" : "clock"} size={18} className="mt-0.5 shrink-0" />
        <p>
          {ready ? (
            <>
              <b>La calculadora está funcionando.</b> Mientras los valores estén «a confirmar», el sitio lo muestra así.
            </>
          ) : (
            <>
              <b>La calculadora pública dice «en preparación»</b> porque faltan las tablas de multas. Un especialista debe cargarlas desde el D.S. 019-2006-TR (art. 48) y sus modificatorias: para cada tamaño de empresa, por tramos de trabajadores afectados, el mínimo y máximo en UIT de cada gravedad.
            </>
          )}
        </p>
      </section>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label="Cálculos hechos" value={d.stats.total} icon="grid" tone="brand" hint={`${d.stats.last_30} en los últimos 30 días`} />
        <StatCard label="Dejaron su correo" value={d.stats.with_lead} icon="people" tone="ok" hint="Están en Interesados" />
        <StatCard label="UIT vigente" value={d.params.uit.current ? `S/ ${Number(d.params.uit.current.value).toLocaleString("es-PE")}` : "–"} icon="tag" tone="amber" hint={d.params.uit.current ? `Desde ${fmt(d.params.uit.current.valid_from)}` : "Sin cargar"} />
      </div>

      <div className="grid grid-cols-1 items-start gap-5 2xl:grid-cols-[minmax(0,1fr)_26rem]">
        <section className="space-y-3">
          <h2 className="font-display text-lg font-semibold">Valores de la calculadora</h2>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {(
              [
                ["uit", "Valor de la UIT", "Lo fija el MEF cada año."],
                ["discount", "Descuento por subsanar", "Porcentaje de reducción si la empresa corrige a tiempo (Ley 28806, art. 40)."],
              ] as const
            ).map(([k, t, h]) => {
              const p = d.params[k];
              return (
                <div key={k} className="rounded-2xl border border-line bg-surface p-4 shadow-sm">
                  <div className="flex items-start justify-between gap-2">
                    <span>
                      <b className="block text-sm">{t}</b>
                      <span className="text-xs text-muted">{h}</span>
                    </span>
                    {status(p)}
                  </div>
                  <p className="mt-2 font-display text-2xl">{p.current ? (p.current.value === "por_definir" ? "Por definir" : k === "uit" ? `S/ ${Number(p.current.value).toLocaleString("es-PE")}` : `${p.current.value} %`) : "–"}</p>
                  {p.current && <p className="text-xs text-muted">Vigente desde {fmt(p.current.valid_from)}</p>}
                  {owner && (
                    <Button size="sm" variant="soft" className="mt-2" icon="pen" onClick={() => setEditing(k)}>
                      Cambiar
                    </Button>
                  )}
                </div>
              );
            })}
          </div>
          {TABLES.map(([k, t]) => {
            const p = d.params[k];
            const rows = rowsOf(p);
            return (
              <div key={k} className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                  <span className="flex items-center gap-2">
                    <b className="text-sm">Tabla de multas · {t}</b>
                    {rows.length === 0 ? <Chip tone="bad">Vacía</Chip> : status(p)}
                  </span>
                  {owner && (
                    <Button size="sm" variant="soft" icon="pen" onClick={() => setEditing(k)}>
                      {rows.length ? "Cambiar" : "Cargar tabla"}
                    </Button>
                  )}
                </div>
                {rows.length === 0 ? (
                  <p className="px-4 py-4 text-sm text-muted">Sin tramos cargados.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-bg/70 text-left text-xs text-muted">
                        <tr>
                          <th className="px-4 py-2 font-semibold">Trabajadores afectados</th>
                          {Object.values(SEV).map((s) => (
                            <th key={s} className="px-4 py-2 font-semibold">
                              {s} (UIT)
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-line">
                        {rows.map((r, i) => (
                          <tr key={i}>
                            <td className="px-4 py-2">{r.workers_to ? `${r.workers_from} a ${r.workers_to}` : `${r.workers_from} o más`}</td>
                            {(Object.keys(SEV) as (keyof typeof SEV)[]).map((s) => (
                              <td key={s} className="px-4 py-2 tabular-nums">
                                {r[s].min} – {r[s].max}
                              </td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                {p.current && <p className="border-t border-line px-4 py-2 text-xs text-muted">Vigente desde {fmt(p.current.valid_from)}{p.current.legal_reference ? ` · ${p.current.legal_reference}` : ""}</p>}
              </div>
            );
          })}
          <p className="text-xs text-muted">
            Cada cambio queda como versión nueva con su fecha (los cálculos anteriores conservan los valores que usaron). Para marcar un valor como confirmado por el especialista, usa{" "}
            <Link href="/plataforma/catalogos" className="font-semibold text-brand hover:underline">
              Catálogos globales
            </Link>
            .
          </p>
        </section>

        <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          <h2 className="border-b border-line px-5 py-3 font-display font-semibold">Últimos cálculos</h2>
          {d.recent.length === 0 ? (
            <p className="p-5 text-sm text-muted">Todavía nadie usó la calculadora.</p>
          ) : (
            <ul className="max-h-[36rem] divide-y divide-line overflow-y-auto">
              {d.recent.map((c, i) => (
                <li key={i} className="px-5 py-2.5 text-sm">
                  <span className="flex justify-between gap-2">
                    <b>
                      {SIZE[c.company_size]} · {SEV[c.severity as keyof typeof SEV]} · {c.affected_workers} trab.
                    </b>
                    <span className="text-xs text-muted">{fmt(c.created_at)}</span>
                  </span>
                  <span className="block text-xs text-muted">
                    S/ {Number(c.result_min_pen).toLocaleString("es-PE")} a S/ {Number(c.result_max_pen).toLocaleString("es-PE")}
                    {c.full_name && ` · ${c.full_name}${c.company_name ? ` (${c.company_name})` : ""}`}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      {editing && (
        <EditModal
          k={editing}
          p={d.params[editing]}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null);
            setToast({ text: "Guardado como versión nueva. Queda «a confirmar» hasta que el especialista lo confirme.", tone: "ok" });
            load();
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function EditModal({ k, p, onClose, onDone }: { k: keyof Data["params"]; p: Param; onClose: () => void; onDone: () => void }) {
  const isTable = k === "micro" || k === "small" || k === "non_mype";
  const today = new Date().toISOString().slice(0, 10);
  const minDate = p.current && p.current.valid_from >= today ? new Date(new Date(p.current.valid_from + "T12:00").getTime() + 86400000).toISOString().slice(0, 10) : today;
  const empty: Row = { workers_from: "", workers_to: "", minor: { min: 0, max: 0 }, serious: { min: 0, max: 0 }, very_serious: { min: 0, max: 0 } };
  const [rows, setRows] = useState<Row[]>(isTable ? (rowsOf(p).length ? rowsOf(p) : [{ ...empty, workers_from: 1 }]) : []);
  const [value, setValue] = useState(!isTable && p.current && p.current.value !== "por_definir" ? p.current.value : "");
  const [validFrom, setValidFrom] = useState(minDate);
  const [reference, setReference] = useState(p.current?.legal_reference ?? "");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const setCell = (i: number, patch: Partial<Row>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api(`/internal/site/fine-calculator/${k}`, {
        method: "POST",
        body: { value: isTable ? { rows: rows.map((r) => ({ ...r, workers_to: r.workers_to === "" ? null : r.workers_to })) } : value, valid_from: validFrom, legal_reference: reference || null, reason },
      });
      onDone();
    } catch (x) {
      setError(x instanceof ApiError ? (Object.values(x.errors ?? {})[0]?.[0] ?? x.detail ?? x.message) : "No se pudo guardar.");
      setSaving(false);
    }
  }

  const title = { uit: "Valor de la UIT", discount: "Descuento por subsanar", micro: "Tabla · Microempresa", small: "Tabla · Pequeña empresa", non_mype: "Tabla · No MYPE" }[k];

  return (
    <Modal
      title={title}
      icon="pen"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="fine-form" icon="check" loading={saving}>
            Guardar versión
          </Button>
        </>
      }
    >
      <form id="fine-form" onSubmit={save} className="space-y-4" noValidate>
        {isTable ? (
          <div className="space-y-2">
            <p className="text-xs text-muted">Un tramo por fila. Deja «hasta» vacío en el último tramo («o más»). Montos en UIT, con punto decimal.</p>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="text-left text-muted">
                  <tr>
                    <th className="px-1 py-1">Desde</th>
                    <th className="px-1 py-1">Hasta</th>
                    {Object.values(SEV).map((s) => (
                      <th key={s} className="px-1 py-1" colSpan={2}>
                        {s} (mín. – máx.)
                      </th>
                    ))}
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r, i) => (
                    <tr key={i}>
                      <td className="p-1">
                        <input className="input !h-9 w-16 !px-2 text-xs" inputMode="numeric" value={r.workers_from} onChange={(e) => setCell(i, { workers_from: e.target.value.replace(/\D/g, "") })} />
                      </td>
                      <td className="p-1">
                        <input className="input !h-9 w-16 !px-2 text-xs" inputMode="numeric" value={r.workers_to ?? ""} onChange={(e) => setCell(i, { workers_to: e.target.value.replace(/\D/g, "") })} />
                      </td>
                      {(Object.keys(SEV) as (keyof typeof SEV)[]).flatMap((s) =>
                        (["min", "max"] as const).map((m) => (
                          <td key={s + m} className="p-1">
                            <input
                              className="input !h-9 w-16 !px-2 text-xs"
                              inputMode="decimal"
                              value={r[s][m]}
                              onChange={(e) => setCell(i, { [s]: { ...r[s], [m]: e.target.value.replace(/[^\d.]/g, "") } } as Partial<Row>)}
                            />
                          </td>
                        )),
                      )}
                      <td className="p-1">
                        <button type="button" aria-label="Quitar tramo" onClick={() => setRows(rows.filter((_, j) => j !== i))} className="grid h-9 w-9 place-items-center rounded-lg text-bad hover:bg-bad-soft">
                          <Icon name="close" size={14} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Button size="sm" variant="ghost" icon="plus" onClick={() => setRows([...rows, empty])}>
              Agregar tramo
            </Button>
          </div>
        ) : (
          <FormField label={k === "uit" ? "Valor de la UIT en soles" : "Porcentaje de descuento"}>
            <input className="input tabular-nums sm:w-48" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value.replace(/[^\d.]/g, ""))} placeholder={k === "uit" ? "5500" : "50"} />
          </FormField>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Vigente desde" hint="La versión actual se cierra el día anterior.">
            <input className="input" type="date" min={minDate} value={validFrom} onChange={(e) => setValidFrom(e.target.value)} />
          </FormField>
          <FormField label="Norma de referencia">
            <input className="input" maxLength={200} value={reference} onChange={(e) => setReference(e.target.value)} />
          </FormField>
        </div>
        <FormField label="Motivo del cambio" hint="Por ejemplo: «D.S. que fija la UIT 2027» o «Tabla revisada por el abogado».">
          <input className="input" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
        {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      </form>
    </Modal>
  );
}
