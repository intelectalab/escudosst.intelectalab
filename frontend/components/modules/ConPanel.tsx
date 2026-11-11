"use client";

// Panel de la consultora (M-CON SCR-CON-01..03, docs/07 §5.1; prototipo m-diferenciadores.js R.CON): indicadores de
// todas las empresas cliente, tabla ordenada por riesgo (clic = entrar a esa empresa), próximas visitas, alertas
// críticas por empresa, «Invitar empresa» con sus invitaciones y «Configurar marca blanca».
// Cada empresa conserva su propio plan: el panel solo agrega indicadores ya calculados por empresa.

import { useCallback, useEffect, useMemo, useState } from "react";
import { WhiteLabelPanel } from "@/components/addons/WhiteLabel";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import { Bar, Card, Chip, Dot, Kpi, toneOf, type Tone } from "./Summary";
import { ConsultantMatrix } from "./ConMatrix";

type Company = {
  company_id: string; name: string; ruc: string; role: string; is_active: boolean; sector: string | null; workers: number;
  plan: { code: string; name: string; status: string } | null; sst_model: string | null; compliance: number | null; alerts: number; critical: number; consultants: string[];
};
type Panel = {
  consultancy: { name: string; is_admin: boolean; home_company_id: string | null };
  kpis: { companies: number; workers: number; compliance: number | null; alerts: number };
  companies: Company[];
  consultants: { id: string; name: string }[];
  agenda: { company_id: string; company: string; kind: string; module: string; text: string; date: string }[];
  critical: { company_id: string; company: string; tone: "bad" | "amber"; count: number; text: string; module: string }[];
  agenda_days: number;
};
type Invitation = { id: string; ruc: string; legal_name: string | null; company_name: string | null; invited_email: string | null; suggested_plan: string | null; status: string; sent_at: string; expires_at: string };

const STATUS: Record<string, [string, Tone]> = {
  sent: ["Enviada", "warn"], accepted: ["Aceptada", "ok"], expired: ["Vencida", "na"], cancelled: ["Anulada", "na"], revoked: ["Revocada", "na"], declined: ["Rechazada", "bad"],
};
const fmtDate = (d: string) => new Date(d.length === 10 ? `${d}T12:00:00` : d).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric" });
const msgOf = (e: ApiError) => Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title;

