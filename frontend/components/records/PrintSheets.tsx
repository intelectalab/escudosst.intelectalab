"use client";

// Hojas para imprimir o guardar en PDF (con el diálogo del navegador): certificado A4 horizontal y fotocheck tamaño
// tarjeta, ambos con su QR de verificación. Usan la sesión de la pestaña (misma app).

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { QrCode } from "@/components/mfa/QrCode";
import { CertificateView, type CertData } from "./CertificateView";

const d = (v: unknown) => (v ? String(v).slice(0, 10).split("-").reverse().join("/") : "—");

function useSheet<T>(path: string) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: T }>(path, { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [path]);
  return { data, error };
}

function Toolbar({ hint }: { hint: string }) {
  return (
    <div className="no-print mx-auto mb-4 flex max-w-4xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
      <span className="text-sm text-gray-600">{hint}</span>
      <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">
        Imprimir / Guardar PDF
      </button>
    </div>
  );
}

type Cert = CertData & { public_verification_enabled: boolean };

export function CertificateSheet({ id }: { id: string }) {
  const { data: c, error } = useSheet<Cert>(`/cap/certificates/${id}/print`);
  if (error) return <p className="p-8">{error}</p>;
  if (!c) return <p className="p-8">Preparando el certificado…</p>;
  return (
    <div className="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
      <Toolbar hint={c.public_verification_enabled ? "El QR permite verificarlo en línea." : "La verificación pública de este certificado está desactivada: actívala en el certificado para que el QR funcione."} />
      <CertificateView c={c} />
    </div>
  );
}

type Cred = {
  credential_number: string; type: string | null; status: string; valid_from: string | null; expires_on: string | null; holder_name: string | null;
  document_masked: string | null; position: string | null; site: string | null; photo_file_id: string | null; company: { name: string; ruc: string }; verify_url: string;
};

export function CredentialSheet({ id }: { id: string }) {
  return (
    <div className="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
      <style>{`@page { size: 54mm 86mm; margin: 0 } @media print { .no-print { display: none } }`}</style>
      <CredentialCard id={id} toolbar />
    </div>
  );
}

/** Tarjeta CR80 (54 × 86 mm) de un fotocheck; en el lote cada tarjeta va en su propia hoja (M-FOT SCR-FOT-04). */
export function CredentialCard({ id, toolbar = false }: { id: string; toolbar?: boolean }) {
  const { data: c, error } = useSheet<Cred>(`/fot/credentials/${id}/print`);
  const [photo, setPhoto] = useState<string | null>(null);
  useEffect(() => {
    if (!c?.photo_file_id) return;
    // La foto se pide con la sesión y se muestra como imagen local.
    import("@/lib/session").then(async ({ session }) => {
      const { API_URL } = await import("@/lib/api");
      const res = await fetch(`${API_URL}/api/v1/files/${c.photo_file_id}`, { headers: { Authorization: `Bearer ${session.getToken()}`, "X-Company-Id": session.getCompanyId() ?? "" } });
      if (res.ok) setPhoto(URL.createObjectURL(await res.blob()));
    });
  }, [c?.photo_file_id]);
  if (error) return <p className="p-8">{error}</p>;
  if (!c) return <p className="p-8">Preparando el fotocheck…</p>;
  return (
    <>
      {toolbar && <Toolbar hint={c.status === "active" ? "Imprime en tarjeta (54 × 86 mm)." : "Atención: el fotocheck no está activo; el QR dirá que no está habilitado."} />}
      <div className="mx-auto mb-4 flex flex-col items-center overflow-hidden rounded-xl bg-white text-center text-gray-900 shadow print:mb-0 print:shadow-none" style={{ width: "54mm", height: "86mm", breakAfter: "page" }}>
        <div className="w-full bg-[#0d3b6e] py-1.5 text-[9px] font-bold uppercase tracking-wider text-white">{c.company.name}</div>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        {photo ? <img src={photo} alt="" className="mt-2 h-[24mm] w-[20mm] rounded object-cover" /> : <div className="mt-2 h-[24mm] w-[20mm] rounded bg-gray-200" />}
        <p className="mt-1.5 px-2 text-[11px] font-bold leading-tight">{c.holder_name}</p>
        <p className="text-[8px] text-gray-600">{c.position ?? ""}{c.site ? ` · ${c.site}` : ""}</p>
        <div className="mt-1"><QrCode value={c.verify_url} size={78} /></div>
        <p className="text-[7px] text-gray-600">{c.credential_number} · vence {d(c.expires_on)}</p>
        <p className="text-[7px] text-gray-500">{c.document_masked ?? ""}</p>
      </div>
    </>
  );
}
