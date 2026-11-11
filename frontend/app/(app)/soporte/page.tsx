"use client";

// Accesos de soporte, lado del cliente (docs/05 §4.6): el administrador de cuenta autoriza, rechaza o termina
// el acceso del equipo de EscudoSST. El Jefe SST puede verlos, pero no decidir.

import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState, PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { formatDate } from "@/lib/accounts";
import { SUPPORT_STATUS, type SupportGrant, timeLeft } from "@/lib/support";

type Action = { kind: "approve" | "reject" | "revoke"; grant: SupportGrant };

export default function SoporteClientePage() {
  const [grants, setGrants] = useState<SupportGrant[] | null>(null);
  const [canManage, setCanManage] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<Action | null>(null);
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const closeToast = useCallback(() => setToast(null), []);

  const load = useCallback(() => {
    api<{ data: SupportGrant[]; can_manage: boolean }>("/account/support-access", { withCompany: true })
      .then((r) => {
        setGrants(r.data);
        setCanManage(r.can_manage);
      })
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar los accesos de soporte."));
  }, []);
  useEffect(load, [load]);

  async function run() {
    if (!action) return;
    setSaving(true);
    try {
      await api(`/account/support-access/${action.grant.id}/${action.kind}`, {
        method: "POST",
        withCompany: true,
        body: action.kind === "reject" ? { reason: reason.trim() || null } : undefined,
      });
      setToast({
        text: { approve: "Acceso autorizado. Vencerá solo.", reject: "Solicitud rechazada.", revoke: "Acceso terminado: soporte ya no puede entrar." }[action.kind],
        tone: "ok",
      });
      setAction(null);
      setReason("");
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? e.message : "No se pudo completar.", tone: "bad" });
    } finally {
      setSaving(false);
    }
  }

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!grants) return <div className="h-72 animate-pulse rounded-2xl bg-line/40" aria-label="Cargando" />;

  return (
    <div className="space-y-6">
      <PageHeader
        crumbs={[["Mi empresa"], ["Accesos de soporte"]]}
        icon="shield"
        title="Accesos de soporte"
        description="El equipo de EscudoSST solo entra a tu empresa si tú lo autorizas."
      />

      <ul className="grid gap-2 rounded-2xl border border-line bg-surface p-4 text-sm shadow-sm sm:grid-cols-3">
        {[
          ["eye", "Solo puede mirar: no crea, edita ni borra nada."],
          ["heart", "Nunca ve datos médicos de tus trabajadores."],
          ["clock", "El acceso vence solo y puedes terminarlo antes."],
        ].map(([icon, text]) => (
          <li key={icon} className="flex items-start gap-2">
            <Icon name={icon} size={16} className="mt-0.5 shrink-0 text-brand" />
            {text}
          </li>
        ))}
      </ul>

      {!canManage && <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">Solo el administrador de cuenta puede autorizar, rechazar o terminar accesos.</p>}

      {grants.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState icon="shield" text="No hay solicitudes de soporte." />
        </div>
      ) : (
        <ul className="space-y-3">
          {grants.map((g) => (
            <li key={g.id} className={`rounded-2xl border bg-surface p-4 shadow-sm sm:p-5 ${g.status === "requested" ? "border-amber-ink/40" : "border-line"}`}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="font-semibold">{g.support_name}</p>
                  <p className="text-xs text-muted">
                    Soporte de EscudoSST{g.support_email ? ` · ${g.support_email}` : ""} · pidió el {formatDate(g.requested_at, true)}
                  </p>
                </div>
                <Chip tone={SUPPORT_STATUS[g.status].tone} dot>
                  {g.status === "requested" ? "Espera tu respuesta" : SUPPORT_STATUS[g.status].label}
                </Chip>
              </div>
              <p className="mt-3 rounded-lg bg-bg px-3 py-2 text-sm">
                <span className="text-muted">Para qué:</span> {g.reason}
                {g.ticket_reference && <span className="text-muted"> · Ticket {g.ticket_reference}</span>}
              </p>
              <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted">
                  {g.status === "requested" && <>Solo lectura por {g.hours} horas desde que lo autorices.</>}
                  {g.status === "active" && (
                    <>
                      Autorizado por {g.authorized_name ?? "el administrador"} · vence {formatDate(g.expires_at, true)} ({timeLeft(g.expires_at)})
                    </>
                  )}
                  {g.status === "rejected" && <>Rechazado.</>}
                  {g.status === "expired" && <>Venció el {formatDate(g.expires_at, true)}</>}
                  {g.status === "revoked" && <>Terminado el {formatDate(g.revoked_at, true)}</>}
                </p>
                {canManage && (
                  <div className="flex flex-wrap gap-2">
                    {g.status === "requested" && (
                      <>
                        <Button variant="primary" size="sm" icon="check" onClick={() => setAction({ kind: "approve", grant: g })}>
                          Autorizar
                        </Button>
                        <Button variant="danger-ghost" size="sm" icon="close" onClick={() => setAction({ kind: "reject", grant: g })}>
                          Rechazar
                        </Button>
                      </>
                    )}
                    {g.status === "active" && (
                      <Button variant="danger-ghost" size="sm" icon="close" onClick={() => setAction({ kind: "revoke", grant: g })}>
                        Terminar acceso
                      </Button>
                    )}
                  </div>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {action && (
        <Modal
          title={{ approve: "Autorizar acceso de soporte", reject: "Rechazar solicitud", revoke: "Terminar acceso de soporte" }[action.kind]}
          subtitle={action.grant.support_name}
          icon={action.kind === "approve" ? "check" : "close"}
          size="sm"
          onClose={() => setAction(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setAction(null)}>
                Volver
              </Button>
              <Button variant={action.kind === "approve" ? "primary" : "danger"} icon={action.kind === "approve" ? "check" : "close"} loading={saving} onClick={run}>
                {{ approve: "Autorizar", reject: "Rechazar", revoke: "Terminar ahora" }[action.kind]}
              </Button>
            </>
          }
        >
          {action.kind === "approve" && (
            <p className="text-sm">
              {action.grant.support_name} podrá <b>ver</b> la información de tu empresa durante <b>{action.grant.hours} horas</b>, sin cambiar nada y sin ver datos médicos. Puedes terminarlo
              antes cuando quieras. Queda en tu bitácora.
            </p>
          )}
          {action.kind === "reject" && (
            <FormField label="Motivo (opcional)" counter={`${reason.length}/300`}>
              <textarea className="input min-h-20" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
            </FormField>
          )}
          {action.kind === "revoke" && <p className="text-sm">Soporte dejará de poder entrar desde ahora, aunque el acceso aún no haya vencido.</p>}
        </Modal>
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={closeToast} />}
    </div>
  );
}
