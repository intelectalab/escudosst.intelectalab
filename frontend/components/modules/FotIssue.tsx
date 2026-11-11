"use client";

// Fotocheck (M-FOT, SCR-FOT-02/03; prototipo m-personas.js R.FOT): indicadores (vigentes, por vencer, no habilitados,
// verificaciones del mes) y la tarjeta «Emitir fotocheck»: se elige al trabajador, se ven los requisitos del tipo con
// Cumple / Por vencer / No cumple (misma evaluación que la emisión) y la vista previa de la credencial con la banda
// HABILITADO / POR VENCER / NO HABILITADO. Nunca se muestran diagnósticos: solo si la aptitud está vigente.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { records, recordsHref, type RecordField } from "@/lib/records";
import { RefSelect } from "@/components/records/ModuleRecords";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Card, Chip, Dot, Kpi, type Tone } from "./Summary";

type Summary = { kpis: { active: number; expiring: number; not_enabled: number; verifications_month: number }; types: { id: string; name: string; applies_to: string }[] };
type Req = { type: string; description: string; blocking: boolean; status: "ok" | "warn" | "bad"; detail: string };
type Evaluation = {
  worker: { id: string; name: string; position: string | null; document_last4: string | null };
  company: string;
  type: { id: string; name: string; validity_months: number | null };
  requirements: Req[];
  overall: "ok" | "warn" | "bad";
  credential: { id: string; credential_number: string; status: string; valid_from: string | null; expires_on: string | null } | null;
};

const LABEL: Record<Tone, string> = { ok: "Cumple", warn: "Por vencer", bad: "No cumple", na: "—" };
const BAND: Record<"ok" | "warn" | "bad", [string, string]> = {
  ok: ["HABILITADO", "bg-ok text-white"],
  warn: ["POR VENCER", "bg-[#F4B90F] text-[#2A1C00]"],
  bad: ["NO HABILITADO", "bg-bad text-white"],
};
const WORKER_FIELD = { name: "worker_id", type: "ref", ref: "workers", label: "Trabajador" } as RecordField;
const d = (v: unknown) => (v ? String(v).slice(0, 10).split("-").reverse().join("/") : "—");
const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((x) => x[0]?.toUpperCase()).join("");

