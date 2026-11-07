"use client";

// Aceptación pendiente de políticas (SCR-WEB-24): si el dueño publicó una versión nueva de los Términos o de la
// Política de privacidad pidiendo que se vuelva a aceptar (o el usuario nunca la aceptó), se muestra esta ventana
// al entrar al sistema. No se puede seguir sin aceptar; se puede leer cada documento antes.

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";

type Pending = { doc_type: string; title: string; version_id: string; version_label: string; is_reacceptance: boolean; slug: string };

export function LegalPendingGate() {
  const [pending, setPending] = useState<Pending[]>([]);
  const [checked, setChecked] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Pending[] }>("/me/legal-pending")
      .then((r) => setPending(r.data))
      .catch(() => undefined);
  }, []);

  if (pending.length === 0) return null;
  const updated = pending.some((p) => p.is_reacceptance);

  async function accept() {
    setSaving(true);
    setError(null);
    try {
      await api("/me/legal-accept", { method: "POST", body: { version_ids: pending.map((p) => p.version_id) } });
      setPending([]);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "No se pudo guardar. Inténtalo de nuevo.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-[#06101f]/55 backdrop-blur-[2px] sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="legal-title">
      <div className="w-full max-w-lg rounded-t-2xl bg-surface shadow-2xl sm:rounded-2xl">
        <div className="modal-head flex items-center gap-3 border-b border-line px-6 py-5 sm:rounded-t-2xl">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-brand text-white">
            <Icon name="file" size={20} />
          </span>
          <div>
            <h2 id="legal-title" className="text-lg font-semibold leading-tight">
              {updated ? "Actualizamos nuestras políticas" : "Antes de continuar"}
            </h2>
            <p className="text-sm text-muted">{updated ? "Léelas y acéptalas para seguir usando EscudoSST." : "Acepta las políticas de EscudoSST para usar el sistema."}</p>
          </div>
        </div>
        <div className="space-y-4 px-6 py-5">
          <ul className="space-y-2">
            {pending.map((p) => (
              <li key={p.version_id}>
                <a href={`/legal/${p.slug}`} target="_blank" rel="noopener" className="flex items-center gap-3 rounded-xl border border-line px-4 py-3 text-sm hover:border-brand/50">
                  <Icon name="file" size={16} className="text-brand" />
                  <span className="flex-1">
                    <b>{p.title}</b> <span className="text-muted">· versión {p.version_label}</span>
                  </span>
                  <span className="text-xs font-semibold text-brand">Leer</span>
                </a>
              </li>
            ))}
          </ul>
          <label className="flex cursor-pointer items-start gap-3 rounded-xl bg-bg/60 p-3.5 text-sm">
            <input type="checkbox" className="mt-0.5 h-4 w-4" checked={checked} onChange={(e) => setChecked(e.target.checked)} />
            He leído y acepto {pending.length > 1 ? "estos documentos" : "este documento"}.
          </label>
          {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
        </div>
        <div className="flex flex-col-reverse gap-2 border-t border-line px-6 py-4 sm:flex-row sm:justify-end">
          <Button
            variant="ghost"
            onClick={() => {
              session.clear();
              window.location.href = "/login";
            }}
          >
            Salir
          </Button>
          <Button variant="primary" icon="check" disabled={!checked} loading={saving} onClick={accept}>
            Aceptar y continuar
          </Button>
        </div>
      </div>
    </div>
  );
}
