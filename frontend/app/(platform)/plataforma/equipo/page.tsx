"use client";

// Equipo de la plataforma (docs/05 §4): quién entra al panel y con qué rol. Solo el Dueño invita, cambia roles y
// desactiva. La persona invitada recibe un correo para crear su contraseña y luego configura su verificación en dos pasos.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Member = { id: string; full_name: string; email: string; platform_role: "owner" | "support"; status: "invited" | "active" | "locked" | "disabled"; mfa_enabled: boolean; last_login_at: string | null; invite_pending: boolean };

const ROLE_INFO = {
  owner: { label: "Dueño", tone: "bg-side text-white", can: ["Todo lo de Soporte", "Aprueba y publica (casos, páginas, políticas)", "Exporta (interesados, reclamos)", "Administra el equipo"] },
  support: { label: "Soporte", tone: "bg-brand-soft text-brand-strong", can: ["Atiende solicitudes, interesados y reclamos", "Prepara casos, páginas y testimonios", "Ve cuentas, métricas y bitácora", "No publica, no exporta, no administra el equipo"] },
} as const;

const initials = (n: string) =>
  n
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();

export default function EquipoPage() {
  const me = usePlatformUser();
  const [rows, setRows] = useState<Member[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [inviting, setInviting] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: Member[] }>("/internal/team")
      .then((r) => setRows(r.data))
      .catch((e) => {
        if (e instanceof ApiError && e.status === 403) setForbidden(true);
        setRows([]);
      });
  }, []);
  useEffect(load, [load]);

  async function run(key: string, fn: () => Promise<unknown>, ok: string) {
    setBusy(key);
    try {
      await fn();
      setToast({ text: ok, tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  const status = (m: Member) =>
    m.status === "disabled" ? <Chip tone="na">Desactivado</Chip> : m.status === "invited" ? <Chip tone="amber">{m.invite_pending ? "Invitación enviada" : "Invitación vencida"}</Chip> : m.status === "locked" ? <Chip tone="bad">Bloqueado</Chip> : <Chip tone="ok" dot>Activo</Chip>;

  return (
    <div className="mx-auto w-full max-w-[1600px] space-y-5">
      <PageHeader
        crumbs={[["Equipo de la plataforma"]]}
        icon="people"
        title="Equipo de la plataforma"
        description="Personas de EscudoSST que entran a este panel. Ninguna ve datos de negocio de las empresas."
        aside={
          !forbidden && (
            <Button variant="primary" icon="plus" onClick={() => setInviting(true)}>
              Invitar a alguien
            </Button>
          )
        }
      />

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {(Object.keys(ROLE_INFO) as (keyof typeof ROLE_INFO)[]).map((k) => (
          <section key={k} className="rounded-2xl border border-line bg-surface p-4 shadow-sm">
            <p className="flex items-center gap-2">
              <span className={`rounded-md px-2 py-0.5 text-xs font-bold ${ROLE_INFO[k].tone}`}>{ROLE_INFO[k].label}</span>
              <span className="text-sm text-muted">puede…</span>
            </p>
            <ul className="mt-2 grid gap-1 text-sm">
              {ROLE_INFO[k].can.map((c) => (
                <li key={c} className="flex items-start gap-2">
                  <Icon name={c.startsWith("No ") ? "close" : "check"} size={14} className={`mt-0.5 shrink-0 ${c.startsWith("No ") ? "text-bad" : "text-ok"}`} />
                  {c}
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>

      <p className="flex items-start gap-2 rounded-xl border border-amber-ink/20 bg-amber-soft/60 px-4 py-3 text-sm text-amber-ink">
        <Icon name="mail" size={16} className="mt-0.5 shrink-0" />
        Invita con correos reales de trabajo, revisados a diario y con espacio: ahí llegan la invitación, los códigos de verificación, la recuperación de contraseña y los avisos.
      </p>

      {forbidden ? (
        <div className="rounded-2xl border border-line bg-surface p-8 text-center shadow-sm">
          <Icon name="lock" size={24} className="mx-auto text-muted" />
          <p className="mt-2 font-semibold">Solo un Dueño de la plataforma administra el equipo.</p>
        </div>
      ) : rows === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : (
        <div className="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          <ul className="divide-y divide-line">
            {rows.map((m) => (
              <li key={m.id} className={`flex flex-wrap items-center gap-3 px-4 py-3 ${m.status === "disabled" ? "opacity-60" : ""}`}>
                <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-full font-bold ${m.platform_role === "owner" ? "bg-side text-white" : "bg-brand-soft text-brand-strong"}`}>{initials(m.full_name)}</span>
                <span className="min-w-[12rem] flex-1">
                  <b className="block text-sm">
                    {m.full_name} {m.id === me.id && <span className="font-normal text-muted">(tú)</span>}
                  </b>
                  <span className="block text-xs text-muted">{m.email}</span>
                </span>
                <span className="flex flex-wrap items-center gap-1.5">
                  {status(m)}
                  {m.status === "active" && !m.mfa_enabled && <Chip tone="amber">Sin verificación en dos pasos</Chip>}
                </span>
                <span className="w-36 text-xs text-muted">{m.last_login_at ? `Último ingreso ${new Date(m.last_login_at).toLocaleDateString("es-PE")}` : "Nunca ingresó"}</span>
                <select
                  className="input !h-9 w-36 !py-1 text-sm"
                  aria-label={`Rol de ${m.full_name}`}
                  value={m.platform_role}
                  disabled={m.status === "disabled" || busy !== null}
                  onChange={(e) => run(m.id + "role", () => api(`/internal/team/${m.id}`, { method: "PATCH", body: { platform_role: e.target.value } }), "Rol actualizado.")}
                >
                  <option value="owner">Dueño</option>
                  <option value="support">Soporte</option>
                </select>
                <span className="flex gap-1">
                  {m.status === "invited" && (
                    <Button size="sm" variant="ghost" loading={busy === m.id + "resend"} onClick={() => run(m.id + "resend", () => api(`/internal/team/${m.id}/resend`, { method: "POST" }), "Invitación reenviada.")}>
                      Reenviar
                    </Button>
                  )}
                  {m.id !== me.id &&
                    (m.status === "disabled" ? (
                      <Button size="sm" variant="ghost" loading={busy === m.id + "on"} onClick={() => run(m.id + "on", () => api(`/internal/team/${m.id}/enable`, { method: "POST" }), "Acceso reactivado.")}>
                        Reactivar
                      </Button>
                    ) : (
                      <Button
                        size="sm"
                        variant="danger-ghost"
                        loading={busy === m.id + "off"}
                        onClick={() => window.confirm(`¿Quitarle el acceso a ${m.full_name}? Sus sesiones se cierran al momento.`) && run(m.id + "off", () => api(`/internal/team/${m.id}/disable`, { method: "POST" }), "Acceso desactivado.")}
                      >
                        Desactivar
                      </Button>
                    ))}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {inviting && (
        <InviteMember
          onClose={() => setInviting(false)}
          onDone={(email) => {
            setInviting(false);
            setToast({ text: `Invitación enviada a ${email}.`, tone: "ok" });
            load();
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function InviteMember({ onClose, onDone }: { onClose: () => void; onDone: (email: string) => void }) {
  const [f, setF] = useState({ full_name: "", email: "", platform_role: "support" as "owner" | "support" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api("/internal/team", { method: "POST", body: f });
      onDone(f.email);
    } catch (x) {
      setErrors(x instanceof ApiError ? (x.errors ?? { email: [x.detail ?? x.message] }) : { email: ["No se pudo enviar."] });
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Invitar al equipo"
      icon="people"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="invite-member" icon="mail" loading={saving}>
            Enviar invitación
          </Button>
        </>
      }
    >
      <form id="invite-member" onSubmit={submit} className="space-y-4" noValidate>
        <FormField label="Nombre completo" error={errors.full_name?.[0]}>
          <input className="input" maxLength={120} value={f.full_name} onChange={(e) => setF({ ...f, full_name: e.target.value })} autoFocus />
        </FormField>
        <FormField label="Correo de trabajo" error={errors.email?.[0]} hint="Debe ser un correo real: ahí llega el enlace para crear su contraseña (vence en 72 horas).">
          <input className="input" type="email" maxLength={160} value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
        </FormField>
        <div>
          <p className="mb-1.5 text-sm font-semibold">Rol</p>
          <div className="grid grid-cols-2 gap-2">
            {(["support", "owner"] as const).map((r) => (
              <button
                key={r}
                type="button"
                onClick={() => setF({ ...f, platform_role: r })}
                aria-pressed={f.platform_role === r}
                className={`rounded-xl border p-3 text-left text-sm transition-colors ${f.platform_role === r ? "border-brand bg-brand-soft/60 ring-1 ring-brand" : "border-line hover:border-brand/50"}`}
              >
                <b className="block">{ROLE_INFO[r].label}</b>
                <span className="text-xs text-muted">{ROLE_INFO[r].can[r === "owner" ? 1 : 0]}</span>
              </button>
            ))}
          </div>
        </div>
      </form>
    </Modal>
  );
}