export function FotIssue({ readOnly }: { readOnly: boolean }) {
  const [sum, setSum] = useState<Summary | null>(null);
  const [worker, setWorker] = useState("");
  const [type, setType] = useState("");
  const [ev, setEv] = useState<Evaluation | null>(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ tone: Tone; text: string } | null>(null);

  const loadSummary = useCallback(() => {
    api<{ data: Summary }>("/fot/summary", { withCompany: true })
      .then((r) => {
        setSum(r.data);
        setType((t) => t || (r.data.types.find((x) => x.applies_to !== "contractor_workers")?.id ?? ""));
      })
      .catch(() => setSum({ kpis: { active: 0, expiring: 0, not_enabled: 0, verifications_month: 0 }, types: [] }));
  }, []);
  useEffect(loadSummary, [loadSummary]);

  const evaluate = useCallback(() => {
    if (!worker) return;
    setLoading(true);
    const qs = new URLSearchParams({ worker_id: worker, ...(type ? { credential_type_id: type } : {}) });
    api<{ data: Evaluation }>(`/fot/evaluate?${qs}`, { withCompany: true })
      .then((r) => setEv(r.data))
      .catch((e: ApiError) => {
        setEv(null);
        setMsg({ tone: "bad", text: e.detail ?? e.title });
      })
      .finally(() => setLoading(false));
  }, [worker, type]);
  useEffect(() => {
    setMsg(null);
    evaluate();
  }, [evaluate]);

  const issue = async () => {
    if (!ev) return;
    setBusy(true);
    setMsg(null);
    try {
      const r = await records.create("FOT", "credentials", { credential_type_id: ev.type.id, worker_id: ev.worker.id });
      setMsg({ tone: r.data.status === "active" ? "ok" : "warn", text: `Fotocheck ${String(r.data.credential_number ?? "")} emitido${r.data.status === "active" ? "" : " (queda bloqueado hasta cumplir los requisitos)"}.` });
      evaluate();
      loadSummary();
    } catch (e) {
      const ae = e as ApiError;
      setMsg({ tone: "bad", text: Object.values(ae.errors ?? {})[0]?.[0] ?? ae.detail ?? ae.title });
    } finally {
      setBusy(false);
    }
  };

  const k = sum?.kpis;
  const cred = ev?.credential && ev.credential.status !== "revoked" ? ev.credential : null;
  const [band, bandCls] = BAND[ev?.overall ?? "bad"];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Fotochecks vigentes" value={k ? String(k.active) : "—"} color="text-ok" href={recordsHref("FOT", "credentials", { filters: { status: "active" } })} />
        <Kpi label="Por vencer (30 días)" value={k ? String(k.expiring) : "—"} color="text-amber-ink" href={recordsHref("FOT", "credentials", { filters: { status: "active" } })} />
        <Kpi label="No habilitados" value={k ? String(k.not_enabled) : "—"} color="text-bad" href={recordsHref("FOT", "credentials", { filters: { status: "blocked,expired" } })} />
        <Kpi label="Verificaciones este mes" value={k ? String(k.verifications_month) : "—"} sub="En portería y campo" />
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <Card title="Emitir fotocheck">
          <div className="mb-3 grid gap-3 sm:grid-cols-2">
            <label className="text-xs font-semibold text-muted">
              Trabajador
              <div className="mt-1 font-normal">
                <RefSelect code="FOT" table="credentials" f={WORKER_FIELD} value={worker} disabled={busy} invalid={false} onChange={(v) => setWorker(String(v ?? ""))} />
              </div>
            </label>
            <label className="text-xs font-semibold text-muted">
              Tipo de fotocheck
              <select className="input mt-1 font-normal" value={type} onChange={(e) => setType(e.target.value)}>
                {(sum?.types ?? []).map((t) => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </label>
          </div>
          {!worker ? (
            <p className="text-sm text-muted">Elige a un trabajador para revisar si cumple los requisitos del fotocheck.</p>
          ) : loading && !ev ? (
            <div className="h-32 animate-pulse rounded-lg bg-bg" />
          ) : ev ? (
            <>
              <p className="mb-2 text-sm text-muted">
                Para emitirlo deben cumplirse los requisitos del tipo «{ev.type.name}»{ev.worker.position ? ` para el puesto «${ev.worker.position}»` : ""}.
              </p>
              {ev.requirements.length === 0 ? (
                <p className="rounded-lg bg-bg px-3 py-2 text-sm text-muted">Este tipo no tiene requisitos configurados. Agrégalos en «Tipos de credencial».</p>
              ) : (
                <ul className="divide-y divide-line">
                  {ev.requirements.map((r, i) => (
                    <li key={`${r.type}-${i}`} className="flex items-center gap-3 py-2.5">
                      <Dot tone={r.status} />
                      <span className="min-w-0 flex-1">
                        <b className="block text-sm">{r.description}</b>
                        <span className="block text-xs text-muted">
                          {r.detail}
                          {r.blocking ? "" : " · no bloqueante"}
                        </span>
                      </span>
                      <Chip tone={r.status}>{LABEL[r.status]}</Chip>
                    </li>
                  ))}
                </ul>
              )}
              <p className={`mt-3 rounded-lg px-3 py-2 text-sm ${ev.overall === "ok" ? "bg-ok-soft text-ok" : ev.overall === "warn" ? "bg-amber-soft text-amber-ink" : "bg-bad-soft text-bad"}`}>
                {ev.overall === "ok"
                  ? "Cumple todos los requisitos."
                  : ev.overall === "warn"
                    ? "Se puede emitir; el fotocheck mostrará la alerta hasta que se renueve el requisito."
                    : "No se puede emitir: hay requisitos bloqueantes que no se cumplen."}
              </p>
              <div className="mt-3 flex flex-wrap gap-2">
                {!readOnly && !cred && (
                  <Button variant="primary" loading={busy} disabled={ev.overall === "bad"} onClick={issue}>Emitir fotocheck</Button>
                )}
                {cred && (
                  <>
                    <a href={`/imprimir/fotocheck/${cred.id}`} target="_blank" rel="noreferrer" className="inline-flex h-10 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white">
                      <Icon name="idcard" size={16} /> Imprimir fotocheck
                    </a>
                    <Link href={recordsHref("FOT", "credentials", { open: cred.id })} className="inline-flex h-10 items-center rounded-lg border border-line px-4 text-sm font-semibold hover:border-brand">
                      Ver ficha ({cred.credential_number})
                    </Link>
                  </>
                )}
              </div>
            </>
          ) : null}
          {msg && <p role="status" className={`mt-2 text-sm ${msg.tone === "ok" ? "text-ok" : msg.tone === "warn" ? "text-amber-ink" : "text-bad"}`}>{msg.text}</p>}
        </Card>

        <div className="flex flex-col items-center gap-2">
          <div className="w-full max-w-[300px] overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" aria-label="Vista previa del fotocheck">
            <div className="bg-side px-4 py-2 text-center text-xs font-semibold text-white">{ev?.company ?? "Empresa"}</div>
            <div className="flex flex-col items-center gap-2 px-4 py-4 text-center">
              <span className="grid h-20 w-20 place-items-center rounded-full bg-brand-soft font-display text-2xl font-bold text-brand-strong" aria-hidden="true">
                {ev ? initials(ev.worker.name) : <Icon name="user" size={30} />}
              </span>
              <b className="font-display text-lg leading-tight">{ev?.worker.name ?? "Nombre del trabajador"}</b>
              <span className="text-sm text-muted">{ev?.worker.position ?? "Puesto"}</span>
              {ev?.worker.document_last4 && <span className="text-xs text-muted">DNI ••••{ev.worker.document_last4}</span>}
              <span className="mt-1 grid h-20 w-20 place-items-center rounded-lg border border-dashed border-line text-muted" title="El QR se genera al emitir">
                <Icon name="grid" size={34} />
              </span>
              <span className="text-[11px] text-muted">
                {cred ? `${cred.credential_number} · vence ${d(cred.expires_on)}` : ev?.type.validity_months ? `Vigencia ${ev.type.validity_months} meses` : ""}
              </span>
            </div>
            <div className={`py-2 text-center text-sm font-bold tracking-wide ${ev ? bandCls : "bg-na-soft text-muted"}`}>{ev ? band : "SIN EVALUAR"}</div>
          </div>
          <p className="max-w-[300px] text-center text-[11px] text-muted">El QR solo lleva un código seguro. Quien lo escanea ve nombre, foto, puesto y si está habilitado; nunca diagnósticos.</p>
        </div>
      </div>
    </div>
  );
}
