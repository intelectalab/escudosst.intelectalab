"use client";

// Afiche de la política SST para exhibir (M-POL §11 y flujo 4): título, texto, firma de gerencia y QR de verificación pública.
// Se imprime en A4 o A3 desde el diálogo de impresión del navegador.

import { useParams } from "next/navigation";
import { useState } from "react";
import { QrCode } from "@/components/mfa/QrCode";
import { ReportLoading, useReport } from "@/components/modules/FieldReports";
import { session } from "@/lib/session";
import { safeHtml } from "@/lib/workerApp";

type Sig = { signer_name: string; signed_at: string; method: string; image: string | null };
type Data = { company: { name: string; legal_name: string; ruc: string };
  policy: { title: string; version_number: number; status: string; content_html: string | null; effective_on: string | null; approved_at: string | null; approved_by: string | null };
  signatures: Sig[] };

export default function AfichePolitica() {
  const { id } = useParams<{ id: string }>();
  const { data, error } = useReport<Data>(`/reports/sst_policies/${id}`);
  const [size, setSize] = useState<"A4" | "A3">("A4");
  if (!data) return <ReportLoading error={error} />;
  const p = data.policy;
  const url = `${typeof window === "undefined" ? "" : window.location.origin}/verificar/politica/${session.getCompanyId()}/${id}`;
  const sig = data.signatures[0];
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: ${size}; margin: 12mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <label className="text-sm">Tamaño <select className="ml-2 rounded border px-2 py-1" value={size} onChange={(e) => setSize(e.target.value as "A4" | "A3")}><option>A4</option><option>A3</option></select></label>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir afiche</button>
      </div>
      <div className="mx-auto max-w-3xl space-y-5 border-8 border-[#0d3b6e] bg-white p-10 shadow print:max-w-none print:shadow-none">
        <header className="text-center">
          <p className="text-sm font-semibold uppercase tracking-wide text-gray-600">{data.company.legal_name}</p>
          <h1 className="mt-2 text-3xl font-bold uppercase text-[#0d3b6e]">{p.title}</h1>
          <p className="text-sm text-gray-600">Versión {p.version_number}{p.effective_on ? ` · Vigente desde ${p.effective_on.split("-").reverse().join("/")}` : ""}</p>
        </header>
        <div className="prose max-w-none text-base leading-relaxed" dangerouslySetInnerHTML={{ __html: safeHtml(p.content_html ?? "") }} />
        <footer className="flex items-end justify-between gap-6 border-t pt-4">
          <div className="text-sm">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            {sig?.image && <img src={sig.image} alt={`Firma de ${sig.signer_name}`} className="h-16 object-contain" />}
            <p className="font-semibold">{sig?.signer_name ?? p.approved_by ?? ""}</p>
            <p className="text-gray-600">Gerencia{p.approved_at ? ` · ${new Date(p.approved_at).toLocaleDateString("es-PE")}` : ""}</p>
          </div>
          <div className="text-center text-xs text-gray-600">
            <QrCode value={url} size={110} alt="QR para verificar la política" />
            <p>Verifica esta política</p>
          </div>
        </footer>
      </div>
    </div>
  );
}
