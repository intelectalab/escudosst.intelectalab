"use client";

// Hojas para imprimir o guardar en PDF (con el diálogo del navegador): certificado A4 horizontal y fotocheck tamaño
// tarjeta, ambos con su QR de verificación. Usan la sesión de la pestaña (misma app).

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { QrCode } from "@/components/mfa/QrCode";

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

type Cert = {
  certificate_code: string; holder_name: string; holder_document_masked: string | null; course_title: string; hours: number | null; score: number | null;
  issued_on: string; expires_on: string | null; status: string; public_verification_enabled: boolean; instructor: string | null;
  company: { name: string; legal_name: string; ruc: string }; verify_url: string; signature: string | null; signer_name: string | null;
};

export function CertificateSheet({ id }: { id: string }) {
  const { data: c, error } = useSheet<Cert>(`/cap/certificates/${id}/print`);
  if (error) return <p className="p-8">{error}</p>;
  if (!c) return <p className="p-8">Preparando el certificado…</p>;
  return (
    <div className="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
      <style>{`@page { size: A4 landscape; margin: 12mm } @media print { .no-print { display: none } }`}</style>
      <Toolbar hint={c.public_verification_enabled ? "El QR permite verificarlo en línea." : "La verificación pública de este certificado está desactivada: actívala en el certificado para que el QR funcione."} />
      <div className="mx-auto flex max-w-4xl flex-col justify-between rounded-2xl border-[6px] border-double border-[#0d3b6e] bg-white p-10 text-center text-gray-900 print:border-[#0d3b6e]" style={{ minHeight: "170mm" }}>
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.3em] text-[#1b6fb7]">{c.company.name}</p>
          <h1 className="mt-3 text-4xl font-bold text-[#0d3b6e]">CERTIFICADO</h1>
          {c.status === "voided" && <p className="mt-2 text-2xl font-bold text-red-700">ANULADO</p>}
          <p className="mt-6 text-lg">Se certifica que</p>
          <p className="mt-2 text-3xl font-bold">{c.holder_name}</p>
          {c.holder_document_masked && <p className="text-sm text-gray-600">Documento {c.holder_document_masked}</p>}
          <p className="mt-6 text-lg">participó y aprobó la capacitación</p>
          <p className="mt-2 text-2xl font-semibold text-[#0d3b6e]">«{c.course_title}»</p>
          <p className="mt-3 text-base">
            {c.hours !== null && <>con una duración de <b>{c.hours} horas</b></>}
            {c.score !== null && <> y nota <b>{c.score}</b></>}.
          </p>
        </div>
        <div className="mt-8 flex items-end justify-between gap-6 text-left text-sm">
          <div>
            <p>Emitido: <b>{d(c.issued_on)}</b></p>
            <p>Vigente hasta: <b>{c.expires_on ? d(c.expires_on) : "sin vencimiento"}</b></p>
            <p>Código: <b>{c.certificate_code}</b></p>
            <p className="text-xs text-gray-500">{c.company.legal_name} · RUC {c.company.ruc}</p>
          </div>
          <div className="text-center">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            {c.signature && <img src={c.signature} alt="Firma" className="mx-auto -mb-1 h-16 max-w-[14rem] object-contain" />}
            <div className="mx-auto h-0 w-56 border-t border-gray-800" />
            <p className="mt-1">{c.signer_name ?? c.instructor ?? "Capacitador"}</p>
            {c.signer_name && c.instructor && <p className="text-xs text-gray-500">Capacitador: {c.instructor}</p>}
          </div>
          <div className="text-center">
            <QrCode value={c.verify_url} size={110} />
            <p className="mt-1 text-[10px] text-gray-500">Verifica en línea</p>
          </div>
        </div>
      </div>
    </div>
  );
}

type Cred = {
  credential_number: string; type: string | null; status: string; valid_from: string | null; expires_on: string | null; holder_name: string | null;
  document_masked: string | null; position: string | null; site: string | null; photo_file_id: string | null; company: { name: string; ruc: string }; verify_url: string;
};

export function CredentialSheet({ id }: { id: string }) {
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
    <div className="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
      <style>{`@page { size: 54mm 86mm; margin: 0 } @media print { .no-print { display: none } }`}</style>
      <Toolbar hint={c.status === "active" ? "Imprime en tarjeta (54 × 86 mm)." : "Atención: el fotocheck no está activo; el QR dirá que no está habilitado."} />
      <div className="mx-auto flex flex-col items-center overflow-hidden rounded-xl bg-white text-center text-gray-900 shadow print:shadow-none" style={{ width: "54mm", height: "86mm" }}>
        <div className="w-full bg-[#0d3b6e] py-1.5 text-[9px] font-bold uppercase tracking-wider text-white">{c.company.name}</div>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        {photo ? <img src={photo} alt="" className="mt-2 h-[24mm] w-[20mm] rounded object-cover" /> : <div className="mt-2 h-[24mm] w-[20mm] rounded bg-gray-200" />}
        <p className="mt-1.5 px-2 text-[11px] font-bold leading-tight">{c.holder_name}</p>
        <p className="text-[8px] text-gray-600">{c.position ?? ""}{c.site ? ` · ${c.site}` : ""}</p>
        <div className="mt-1"><QrCode value={c.verify_url} size={78} /></div>
        <p className="text-[7px] text-gray-600">{c.credential_number} · vence {d(c.expires_on)}</p>
        <p className="text-[7px] text-gray-500">{c.document_masked ?? ""}</p>
      </div>
    </div>
  );
}
