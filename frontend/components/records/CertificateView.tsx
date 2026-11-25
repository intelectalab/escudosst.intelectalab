"use client";

// Certificado de capacitación tal como se imprime (M-CAP SCR-CAP-11): lo usan la hoja de impresión del certificado y la vista previa del
// editor de plantillas, para que la vista previa sea igual al certificado impreso. Con plantilla, el texto sale de body_template con sus
// variables {{…}}, y la plantilla define logo, fondo, firmantes, orientación, QR, horas y nota.

import { useEffect, useState } from "react";
import { API_URL } from "@/lib/api";
import { session } from "@/lib/session";
import { QrCode } from "@/components/mfa/QrCode";

export type CertTemplate = {
  orientation: string; paper_size: string; body_template: string; logo_file_id: string | null; background_file_id: string | null;
  signatory_name: string | null; signatory_title: string | null; signature_file_id: string | null;
  second_signatory_name: string | null; second_signatory_title: string | null; second_signature_file_id: string | null;
  shows_qr: boolean; shows_hours: boolean; shows_score: boolean; layout?: { colors?: { primary?: string; accent?: string } } | null;
};

export type CertData = {
  certificate_code: string; holder_name: string; holder_document_masked: string | null; course_title: string; hours: number | null; score: number | null;
  issued_on: string; expires_on: string | null; status: string; instructor: string | null;
  company: { name: string; legal_name: string; ruc: string }; verify_url: string; signature: string | null; signer_name: string | null;
  template?: CertTemplate | null;
};

/** Variables que admite el texto de la plantilla (las mismas de certificate_templates.variable_fields). */
export const CERT_VARIABLES: [string, string][] = [
  ["holder_name", "Nombre del titular"], ["holder_document_masked", "Documento (enmascarado)"], ["course_title", "Curso o tema"], ["hours", "Horas"],
  ["score", "Nota"], ["grade_label", "Calificación"], ["issued_on", "Fecha de emisión"], ["expires_on", "Vigente hasta"],
  ["certificate_number", "Código del certificado"], ["verification_url", "Dirección de verificación"],
];

const d = (v: unknown) => (v ? String(v).slice(0, 10).split("-").reverse().join("/") : "—");

export function fillTemplate(body: string, c: CertData): string {
  const vars: Record<string, string> = {
    holder_name: c.holder_name, holder_document_masked: c.holder_document_masked ?? "—", course_title: c.course_title, hours: c.hours === null ? "—" : String(c.hours),
    score: c.score === null ? "—" : String(c.score), grade_label: c.score === null ? "—" : String(c.score), issued_on: d(c.issued_on),
    expires_on: c.expires_on ? d(c.expires_on) : "sin vencimiento", certificate_number: c.certificate_code, verification_url: c.verify_url,
  };
  return body.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k: string) => (k in vars ? vars[k] : m));
}

/** Imagen de un archivo de la empresa (logo, fondo, firma) pedida con la sesión y mostrada como imagen local. */
export function useFileUrl(id: string | null | undefined) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!id) { setUrl(null); return; }
    let gone = false;
    let made: string | null = null;
    fetch(`${API_URL}/api/v1/files/${id}`, { headers: { Authorization: `Bearer ${session.getToken()}`, "X-Company-Id": session.getCompanyId() ?? "" } })
      .then(async (r) => { if (r.ok && !gone) { made = URL.createObjectURL(await r.blob()); setUrl(made); } })
      .catch(() => setUrl(null));
    return () => { gone = true; if (made) URL.revokeObjectURL(made); };
  }, [id]);
  return url;
}

function Signer({ img, name, title }: { img: string | null; name: string; title?: string | null }) {
  return (
    <div className="text-center">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      {img && <img src={img} alt="Firma" className="mx-auto -mb-1 h-16 max-w-[14rem] object-contain" />}
      <div className="mx-auto h-0 w-56 border-t border-gray-800" />
      <p className="mt-1">{name}</p>
      {title && <p className="text-xs text-gray-500">{title}</p>}
    </div>
  );
}

