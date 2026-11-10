"use client";

// Verificación pública de un certificado o fotocheck (sin sesión): muestra solo lo mínimo
// (nombre, curso o puesto, vigencia, estado y empresa). Nunca datos de salud ni documentos completos.

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Result = Record<string, unknown> & { result: string };

const CERT: Record<string, [string, string]> = {
  valid: ["Certificado válido", "bg-[#E8F7EE] text-[#17703e]"],
  expired: ["Certificado vencido", "bg-[#FFF6DB] text-[#7a5300]"],
  voided: ["Certificado anulado", "bg-[#FDECEB] text-[#a3261f]"],
  pending_validation: ["Pendiente de validación", "bg-[#FFF6DB] text-[#7a5300]"],
  not_public: ["No verificable públicamente", "bg-[#EEF1F5] text-[#4a5563]"],
  invalid: ["No encontramos un certificado con ese código", "bg-[#FDECEB] text-[#a3261f]"],
};
const CRED: Record<string, [string, string]> = {
  enabled: ["Habilitado para ingresar", "bg-[#E8F7EE] text-[#17703e]"],
  not_enabled: ["No habilitado", "bg-[#FDECEB] text-[#a3261f]"],
  expired: ["Fotocheck vencido", "bg-[#FFF6DB] text-[#7a5300]"],
  revoked: ["Fotocheck revocado", "bg-[#FDECEB] text-[#a3261f]"],
  invalid: ["Fotocheck no reconocido", "bg-[#FDECEB] text-[#a3261f]"],
};

const d = (v: unknown) => (v ? String(v).slice(0, 10).split("-").reverse().join("/") : "—");

export function VerifyResult({ kind, code }: { kind: "certificate" | "credential"; code: string }) {
  const [r, setR] = useState<Result | null>(null);
  const [error, setError] = useState(false);
  useEffect(() => {
    api<{ data: Result }>(`/public/verify/${kind}/${encodeURIComponent(code)}`)
      .then((x) => setR(x.data))
      .catch(() => setError(true));
  }, [kind, code]);

  const map = kind === "certificate" ? CERT : CRED;
  if (error) return <p className="site-card p-6">No pudimos verificar en este momento. Inténtalo de nuevo en unos minutos.</p>;
  if (!r) return <p className="site-card p-6">Verificando…</p>;
  const [label, tone] = map[r.result] ?? map.invalid;
  const rows: [string, unknown][] =
    kind === "certificate"
      ? [["Titular", r.holder_name], ["Documento", r.holder_document_masked], ["Curso o tema", r.course_title], ["Horas", r.hours], ["Emitido", d(r.issued_on)], ["Vence", r.expires_on ? d(r.expires_on) : "Sin vencimiento"], ["Código", r.certificate_code], ["Empresa emisora", r.company]]
      : [["Titular", r.name], ["Puesto", r.position], ["Fotocheck", r.credential_number], ["Vence", d(r.expires_on)], ["Empresa", r.company]];
  return (
    <div className="site-card space-y-4 p-6">
      <p className={`rounded-xl px-4 py-3 text-lg font-bold ${tone}`}>{label}</p>
      {r.result !== "invalid" && r.result !== "not_public" && (
        <dl className="grid gap-2 sm:grid-cols-2">
          {rows.filter(([, v]) => v !== undefined && v !== null && v !== "").map(([k, v]) => (
            <div key={k}>
              <dt className="text-xs font-semibold text-[var(--site-text-2)]">{k}</dt>
              <dd className="font-medium">{String(v)}</dd>
            </div>
          ))}
        </dl>
      )}
      {Array.isArray(r.blocking_reasons) && r.blocking_reasons.length > 0 && (
        <p className="text-sm">Motivo: {(r.blocking_reasons as string[]).join(", ")}.</p>
      )}
      {r.result === "not_public" && <p className="text-sm">La empresa {String(r.company ?? "")} no habilitó la verificación pública de este certificado.</p>}
      <p className="text-xs text-[var(--site-text-2)]">Verificación hecha en EscudoSST el {new Date().toLocaleString("es-PE")} · Cada consulta queda registrada.</p>
    </div>
  );
}
