"use client";

// Piezas comunes de Contratistas (M-CTR): estado de un requisito/documento y visor de archivos de la API.

import { useEffect, useState } from "react";
import { API_URL } from "@/lib/api";
import { session } from "@/lib/session";
import { Chip, type Tone } from "./Summary";

export type DocState = "pending" | "submitted" | "observed" | "rejected" | "approved" | "expiring" | "expired";

export const DOC_STATE: Record<DocState, [string, Tone]> = {
  pending: ["Pendiente", "na"],
  submitted: ["En revisión", "warn"],
  observed: ["Observado", "bad"],
  rejected: ["Rechazado", "bad"],
  approved: ["Aprobado", "ok"],
  expiring: ["Por vencer", "warn"],
  expired: ["Vencido", "bad"],
};

export const ACCREDITATION: Record<string, [string, Tone]> = {
  draft: ["Borrador", "na"],
  submitted: ["En revisión", "warn"],
  under_review: ["En revisión", "warn"],
  observed: ["Observada", "bad"],
  accredited: ["Acreditado", "ok"],
  expiring: ["Por vencer", "warn"],
  expired: ["Vencida", "bad"],
  suspended: ["Suspendida", "bad"],
  rejected: ["Rechazada", "bad"],
};

export const fmtDate = (d: string | null | undefined) => (d ? new Date(d.length === 10 ? `${d}T12:00:00` : d).toLocaleDateString("es-PE") : "—");

export function StateChip({ state }: { state: string }) {
  const [label, tone] = DOC_STATE[state as DocState] ?? [state, "na"];
  return <Chip tone={tone}>{label}</Chip>;
}

export function AccreditationChip({ status }: { status: string | null }) {
  if (!status) return <Chip tone="na">Sin solicitud</Chip>;
  const [label, tone] = ACCREDITATION[status] ?? [status, "na"];
  return <Chip tone={tone}>{label}</Chip>;
}

/** Muestra un archivo de /files/{id} (PDF o imagen) dentro de la pantalla, con la sesión activa. */
export function FileViewer({ fileId, name, mime }: { fileId: string; name?: string | null; mime?: string | null }) {
  const [url, setUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let revoke: string | null = null;
    const headers: Record<string, string> = { Accept: "*/*" };
    const token = session.getToken();
    const company = session.getCompanyId();
    if (token) headers.Authorization = `Bearer ${token}`;
    if (company) headers["X-Company-Id"] = company;
    fetch(`${API_URL}/api/v1/files/${fileId}`, { headers })
      .then(async (r) => {
        if (!r.ok) throw new Error("No se pudo abrir el archivo.");
        revoke = URL.createObjectURL(await r.blob());
        setUrl(revoke);
      })
      .catch((e: Error) => setError(e.message));
    return () => {
      if (revoke) URL.revokeObjectURL(revoke);
    };
  }, [fileId]);

  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>;
  if (!url) return <div className="h-72 animate-pulse rounded-lg bg-bg" />;
  return (
    <div className="space-y-2">
      {mime?.startsWith("image/") ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={url} alt={name ?? "Documento"} className="max-h-[60vh] w-full rounded-lg border border-line object-contain" />
      ) : mime === "application/pdf" ? (
        <iframe src={url} title={name ?? "Documento"} className="h-[60vh] w-full rounded-lg border border-line" />
      ) : (
        <p className="text-sm text-muted">Este tipo de archivo no se puede previsualizar.</p>
      )}
      <a href={url} download={name ?? "documento"} className="inline-block text-sm font-semibold text-brand hover:underline">
        Descargar {name ?? "archivo"}
      </a>
    </div>
  );
}
