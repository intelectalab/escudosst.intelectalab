"use client";

// SCR-SUS-10 · Accesos de soporte (docs/05 §4.6). Soporte pide acceso; el administrador de cuenta de la empresa lo
// autoriza desde su sistema; mientras dure, se entra solo en lectura y nunca a datos médicos.

import { useRouter } from "next/navigation";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState, PageHeader, StatCard } from "@/components/ui/Display";
import { FilterBar, SearchInput } from "@/components/ui/FilterBar";
import { ChoiceCards, FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/accounts";
import { session } from "@/lib/session";
import { DURATIONS, SUPPORT_STATUS, type SupportGrant, type SupportStatus, timeLeft } from "@/lib/support";

type Company = { id: string; name: string; ruc: string; account_name: string };
type View = "all" | "requested" | "active" | "closed";

export default function SoportePage() {
  const router = useRouter();
  const [data, setData] = useState<{ grants: SupportGrant[]; companies: Company[] } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [view, setView] = useState<View>("all");
  const [q, setQ] = useState("");
  const [creating, setCreating] = useState(false);
  const [confirm, setConfirm] = useState<{ kind: "cancel" | "revoke"; grant: SupportGrant } | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const closeToast = useCallback(() => setToast(null), []);

  const load = useCallback(() => {
    api<{ data: { grants: SupportGrant[]; companies: Company[] } }>("/internal/support-access")
      .then((r) => setData(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar los accesos."));
  }, []);
  useEffect(load, [load]);

  const filtered = useMemo(() => {
    if (!data) return [];
    const t = q.trim().toLowerCase();
    return data.grants.filter(
      (g) =>
        (view === "all" || (view === "closed" ? ["rejected", "expired", "revoked"].includes(g.status) : g.status === view)) &&
        (!t || [g.company_name, g.ruc, g.reason, g.ticket_reference ?? "", g.support_name].some((v) => v.toLowerCase().includes(t))),
    );
  }, [data, view, q]);

  async function enter(g: SupportGrant) {
    try {
      await api("/me/switch-company", { method: "POST", body: { company_id: g.company_id } });
      session.setCompanyId(g.company_id);
      router.push("/inicio");
    } catch (e) {
      setToast({ text: e instanceof ApiError ? e.message : "No se pudo entrar a la empresa.", tone: "bad" });
    }
  }

  async function act() {
    if (!confirm) return;
    try {
      await api(`/internal/support-access/${confirm.grant.id}/${confirm.kind}`, { method: "POST" });
      setToast({ text: confirm.kind === "cancel" ? "Solicitud cancelada." : "Acceso terminado: ya no puedes entrar a la empresa.", tone: "ok" });
      setConfirm(null);
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? e.message : "No se pudo completar.", tone: "bad" });
    }
  }

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!data) return <div className="mx-auto h-96 max-w-7xl animate-pulse rounded-2xl bg-line/40" aria-label="Cargando accesos" />;

  const n = (s: SupportStatus[]) => data.grants.filter((g) => s.includes(g.status)).length;
  const toggle = (v: View) => setView((cur) => (cur === v ? "all" : v));

  return (
    <div className="mx-auto w-full max-w-[1600px] space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Accesos de soporte"]]}
        icon="shield"
        title="Accesos de soporte"
        description="Entrar a una empresa solo con su permiso: solo lectura, con vencimiento y sin datos médicos."
        aside={
          <Button icon="plus" onClick={() => setCreating(true)}>
            Pedir acceso
          </Button>
        }
      />

      <section aria-label="Resumen" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Todos" value={data.grants.length} hint="Solicitudes y accesos" icon="shield" active={view === "all"} onClick={() => setView("all")} />
        <StatCard label="Esperando autorización" value={n(["requested"])} hint="El cliente aún no responde" icon="clock" tone="amber" active={view === "requested"} onClick={() => toggle("requested")} />
        <StatCard label="Activos" value={n(["active"])} hint="Puedes entrar ahora" icon="check" tone="ok" active={view === "active"} onClick={() => toggle("active")} />
        <StatCard label="Cerrados" value={n(["rejected", "expired", "revoked"])} hint="Rechazados, vencidos o terminados" icon="lock" tone="na" active={view === "closed"} onClick={() => toggle("closed")} />
      </section>

      <FilterBar count={`${filtered.length} ${filtered.length === 1 ? "acceso" : "accesos"}`} onClear={q || view !== "all" ? () => (setQ(""), setView("all")) : undefined}>
        <SearchInput label="Buscar accesos" placeholder="Empresa, RUC, motivo o ticket" value={q} onChange={setQ} />
      </FilterBar>

      {filtered.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState icon="shield" text={data.grants.length ? "Ningún acceso coincide con los filtros." : "Aún no hay solicitudes. Usa «Pedir acceso» cuando un cliente necesite ayuda."} onClear={data.grants.length ? () => (setQ(""), setView("all")) : undefined} />
        </div>
      ) : (
        <ul className="space-y-3">
          {filtered.map((g) => (
            <li key={g.id} className="rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-display font-semibold">{g.company_name}</p>
                  <p className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted">
                    <Code>RUC {g.ruc}</Code>
                    {g.ticket_reference && <span>Ticket {g.ticket_reference}</span>}
                    <span>Pedido por {g.support_name} el {formatDate(g.requested_at, true)}</span>
                  </p>
                </div>
                <Chip tone={SUPPORT_STATUS[g.status].tone} dot>
                  {SUPPORT_STATUS[g.status].label}
                </Chip>
              </div>
              <p className="mt-3 rounded-lg bg-bg px-3 py-2 text-sm">
                <span className="text-muted">Motivo:</span> {g.reason}
              </p>
              <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted">
                  {g.status === "active" && (
                    <>
                      Autorizado por <b className="text-fg">{g.authorized_name}</b> · vence {formatDate(g.expires_at, true)} ({timeLeft(g.expires_at)})
                    </>
                  )}
                  {g.status === "requested" && <>Solo lectura · {g.hours} horas desde que lo autoricen</>}
                  {g.status === "rejected" && <>El administrador de cuenta lo rechazó.</>}
                  {g.status === "expired" && <>Venció el {formatDate(g.expires_at, true)}</>}
                  {g.status === "revoked" && <>Terminado el {formatDate(g.revoked_at, true)}</>}
                </p>
                <div className="flex flex-wrap gap-2">
                  {g.status === "active" && (
                    <>
                      <Button variant="primary" size="sm" icon="eye" onClick={() => enter(g)}>
                        Entrar a la empresa
                      </Button>
                      <Button variant="danger-ghost" size="sm" icon="close" onClick={() => setConfirm({ kind: "revoke", grant: g })}>
                        Terminar acceso
                      </Button>
                    </>
                  )}
                  {g.status === "requested" && (
                    <Button variant="danger-ghost" size="sm" icon="close" onClick={() => setConfirm({ kind: "cancel", grant: g })}>
                      Cancelar solicitud
                    </Button>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {creating && (
        <RequestModal
          companies={data.companies}
          onClose={() => setCreating(false)}
          onDone={() => {
            setCreating(false);
            setToast({ text: "Solicitud enviada. Avisamos al administrador de cuenta para que la autorice.", tone: "ok" });
            load();
          }}
        />
      )}

      {confirm && (
        <Modal
          title={confirm.kind === "cancel" ? "Cancelar solicitud" : "Terminar acceso"}
          subtitle={confirm.grant.company_name}
          icon="close"
          size="sm"
          onClose={() => setConfirm(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setConfirm(null)}>
                Volver
              </Button>
              <Button variant="danger" icon="close" onClick={act}>
                {confirm.kind === "cancel" ? "Cancelar solicitud" : "Terminar ahora"}
              </Button>
            </>
          }
        >
          <p className="text-sm">
            {confirm.kind === "cancel"
              ? "El cliente ya no podrá autorizarla. Si después necesitas entrar, tendrás que pedirlo de nuevo."
              : "Dejarás de poder entrar a la empresa desde ahora, aunque aún no haya vencido. Queda en la bitácora."}
          </p>
        </Modal>
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={closeToast} />}
    </div>
  );
}

function RequestModal({ companies, onClose, onDone }: { companies: Company[]; onClose: () => void; onDone: () => void }) {
  const [q, setQ] = useState("");
  const [company, setCompany] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [ticket, setTicket] = useState("");
  const [hours, setHours] = useState<string>("8");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  const options = companies.filter((c) => !q || [c.name, c.ruc, c.account_name].some((v) => v.toLowerCase().includes(q.toLowerCase()))).slice(0, 6);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api("/internal/support-access", { method: "POST", body: { company_id: company, reason: reason.trim(), ticket_reference: ticket.trim() || null, hours: Number(hours) } });
      onDone();
    } catch (err) {
      setErrors(err instanceof ApiError ? (err.errors ?? { company_id: [err.message] }) : { company_id: ["No se pudo enviar."] });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Pedir acceso a una empresa"
      subtitle="El administrador de cuenta debe autorizarlo"
      icon="shield"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="support-request" icon="check" loading={saving} disabled={!company}>
            Enviar solicitud
          </Button>
        </>
      }
    >
      <form id="support-request" onSubmit={submit} className="space-y-5" noValidate>
        <div className="space-y-2">
          <p className="text-sm font-semibold">Empresa</p>
          <SearchInput label="Buscar empresa" placeholder="Nombre, RUC o cuenta" value={q} onChange={setQ} />
          <ChoiceCards
            label="Empresa"
            value={company}
            onChange={setCompany}
            options={options.map((c) => ({ value: c.id, title: c.name, detail: `RUC ${c.ruc} · ${c.account_name}` }))}
          />
          {errors.company_id && <p className="text-xs font-medium text-bad">● {errors.company_id[0]}</p>}
        </div>
        <FormField label="Motivo" counter={`${reason.length}/300`} hint="El cliente lo leerá para decidir. Sé específico." error={errors.reason?.[0]}>
          <textarea className="input min-h-20" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="N.° de ticket (opcional)" error={errors.ticket_reference?.[0]}>
            <input className="input" maxLength={40} value={ticket} onChange={(e) => setTicket(e.target.value)} />
          </FormField>
          <FormField label="Duración" hint="Cuenta desde que lo autoricen." error={errors.hours?.[0]}>
            <select className="input" value={hours} onChange={(e) => setHours(e.target.value)}>
              {DURATIONS.map(([h, l]) => (
                <option key={h} value={h}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
        </div>
        <p className="flex items-start gap-2 rounded-lg bg-brand-soft px-3 py-2 text-sm text-brand-strong">
          <Icon name="eye" size={16} className="mt-0.5 shrink-0" />
          Siempre en solo lectura y sin acceso a datos médicos. Todo lo que veas queda registrado y el cliente puede terminar el acceso cuando quiera.
        </p>
      </form>
    </Modal>
  );
}
