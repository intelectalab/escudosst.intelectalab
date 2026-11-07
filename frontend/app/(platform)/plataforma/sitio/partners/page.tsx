"use client";

// Sitio web › Partners y referidos (SCR-WEB-46): solicitudes de /partners, aprobar (genera el código) o rechazar con
// motivo, regla de recompensa (por definir hasta que la configures) y referidos de cada partner. Solo el Dueño decide.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Reward = { type?: string; value?: string; condition?: string | null };
type App = {
  id: string;
  partner_type: string;
  company_name: string | null;
  ruc: string | null;
  website: string | null;
  message: string | null;
  status: "pending" | "approved" | "rejected";
  created_at: string;
  reviewed_at: string | null;
  rejection_reason: string | null;
  notes: string | null;
  full_name: string;
  email: string;
  phone: string | null;
  marketing_consent: boolean;
  reviewed_by_name: string | null;
  code_id: string | null;
  code: string | null;
  code_active: boolean | null;
  reward_rule_json: Reward | null;
  referrals: number;
  customers: number;
};
type Ref = { id: string; referral_code_id: string; status: "registered" | "trial" | "customer" | "cancelled"; created_at: string; converted_at: string | null; cancelled_reason: string | null; name: string | null };

const STATUS = { pending: ["Por revisar", "amber"], approved: ["Aprobado", "ok"], rejected: ["Rechazado", "na"] } as const;
const REF_STATUS = { registered: ["Registrado", "na"], trial: ["En prueba", "brand"], customer: ["Cliente", "ok"], cancelled: ["Cancelado", "bad"] } as const;
const date = (d: string) => new Date(d).toLocaleDateString("es-PE", { day: "numeric", month: "short", year: "numeric", timeZone: "America/Lima" });
const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.");

