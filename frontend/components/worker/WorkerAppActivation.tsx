"use client";

// «Activar app del trabajador» desde la ficha 360° (docs/07 §5.2): genera un código de un solo uso y un enlace.
// Se envían por correo (si el trabajador tiene) y por WhatsApp (si está en vivo y lo aceptó); siempre se muestran
// aquí para imprimirlos o dictarlos. Generar uno nuevo anula el anterior.

import { useEffect, useState } from "react";
import { QrCode } from "@/components/mfa/QrCode";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";

type Status = { activated_at: string | null; pending_activation: boolean; devices: number; membership_status: string | null; last_access_at: string | null; has_email: boolean; whatsapp: boolean; can_issue: boolean };
type Issued = { worker: { full_name: string; document_masked: string | null }; company: string; code: string; link: string; expires_at: string; sent: { email: boolean; whatsapp: boolean } };

const fmt = (v: string | null) => (v ? new Date(v).toLocaleString("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }) : "—");

export function WorkerAppActivation({ workerId }: { workerId: string }) {
  const [status, setStatus] = useState<Status | null>(null);
  const [issued, setIssued] = useState<Issued | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = () => api<{ data: Status }>(`/trb/workers/${workerId}/app-access`, { withCompany: true }).then((r) => setStatus(r.data)).catch(() => setStatus(null));
  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [workerId]);

  async function issue() {
    if (status?.activated_at && !window.confirm("Este trabajador ya activó su app. Un código nuevo le permite activarla en otro equipo (por ejemplo, si olvidó su PIN). ¿Generar?")) return;
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: Issued }>(`/trb/workers/${workerId}/app-access`, { method: "POST", withCompany: true });
      setIssued(r.data);
      void load();
    } catch (e) {
      setError(e instanceof ApiError ? e.detail ?? e.title : "No se pudo generar el código.");
    } finally {
      setBusy(false);
    }
  }

  if (!status?.can_issue) return null;
  return (
    <>
      <Button icon="phone" onClick={issue} loading={busy} title={status.activated_at ? `App activada el ${fmt(status.activated_at)} · ${status.devices} equipo(s)` : "El trabajador aún no activa su app"}>
        {status.activated_at ? "Nuevo código de la app" : "Activar app del trabajador"}
      </Button>
      {error && <p role="alert" className="w-full text-sm text-bad">{error}</p>}
      {issued && (
        <Modal title="Código de activación" subtitle={`${issued.worker.full_name}${issued.worker.document_masked ? ` · DNI ${issued.worker.document_masked}` : ""}`} onClose={() => setIssued(null)}
          footer={<>
            <Button onClick={() => setIssued(null)}>Cerrar</Button>
            <Button variant="primary" icon="download" onClick={() => window.print()}>Imprimir</Button>
          </>}>
          <div className="space-y-4 text-sm">
            <p>Entrégale este código al trabajador. Lo usa una sola vez, junto con su DNI, para activar la app en su celular y crear su PIN.</p>
            <p className="rounded-xl bg-brand-soft py-4 text-center font-mono text-3xl font-bold tracking-[0.3em]">{issued.code}</p>
            <div className="flex flex-col items-center gap-2">
              <QrCode value={issued.link} size={180} />
              <p className="break-all text-center text-xs text-muted">{issued.link}</p>
            </div>
            <p className="text-muted">
              Vence el {fmt(issued.expires_at)}. {issued.sent.email ? "Se envió por correo. " : status.has_email ? "No se pudo enviar por correo. " : ""}
              {issued.sent.whatsapp ? "Se envió por WhatsApp." : "No se envió por WhatsApp (sin aceptación del trabajador o WhatsApp no está activo)."}
            </p>
            <p className="text-xs text-muted">Pasos para el trabajador: abrir {new URL(issued.link).origin}/mi/activar, escribir su DNI y el código, y crear un PIN de 6 números.</p>
          </div>
        </Modal>
      )}
    </>
  );
}
