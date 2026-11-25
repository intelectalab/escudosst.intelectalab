"use client";

// Editor de plantillas de certificado (M-CAP SCR-CAP-11): botones para insertar las variables en el texto y vista previa con datos de
// ejemplo, dibujada con el mismo componente que el certificado impreso (lo que se ve aquí es lo que sale al imprimir).

import { CERT_VARIABLES, CertificateView, type CertData } from "./CertificateView";

type Values = Record<string, unknown>;

const SAMPLE: Omit<CertData, "template"> = {
  certificate_code: "CERT-2026-000123", holder_name: "María Pérez Quispe", holder_document_masked: "****5678", course_title: "Trabajos en altura",
  hours: 8, score: 17, issued_on: new Date().toISOString().slice(0, 10), expires_on: `${new Date().getFullYear() + 1}-12-31`, status: "validated", instructor: "Capacitador interno",
  company: { name: "Tu empresa", legal_name: "Tu empresa S.A.C.", ruc: "20000000000" }, verify_url: "https://escudosst.com/verificar/certificado/EJEMPLO", signature: null, signer_name: null,
};

const str = (v: unknown) => (v === null || v === undefined || v === "" ? null : String(v));

export function CertificateTemplateEditor({ values, disabled, onInsert }: { values: Values; disabled: boolean; onInsert: (field: string, text: string) => void }) {
  const tpl = {
    orientation: String(values.orientation || "landscape"), paper_size: String(values.paper_size || "A4"), body_template: String(values.body_template ?? ""),
    logo_file_id: str(values.logo_file_id), background_file_id: str(values.background_file_id),
    signatory_name: str(values.signatory_name), signatory_title: str(values.signatory_title), signature_file_id: str(values.signature_file_id),
    second_signatory_name: str(values.second_signatory_name), second_signatory_title: str(values.second_signatory_title), second_signature_file_id: str(values.second_signature_file_id),
    shows_qr: !!values.shows_qr, shows_hours: !!values.shows_hours, shows_score: !!values.shows_score,
    layout: null,
  };
  return (
    <div className="space-y-3 sm:col-span-2">
      {!disabled && (
        <div className="rounded-xl border border-line p-3 text-sm">
          <p className="mb-2 text-xs font-semibold text-muted">Insertar una variable en el texto del certificado</p>
          <div className="flex flex-wrap gap-1.5">
            {CERT_VARIABLES.map(([k, label]) => (
              <button key={k} type="button" className="rounded-lg border border-line px-2 py-1 text-xs hover:bg-bg" title={`{{${k}}}`}
                onClick={() => onInsert("body_template", `{{${k}}}`)}>{label}</button>
            ))}
          </div>
        </div>
      )}
      <div className="rounded-xl border border-line bg-bg p-3">
        <p className="mb-2 text-xs font-semibold text-muted">Vista previa con datos de ejemplo (así se imprime el certificado)</p>
        <div className="overflow-x-auto">
          <div className="origin-top-left scale-[0.6] sm:scale-75" style={{ width: tpl.orientation === "portrait" ? "42rem" : "56rem" }}>
            <CertificateView c={{ ...SAMPLE, template: tpl }} />
          </div>
        </div>
      </div>
    </div>
  );
}