export function ConsultancyPanel({ readOnly = false }: { readOnly?: boolean }) {
  const [data, setData] = useState<Panel | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [plan, setPlan] = useState("");
  const [consultant, setConsultant] = useState("");
  const [modal, setModal] = useState<"invite" | "brand" | null>(null);
  const [entering, setEntering] = useState<string | null>(null);
  const [invites, setInvites] = useState<Invitation[]>([]);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Panel }>("/con/panel", { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
    api<{ data: Invitation[] }>("/con/invitations", { withCompany: true }).then((r) => setInvites(r.data)).catch(() => setInvites([]));
  }, []);
  useEffect(load, [load]);

  const rows = useMemo(
    () => (data?.companies ?? []).filter((c) => (!plan || c.plan?.code === plan) && (!consultant || c.consultants.includes(consultant))),
    [data, plan, consultant],
  );

  const enter = async (c: Company) => {
    setEntering(c.company_id);
    try {
      await api("/me/switch-company", { method: "POST", body: { company_id: c.company_id } });
      session.setCompanyId(c.company_id);
      // Recarga completa: menú, módulos y marca de la empresa elegida.
      window.location.assign("/inicio");
    } catch (e) {
      setEntering(null);
      setToast(e instanceof ApiError ? (e.detail ?? e.title) : "No pudimos entrar a la empresa.");
    }
  };

  const revoke = (id: string) =>
    api(`/con/invitations/${id}/revoke`, { method: "POST", withCompany: true }).then(load).catch((e: ApiError) => setToast(msgOf(e)));

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (!data) return <div className="h-72 animate-pulse rounded-xl bg-surface" />;

  const k = data.kpis;
  const plans = Array.from(new Map(data.companies.filter((c) => c.plan).map((c) => [c.plan!.code, c.plan!.name])).entries());
  const home = data.consultancy.home_company_id;
  const atHome = home !== null && session.getCompanyId() === home;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted">Panel multi-cliente de <b className="text-fg">{data.consultancy.name}</b>. Cada empresa conserva su propio plan.</p>
        <div className="flex flex-wrap gap-2">
          {data.consultancy.is_admin && (
            <Button icon="palette" onClick={() => (atHome ? setModal("brand") : setToast("La marca blanca se configura desde la empresa de tu consultora: cámbiate a ella desde el selector de empresa."))}>
              Configurar marca blanca
            </Button>
          )}
          {!readOnly && <Button variant="primary" icon="plus" onClick={() => setModal("invite")}>Invitar empresa</Button>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Empresas gestionadas" value={String(k.companies)} />
        <Kpi label="Trabajadores en total" value={k.workers.toLocaleString("es-PE")} color="text-fg" />
        <Kpi label="Cumplimiento promedio" value={k.compliance == null ? "—" : `${k.compliance}%`}
          color={k.compliance == null ? "text-muted" : toneOf(k.compliance) === "ok" ? "text-ok" : toneOf(k.compliance) === "warn" ? "text-amber-ink" : "text-bad"} sub="Autodiagnóstico SUNAFIL" />
        <Kpi label="Alertas abiertas" value={String(k.alerts)} color={k.alerts > 0 ? "text-bad" : "text-ok"} />
      </div>

      <Card
        title={`Empresas cliente de ${data.consultancy.name}`}
        action={
          <div className="flex flex-wrap items-center gap-2">
            <select className="filter-control !w-auto" aria-label="Filtrar por plan" value={plan} onChange={(e) => setPlan(e.target.value)}>
              <option value="">Todos los planes</option>
              {plans.map(([code, name]) => <option key={code} value={code}>{name}</option>)}
            </select>
            {data.consultants.length > 1 && (
              <select className="filter-control !w-auto" aria-label="Filtrar por consultor" value={consultant} onChange={(e) => setConsultant(e.target.value)}>
                <option value="">Todos los consultores</option>
                {data.consultants.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            )}
          </div>
        }
      >
        {data.companies.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted">
            <Icon name="building" size={28} />
            <p>Aún no tienes empresas cliente con asignación vigente.{!readOnly && " Invita a la primera con «Invitar empresa»."}</p>
          </div>
        ) : (
          <>
            <p className="mb-2 text-xs text-muted">Toca una fila para entrar a esa empresa. Ordenadas por riesgo.</p>
            <div className="-mx-4 overflow-x-auto">
              <table className="w-full min-w-[760px] text-left text-sm">
                <thead className="border-y border-line bg-bg text-xs font-semibold text-muted">
                  <tr>{["Empresa", "Sector", "Trabajadores", "Plan", "Figura SST", "Cumplimiento", "Alertas"].map((h) => <th key={h} className="whitespace-nowrap px-4 py-2.5">{h}</th>)}</tr>
                </thead>
                <tbody>
                  {rows.map((c) => (
                    <tr key={c.company_id} tabIndex={0} role="link" aria-label={`Entrar a ${c.name}`}
                      onClick={() => enter(c)} onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && (e.preventDefault(), enter(c))}
                      className="cursor-pointer border-b border-line last:border-0 hover:bg-bg focus-visible:bg-bg focus-visible:outline-none">
                      <td className="px-4 py-3">
                        <p className="font-semibold">{c.name}{entering === c.company_id && <span className="ml-2 text-xs font-normal text-muted">Entrando…</span>}{c.is_active && <span className="ml-2 text-xs font-normal text-muted">(activa)</span>}</p>
                        <p className="text-xs text-muted">RUC {c.ruc}</p>
                      </td>
                      <td className="px-4 py-3">{c.sector ?? "—"}</td>
                      <td className="px-4 py-3 tabular-nums">{c.workers.toLocaleString("es-PE")}</td>
                      <td className="px-4 py-3">{c.plan ? <span className="rounded border border-line bg-bg px-1.5 py-0.5 text-xs font-semibold">{c.plan.name}</span> : "—"}</td>
                      <td className="px-4 py-3">{c.sst_model === "committee" ? "Comité SST" : c.sst_model === "supervisor" ? "Supervisor SST" : "—"}</td>
                      <td className="px-4 py-3">
                        {c.compliance == null ? <span className="text-muted">Sin datos</span> : (
                          <div className="min-w-[120px]"><p className="text-right text-xs font-bold">{c.compliance}%</p><Bar pct={c.compliance} /></div>
                        )}
                      </td>
                      <td className="px-4 py-3"><Chip tone={c.critical > 0 ? "bad" : c.alerts > 0 ? "warn" : "ok"}>{c.alerts} {c.alerts === 1 ? "alerta" : "alertas"}</Chip></td>
                    </tr>
                  ))}
                  {rows.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-muted">Ninguna empresa con esos filtros.</td></tr>}
                </tbody>
              </table>
            </div>
          </>
        )}
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Próximas visitas">
          {data.agenda.length === 0 ? (
            <p className="text-sm text-muted">Sin inspecciones, reuniones del comité ni capacitaciones programadas en los próximos {data.agenda_days} días.</p>
          ) : (
            <ul className="divide-y divide-line">
              {data.agenda.map((a, i) => (
                <li key={i} className="flex items-start gap-3 py-2.5">
                  <span className="mt-0.5 text-brand"><Icon name="calendar" size={17} /></span>
                  <div className="min-w-0">
                    <p className="font-semibold">{a.company}</p>
                    <p className="text-sm text-muted">{a.kind}: {a.text} · {fmtDate(a.date)}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Alertas críticas por empresa">
          {data.critical.length === 0 ? (
            <p className="text-sm text-muted">Ninguna alerta abierta en tus empresas cliente.</p>
          ) : (
            <ul className="divide-y divide-line">
              {data.critical.map((a, i) => (
                <li key={i} className="flex items-start gap-3 py-2.5">
                  <span className="mt-1.5"><Dot tone={a.tone === "bad" ? "bad" : "warn"} /></span>
                  <div className="min-w-0">
                    <p className="font-semibold">{a.company}</p>
                    <p className="text-sm text-muted">{a.count} {a.text}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <ConsultantMatrix key={data.kpis.companies} readOnly={readOnly} />

      {invites.length > 0 && (
        <Card title="Invitaciones a empresas">
          <ul className="divide-y divide-line">
            {invites.map((v) => {
              const [label, tone] = STATUS[v.status] ?? [v.status, "na" as Tone];
              return (
                <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                  <div className="min-w-0">
                    <p className="font-semibold">{v.company_name ?? v.legal_name} <span className="text-xs font-normal text-muted">RUC {v.ruc}</span></p>
                    <p className="text-xs text-muted">
                      {v.invited_email} · enviada el {fmtDate(v.sent_at)}{v.status === "sent" && ` · vence el ${fmtDate(v.expires_at)}`}{v.suggested_plan && ` · plan sugerido ${v.suggested_plan}`}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Chip tone={tone}>{label}</Chip>
                    {v.status === "sent" && !readOnly && <Button size="sm" variant="danger-ghost" onClick={() => revoke(v.id)}>Anular</Button>}
                  </div>
                </li>
              );
            })}
          </ul>
        </Card>
      )}

      {modal === "invite" && <InviteModal onClose={() => setModal(null)} onSent={(m) => { setModal(null); setToast(m); load(); }} />}
      {modal === "brand" && (
        <Modal title="Configurar marca blanca" subtitle="Logo, colores y nombre que ven tus empresas cliente" icon="palette" size="lg" onClose={() => setModal(null)}>
          <WhiteLabelPanel bare />
        </Modal>
      )}
      {toast && (
        <div role="status" className="fixed inset-x-4 bottom-4 z-[60] mx-auto flex max-w-md items-center justify-between gap-3 rounded-xl bg-fg px-4 py-3 text-sm text-white shadow-lg">
          <span>{toast}</span>
          <button type="button" className="font-semibold" onClick={() => setToast(null)}>Cerrar</button>
        </div>
      )}
    </div>
  );
}

function InviteModal({ onClose, onSent }: { onClose: () => void; onSent: (msg: string) => void }) {
  const [f, setF] = useState({ ruc: "", legal_name: "", admin_email: "", suggested_plan: "P" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const send = async () => {
    setSaving(true);
    setErrors({});
    setError(null);
    try {
      const r = await api<{ data: { company_exists: boolean } }>("/con/invitations", { method: "POST", withCompany: true, body: f });
      onSent(r.data.company_exists
        ? "Invitación enviada: la empresa ya usa EscudoSST y su administrador debe aceptar la vinculación."
        : "Invitación enviada: la empresa elige y contrata su plan, y luego acepta la vinculación.");
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        if (!e.errors) setError(e.detail ?? e.title);
      } else setError("No pudimos enviar la invitación.");
      setSaving(false);
    }
  };
  const err = (k: string) => errors[k]?.[0];
  return (
    <Modal title="Invitar empresa cliente" subtitle="Le enviamos un correo al administrador de la empresa" icon="mail" onClose={onClose}
      footer={<><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={saving} onClick={send} disabled={f.ruc.length !== 11 || !f.legal_name.trim() || !f.admin_email.trim()}>Enviar invitación</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="RUC" error={err("ruc")}>
          <input className="input" inputMode="numeric" maxLength={11} value={f.ruc} onChange={(e) => setF({ ...f, ruc: e.target.value.replace(/\D/g, "") })} placeholder="20610325891" />
        </FormField>
        <FormField label="Razón social" error={err("legal_name")}>
          <input className="input" maxLength={160} value={f.legal_name} onChange={(e) => setF({ ...f, legal_name: e.target.value })} />
        </FormField>
        <FormField label="Correo del administrador" error={err("admin_email")}>
          <input className="input" type="email" maxLength={160} value={f.admin_email} onChange={(e) => setF({ ...f, admin_email: e.target.value })} />
        </FormField>
        <FormField label="Plan sugerido" hint="La empresa elige y contrata su plan." error={err("suggested_plan")}>
          <select className="input" value={f.suggested_plan} onChange={(e) => setF({ ...f, suggested_plan: e.target.value })}>
            <option value="E">Esencial</option>
            <option value="P">Profesional</option>
            <option value="C">Corporativo</option>
          </select>
        </FormField>
      </div>
      <p className="mt-4 rounded-lg bg-bg px-4 py-3 text-sm text-muted">
        Si la empresa ya usa EscudoSST, su administrador debe aceptar la vinculación y puede quitarla cuando quiera. Al aceptar, entras a la empresa como consultor asignado.
      </p>
      {error && <p className="mt-3 text-sm text-bad">{error}</p>}
    </Modal>
  );
}