export default function PartnersPanelPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [data, setData] = useState<{ applications: App[]; referrals: Ref[] } | null>(null);
  const [types, setTypes] = useState<Record<string, string>>({});
  const [rewards, setRewards] = useState<Record<string, string>>({});
  const [filter, setFilter] = useState<"all" | "pending" | "approved" | "rejected">("all");
  const [open, setOpen] = useState<App | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: { applications: App[]; referrals: Ref[] }; types: Record<string, string>; rewards: Record<string, string> }>("/internal/site/partners").then((r) => {
      setData(r.data);
      setTypes(r.types);
      setRewards(r.rewards);
      setOpen((o) => (o ? (r.data.applications.find((a) => a.id === o.id) ?? null) : null));
    });
  }, []);
  useEffect(load, [load]);

  const apps = data?.applications ?? [];
  const refs = data?.referrals ?? [];
  const list = apps.filter((a) => filter === "all" || a.status === filter);
  const toggle = (f: typeof filter) => setFilter((x) => (x === f ? "all" : f));
  const rewardText = (r: Reward | null) => (r?.type ? `${rewards[r.type] ?? r.type}: ${r.value}` : "Por definir");

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Partners y referidos"]]}
        icon="building"
        title="Partners y referidos"
        description="Consultoras y profesionales que postulan en /partners para recomendar EscudoSST. Al aprobarlos reciben un código; cada empresa que se registra con él queda como su referido."
        aside={
          <a href="/partners" target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-semibold hover:bg-bg">
            <Icon name="globe" size={16} /> Ver la página
          </a>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Por revisar" value={data ? apps.filter((a) => a.status === "pending").length : "–"} icon="clock" tone="amber" hint="Solicitudes nuevas" active={filter === "pending"} onClick={() => toggle("pending")} />
        <StatCard label="Partners activos" value={data ? apps.filter((a) => a.status === "approved" && a.code_active).length : "–"} icon="building" tone="ok" hint="Con código vigente" active={filter === "approved"} onClick={() => toggle("approved")} />
        <StatCard label="Referidos" value={data ? refs.length : "–"} icon="people" tone="brand" hint={`${refs.filter((r) => r.status === "trial").length} en prueba`} />
        <StatCard label="Clientes referidos" value={data ? refs.filter((r) => r.status === "customer").length : "–"} icon="star" tone="na" hint="Ya contrataron" />
      </div>

      <div className="rounded-2xl border border-amber/30 bg-amber-soft/60 p-4 text-sm text-amber-ink">
        <b>La recompensa está «por definir» hasta que la configures.</b> Se configura en cada partner ya aprobado: abre su tarjeta y pulsa <b>«Código y recompensa»</b> (descuento, comisión, meses sin costo u otra). El sistema no paga nada: solo deja constancia de cada referido y de cuándo pasó a cliente.
      </div>

      {data === null ? (
        <div className="h-48 animate-pulse rounded-2xl bg-line/40" />
      ) : list.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-line bg-surface p-10 text-center">
          <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-brand-soft text-brand-strong">
            <Icon name="building" size={22} />
          </span>
          <p className="mt-3 font-semibold">{apps.length ? "Ninguna en este filtro" : "Todavía no hay solicitudes"}</p>
          <p className="mt-1 text-sm text-muted">Aparecen aquí cuando alguien llena el formulario de /partners.</p>
        </div>
      ) : (
        <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
          {list.map((a) => (
            <li key={a.id} className="min-w-0">
              <button onClick={() => setOpen(a)} className="flex h-full w-full flex-col rounded-2xl border border-line bg-surface p-4 text-left shadow-sm transition hover:border-brand/40 hover:shadow-md">
                <span className="flex flex-wrap gap-1.5">
                  <Chip tone={STATUS[a.status][1]} dot>
                    {STATUS[a.status][0]}
                  </Chip>
                  <Chip tone="brand">{types[a.partner_type] ?? a.partner_type}</Chip>
                  {a.code && a.code_active === false && <Chip tone="bad">Código desactivado</Chip>}
                </span>
                <b className="mt-2 block font-display text-[15px] leading-snug">{a.company_name ?? a.full_name}</b>
                <span className="block truncate text-xs text-muted">
                  {a.full_name} · {a.email}
                </span>
                {a.code ? (
                  <span className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <span className="rounded-md bg-bg px-2 py-1 font-mono font-bold text-fg">{a.code}</span>
                    <span className="text-muted">
                      {a.referrals} referido{a.referrals === 1 ? "" : "s"} · {a.customers} cliente{a.customers === 1 ? "" : "s"}
                    </span>
                  </span>
                ) : (
                  <span className="mt-3 text-xs text-muted">Recibida el {date(a.created_at)}</span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}

      {open && (
        <PartnerModal
          a={open}
          types={types}
          rewards={rewards}
          rewardText={rewardText}
          refs={refs.filter((r) => r.referral_code_id === open.code_id)}
          owner={owner}
          onClose={() => setOpen(null)}
          onDone={(t) => {
            setToast({ text: t, tone: "ok" });
            load();
          }}
          onError={(t) => setToast({ text: t, tone: "bad" })}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function PartnerModal({
  a,
  types,
  rewards,
  rewardText,
  refs,
  owner,
  onClose,
  onDone,
  onError,
}: {
  a: App;
  types: Record<string, string>;
  rewards: Record<string, string>;
  rewardText: (r: Reward | null) => string;
  refs: Ref[];
  owner: boolean;
  onClose: () => void;
  onDone: (t: string) => void;
  onError: (t: string) => void;
}) {
  const [mode, setMode] = useState<"view" | "approve" | "reject" | "reward">("view");
  const [code, setCode] = useState("");
  const [reason, setReason] = useState("");
  const [notes, setNotes] = useState(a.notes ?? "");
  const [rw, setRw] = useState({ active: a.code_active ?? true, type: a.reward_rule_json?.type ?? "", value: a.reward_rule_json?.value ?? "", condition: a.reward_rule_json?.condition ?? "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function review(e: FormEvent, approve: boolean) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const r = await api<{ data: { referral_code: string | null } }>(`/internal/site/partners/${a.id}/review`, { method: "POST", body: { approve, code: code.trim() || null, reason: reason.trim() || null, notes: notes.trim() || null } });
      onDone(approve ? `Aprobado. Código ${r.data.referral_code} enviado por correo.` : "Solicitud rechazada. Le enviamos el motivo por correo.");
      setMode("view");
    } catch (x) {
      if (x instanceof ApiError && x.errors) setErrors(x.errors);
      else onError(errText(x));
    }
    setSaving(false);
  }

  async function saveReward(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(`/internal/site/partners/codes/${a.code_id}`, { method: "PATCH", body: { is_active: rw.active, reward_type: rw.type || null, reward_value: rw.type ? rw.value : null, reward_condition: rw.type ? rw.condition || null : null } });
      onDone("Código guardado.");
      setMode("view");
    } catch (x) {
      if (x instanceof ApiError && x.errors) setErrors(x.errors);
      else onError(errText(x));
    }
    setSaving(false);
  }

  async function setRefStatus(r: Ref, status: Ref["status"]) {
    const reason = status === "cancelled" ? window.prompt("Motivo de la cancelación (opcional)") : null;
    try {
      await api(`/internal/site/partners/referrals/${r.id}`, { method: "PATCH", body: { status, reason } });
      onDone("Estado del referido actualizado.");
    } catch (x) {
      onError(errText(x));
    }
  }

  const link = typeof window !== "undefined" && a.code ? `${window.location.origin}/prueba-gratis?ref=${a.code}` : "";
  const row = (k: string, v: React.ReactNode) =>
    v ? (
      <div className="grid grid-cols-[120px_minmax(0,1fr)] gap-2 py-1.5 text-sm">
        <span className="text-muted">{k}</span>
        <span className="min-w-0 break-words">{v}</span>
      </div>
    ) : null;

  return (
    <Modal
      title={a.company_name ?? a.full_name}
      subtitle={types[a.partner_type]}
      icon="building"
      size="lg"
      onClose={onClose}
      footer={
        mode === "view" ? (
          <>
            {owner && a.status === "pending" && (
              <>
                <Button className="sm:mr-auto" onClick={() => setMode("reject")}>
                  Rechazar
                </Button>
                <Button variant="primary" icon="check" onClick={() => setMode("approve")}>
                  Aprobar
                </Button>
              </>
            )}
            {owner && a.code_id && (
              <Button variant="primary" icon="pen" onClick={() => setMode("reward")}>
                Código y recompensa
              </Button>
            )}
            <Button onClick={onClose}>Cerrar</Button>
          </>
        ) : (
          <>
            <Button onClick={() => setMode("view")}>Volver</Button>
            <Button variant="primary" type="submit" form="partner-form" icon="check" loading={saving}>
              {mode === "approve" ? "Aprobar y enviar código" : mode === "reject" ? "Rechazar y avisar" : "Guardar"}
            </Button>
          </>
        )
      }
    >
      {mode === "view" && (
        <div className="space-y-4">
          <span className="flex flex-wrap gap-1.5">
            <Chip tone={STATUS[a.status][1]} dot>
              {STATUS[a.status][0]}
            </Chip>
            <Chip tone={a.marketing_consent ? "ok" : "na"}>{a.marketing_consent ? "Acepta publicidad" : "Sin permiso de publicidad"}</Chip>
          </span>
          <div className="divide-y divide-line rounded-xl border border-line px-3">
            {row("Contacto", a.full_name)}
            {row("Correo", <a href={`mailto:${a.email}`} className="text-brand hover:underline">{a.email}</a>)}
            {row("Celular", a.phone)}
            {row("RUC", a.ruc)}
            {row("Web", a.website && <a href={a.website} target="_blank" rel="noopener noreferrer" className="text-brand hover:underline">{a.website}</a>)}
            {row("Recibida", date(a.created_at))}
            {row("Revisada", a.reviewed_at && `${date(a.reviewed_at)}${a.reviewed_by_name ? ` · ${a.reviewed_by_name}` : ""}`)}
            {row("Motivo", a.rejection_reason)}
            {row("Notas", a.notes)}
          </div>
          {a.message && <p className="whitespace-pre-line rounded-xl bg-bg p-3 text-sm">{a.message}</p>}

          {a.code && (
            <div className="rounded-xl border border-brand/20 bg-brand-soft/50 p-4">
              <div className="flex flex-wrap items-center gap-2">
                <span className="rounded-lg bg-white px-3 py-1.5 font-mono text-lg font-bold">{a.code}</span>
                <Chip tone={a.code_active ? "ok" : "bad"}>{a.code_active ? "Activo" : "Desactivado"}</Chip>
              </div>
              <p className="mt-2 break-all text-xs text-muted">Enlace: {link}</p>
              <p className="mt-1 text-sm">
                <b>Recompensa:</b> {rewardText(a.reward_rule_json)}
                {a.reward_rule_json?.condition && <span className="text-muted"> · {a.reward_rule_json.condition}</span>}
              </p>
            </div>
          )}

          {a.code && (
            <div>
              <p className="text-sm font-semibold">Referidos ({refs.length})</p>
              {refs.length === 0 ? (
                <p className="mt-1 text-sm text-muted">Aún nadie se registró con este código.</p>
              ) : (
                <ul className="mt-2 divide-y divide-line rounded-xl border border-line">
                  {refs.map((r) => (
                    <li key={r.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                      <span className="min-w-0 flex-1">
                        <b className="block truncate">{r.name ?? "Empresa"}</b>
                        <span className="text-xs text-muted">
                          Desde el {date(r.created_at)}
                          {r.converted_at && ` · cliente el ${date(r.converted_at)}`}
                          {r.cancelled_reason && ` · ${r.cancelled_reason}`}
                        </span>
                      </span>
                      {owner ? (
                        <select className="input h-9 w-auto py-1 text-xs" value={r.status} onChange={(e) => setRefStatus(r, e.target.value as Ref["status"])} aria-label="Estado del referido">
                          {Object.entries(REF_STATUS).map(([k, [l]]) => (
                            <option key={k} value={k}>
                              {l}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <Chip tone={REF_STATUS[r.status][1]}>{REF_STATUS[r.status][0]}</Chip>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>
      )}

      {(mode === "approve" || mode === "reject") && (
        <form id="partner-form" onSubmit={(e) => review(e, mode === "approve")} className="space-y-4" noValidate>
          {mode === "approve" ? (
            <>
              <p className="rounded-xl bg-ok-soft p-3 text-sm text-ok">Se crea su código de referido y se le envía por correo junto con su enlace personal.</p>
              <FormField label="Código (opcional)" error={errors.code?.[0]} hint="Si lo dejas vacío se genera uno, por ejemplo ESC-4F2A9C1B. Solo letras, números y guiones.">
                <input className="input font-mono uppercase" maxLength={32} value={code} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, ""))} placeholder="CONSULTORA-ROSA" autoFocus />
              </FormField>
            </>
          ) : (
            <FormField label="Motivo" error={errors.reason?.[0]} hint="Se le envía a la persona por correo. Sé breve y amable.">
              <textarea className="input min-h-24" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus />
            </FormField>
          )}
          <FormField label="Notas internas (opcional)" hint="Solo las ve el equipo.">
            <textarea className="input min-h-16" maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>
        </form>
      )}

      {mode === "reward" && (
        <form id="partner-form" onSubmit={saveReward} className="space-y-4" noValidate>
          <SwitchRow label="Código activo" hint="Si lo desactivas, los nuevos registros con este código ya no cuentan como referidos." checked={rw.active} onChange={(v) => setRw({ ...rw, active: v })} />
          <FormField label="Tipo de recompensa" error={errors.reward_type?.[0]}>
            <select className="input" value={rw.type} onChange={(e) => setRw({ ...rw, type: e.target.value })}>
              <option value="">Por definir</option>
              {Object.entries(rewards).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          {rw.type && (
            <>
              <FormField label="Valor" error={errors.reward_value?.[0]} hint="Ej.: 10 %, S/ 100 o 1 mes.">
                <input className="input" maxLength={60} value={rw.value} onChange={(e) => setRw({ ...rw, value: e.target.value })} />
              </FormField>
              <FormField label="Condición (opcional)" error={errors.reward_condition?.[0]} hint="Ej.: cuando el referido pague su primer año.">
                <input className="input" maxLength={300} value={rw.condition} onChange={(e) => setRw({ ...rw, condition: e.target.value })} />
              </FormField>
            </>
          )}
        </form>
      )}
    </Modal>
  );
}
