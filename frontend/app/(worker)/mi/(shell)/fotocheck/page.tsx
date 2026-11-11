"use client";

// Mi fotocheck (M-FOT SCR-FOT-05): QR a pantalla completa y estado Habilitado / No habilitado con sus motivos.
// El QR lleva al mismo enlace de verificación que el fotocheck impreso (/verificar/fotocheck/{token}).

import { useEffect, useRef, useState } from "react";
import { QrCode } from "@/components/mfa/QrCode";
import { Icon } from "@/components/ui/Icon";
import { Alert, BigButton, Loading, Title } from "@/components/worker/WorkerUi";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

type Cred = {
  credential: { id: string; number: string; type: string | null; status: string; valid_from: string | null; expires_on: string | null } | null;
  enabled: boolean;
  reasons: string[];
  verify_url?: string;
  worker: { full_name: string; document_masked: string | null; position: string | null; site: string | null };
};

export default function MyCredentialPage() {
  const [c, setC] = useState<Cred | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [full, setFull] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    workerApi<Cred>("/credential").then(setC).catch((e) => setError(errorText(e)));
  }, []);

  useEffect(() => {
    const onChange = () => setFull(!!document.fullscreenElement);
    document.addEventListener("fullscreenchange", onChange);
    return () => document.removeEventListener("fullscreenchange", onChange);
  }, []);

  function toggleFull() {
    if (document.fullscreenElement) void document.exitFullscreen();
    else if (box.current?.requestFullscreen) void box.current.requestFullscreen().catch(() => setFull((f) => !f));
    else setFull((f) => !f);
  }

  if (error) return <Alert>{error}</Alert>;
  if (!c) return <Loading />;
  const size = typeof window === "undefined" ? 280 : Math.min(full ? 420 : 300, window.innerWidth - 64);

  return (
    <>
      <Title sub="Muéstralo en portería o en campo para que lo escaneen.">Mi fotocheck</Title>
      <div ref={box} className={`flex flex-col items-center gap-4 rounded-2xl border border-line bg-surface p-5 text-center ${full ? "fixed inset-0 z-50 justify-center rounded-none border-0" : ""}`}>
        <span className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-lg font-bold ${c.enabled ? "bg-ok-soft text-ok" : "bg-bad-soft text-bad"}`}>
          <Icon name={c.enabled ? "check" : "close"} size={22} /> {c.enabled ? "Habilitado" : "No habilitado"}
        </span>
        {c.verify_url ? <QrCode value={c.verify_url} size={size} /> : <div className="grid h-48 w-48 place-items-center rounded-xl bg-na-soft text-sm text-muted">Sin QR</div>}
        <div>
          <p className="font-display text-lg font-bold">{c.worker.full_name}</p>
          <p className="text-sm text-muted">{[c.worker.document_masked, c.worker.position, c.worker.site].filter(Boolean).join(" · ")}</p>
          {c.credential && (
            <p className="mt-1 text-sm text-muted">
              N.° {c.credential.number}{c.credential.type ? ` · ${c.credential.type}` : ""}{c.credential.expires_on ? ` · vence ${fmtDate(c.credential.expires_on)}` : ""}
            </p>
          )}
        </div>
        {full && <BigButton variant="secondary" onClick={toggleFull} className="max-w-xs">Cerrar pantalla completa</BigButton>}
      </div>
      {!full && c.verify_url && <BigButton onClick={toggleFull}><Icon name="expand" size={18} /> Ver a pantalla completa</BigButton>}
      {!c.enabled && c.reasons.length > 0 && (
        <section className="space-y-2 rounded-2xl border border-line bg-surface p-4">
          <h2 className="font-display text-base font-semibold">Qué te falta</h2>
          <ul className="list-disc space-y-1 pl-5 text-sm">
            {c.reasons.map((r) => <li key={r}>{r}</li>)}
          </ul>
          <p className="text-sm text-muted">Consulta con el área de SST para ponerte al día.</p>
        </section>
      )}
    </>
  );
}