export function CertificateView({ c }: { c: CertData }) {
  const t = c.template ?? null;
  const logo = useFileUrl(t?.logo_file_id);
  const bg = useFileUrl(t?.background_file_id);
  const sig1 = useFileUrl(t?.signature_file_id);
  const sig2 = useFileUrl(t?.second_signature_file_id);
  const primary = t?.layout?.colors?.primary ?? "#0d3b6e";
  const accent = t?.layout?.colors?.accent ?? "#1b6fb7";
  const portrait = t?.orientation === "portrait";
  const showQr = t ? t.shows_qr : true;
  const showHours = t ? t.shows_hours : true;
  const showScore = t ? t.shows_score : c.score !== null;
  const body = t?.body_template ? fillTemplate(t.body_template, c) : null;
  return (
    <>
      <style>{`@page { size: ${t?.paper_size === "letter" ? "letter" : "A4"} ${portrait ? "portrait" : "landscape"}; margin: 12mm } @media print { .no-print { display: none } }`}</style>
      <div className={`mx-auto flex ${portrait ? "max-w-2xl" : "max-w-4xl"} flex-col justify-between rounded-2xl border-[6px] border-double bg-white bg-cover bg-center p-10 text-center text-gray-900`}
        style={{ minHeight: portrait ? "250mm" : "170mm", borderColor: primary, backgroundImage: bg ? `url(${bg})` : undefined }}>
        <div>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          {logo && <img src={logo} alt="Logo" className="mx-auto mb-2 h-14 max-w-[12rem] object-contain" />}
          <p className="text-sm font-semibold uppercase tracking-[0.3em]" style={{ color: accent }}>{c.company.name}</p>
          <h1 className="mt-3 text-4xl font-bold" style={{ color: primary }}>CERTIFICADO</h1>
          {c.status === "voided" && <p className="mt-2 text-2xl font-bold text-red-700">ANULADO</p>}
          {body !== null ? (
            <>
              <p className="mx-auto mt-6 max-w-3xl whitespace-pre-line text-lg">{body}</p>
              {(showHours && c.hours !== null && !/\{\{\s*hours\s*\}\}/.test(t!.body_template)) || (showScore && c.score !== null && !/\{\{\s*(score|grade_label)\s*\}\}/.test(t!.body_template)) ? (
                <p className="mt-3 text-base">
                  {showHours && c.hours !== null && !/\{\{\s*hours\s*\}\}/.test(t!.body_template) && <>Duración: <b>{c.hours} horas</b>. </>}
                  {showScore && c.score !== null && !/\{\{\s*(score|grade_label)\s*\}\}/.test(t!.body_template) && <>Nota: <b>{c.score}</b>.</>}
                </p>
              ) : null}
            </>
          ) : (
            <>
              <p className="mt-6 text-lg">Se certifica que</p>
              <p className="mt-2 text-3xl font-bold">{c.holder_name}</p>
              {c.holder_document_masked && <p className="text-sm text-gray-600">Documento {c.holder_document_masked}</p>}
              <p className="mt-6 text-lg">participó y aprobó la capacitación</p>
              <p className="mt-2 text-2xl font-semibold" style={{ color: primary }}>«{c.course_title}»</p>
              <p className="mt-3 text-base">
                {showHours && c.hours !== null && <>con una duración de <b>{c.hours} horas</b></>}
                {showScore && c.score !== null && <> y nota <b>{c.score}</b></>}.
              </p>
            </>
          )}
        </div>
        <div className="mt-8 flex flex-wrap items-end justify-between gap-6 text-left text-sm">
          <div>
            <p>Emitido: <b>{d(c.issued_on)}</b></p>
            <p>Vigente hasta: <b>{c.expires_on ? d(c.expires_on) : "sin vencimiento"}</b></p>
            <p>Código: <b>{c.certificate_code}</b></p>
            <p className="text-xs text-gray-500">{c.company.legal_name} · RUC {c.company.ruc}</p>
          </div>
          {t?.signatory_name ? (
            <Signer img={sig1 ?? c.signature} name={t.signatory_name} title={t.signatory_title} />
          ) : (
            <Signer img={c.signature} name={c.signer_name ?? c.instructor ?? "Capacitador"} title={c.signer_name && c.instructor ? `Capacitador: ${c.instructor}` : null} />
          )}
          {t?.second_signatory_name && <Signer img={sig2} name={t.second_signatory_name} title={t.second_signatory_title} />}
          {showQr && (
            <div className="text-center">
              <QrCode value={c.verify_url} size={110} />
              <p className="mt-1 text-[10px] text-gray-500">Verifica en línea</p>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
