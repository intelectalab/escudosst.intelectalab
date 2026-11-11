"use client";

// Expediente SUNAFIL compartido con el inspector por un enlace temporal (sin sesión): índice de lo que la empresa
// tiene listo para la inspección, con lo incluido y lo pendiente. El enlace vence solo y cuenta cada visita.

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";

type Item = { code: string; title: string; status: string; gap: string | null; per_worker: boolean };
type Data = {
  company: { name: string; ruc: string };
  dossier: { title: string; inspection_order_number: string | null; period_from: string; period_to: string; built_at: string | null };
  items: Item[];
  expires_at: string;
  views_left: number | null;
  allow_download?: boolean;
  downloads_left?: number | null;
};

const ST: Record<string, [string, string]> = {
  included: ["Incluido", "bg-[#E8F7EE] text-[#17703e]"],
  missing: ["Pendiente", "bg-[#FDECEB] text-[#a3261f]"],
  expired: ["Vencido", "bg-[#FFF6DB] text-[#7a5300]"],
  not_applicable: ["No aplica", "bg-[#EEF1F5] text-[#4a5563]"],
};
const d = (v: string | null) => (v ? new Date(v.length === 10 ? `${v}T12:00:00-05:00` : v).toLocaleDateString("es-PE", { timeZone: "America/Lima" }) : "—");

export default function ExpedienteCompartido() {
  const { company, token } = useParams<{ company: string; token: string }>();
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [dl, setDl] = useState<{ busy: boolean; msg: string | null }>({ busy: false, msg: null });
  const download = () => {
    setDl({ busy: true, msg: null });
    downloadFile(`/public/sun/${company}/${token}/download`, "expediente.zip")
      .then(() => setDl({ busy: false, msg: "Descarga lista." }))
      .catch((e: ApiError) => setDl({ busy: false, msg: e.detail ?? e.title }));
  };
  useEffect(() => {
    api<{ data: Data }>(`/public/sun/${company}/${token}`).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? "El enlace no es válido."));
  }, [company, token]);

  return (
    <main className="min-h-screen bg-[#EEF4FB] px-4 py-8 text-[#0b2440]">
      <div className="mx-auto max-w-3xl space-y-4">
        <p className="text-sm font-bold uppercase tracking-wider text-[#1f7fd1]">Expediente para inspección · SUNAFIL</p>
        {error && <div className="rounded-2xl bg-white p-6 shadow">{error}</div>}
        {!error && !data && <div className="rounded-2xl bg-white p-6 shadow">Cargando…</div>}
        {data && (
          <div className="relative space-y-4 overflow-hidden rounded-2xl bg-white p-6 shadow">
            {/* Marca de agua (RN-SUN-10): empresa, expediente y fecha de consulta. */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 flex select-none items-center justify-center">
              <span className="-rotate-[24deg] whitespace-nowrap text-2xl font-bold text-[#0b2440] opacity-[0.06] sm:text-4xl">
                {data.company.name} · {data.dossier.inspection_order_number ?? data.dossier.title} · {new Date().toLocaleDateString("es-PE", { timeZone: "America/Lima" })}
              </span>
            </div>
            <div>
              <h1 className="text-2xl font-bold">{data.company.name}</h1>
              <p className="text-sm text-[#5b6b7c]">RUC {data.company.ruc} · {data.dossier.title}{data.dossier.inspection_order_number ? ` · Orden ${data.dossier.inspection_order_number}` : ""}</p>
              <p className="text-sm text-[#5b6b7c]">Periodo: {d(data.dossier.period_from)} al {d(data.dossier.period_to)} · Revisado: {d(data.dossier.built_at)}</p>
            </div>
            <ul className="divide-y divide-[#e3eaf2]">
              {data.items.map((it) => {
                const [label, cls] = ST[it.status] ?? [it.status, "bg-[#EEF1F5]"];
                return (
                  <li key={it.code} className="flex flex-wrap items-start justify-between gap-2 py-2.5">
                    <span>
                      <b>{it.code}.</b> {it.title}
                      {it.gap && <span className="block text-xs text-[#5b6b7c]">{it.gap}</span>}
                    </span>
                    <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${cls}`}>{label}</span>
                  </li>
                );
              })}
            </ul>
            {data.allow_download && (
              <div className="flex flex-wrap items-center gap-3">
                <button type="button" disabled={dl.busy} onClick={download} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                  {dl.busy ? "Descargando…" : "Descargar expediente (ZIP)"}
                </button>
                {data.downloads_left != null && <span className="text-xs text-[#5b6b7c]">Descargas restantes: {data.downloads_left}</span>}
                {dl.msg && <span className="text-xs text-[#5b6b7c]">{dl.msg}</span>}
              </div>
            )}
            <p className="text-xs text-[#5b6b7c]">
              Enlace disponible hasta el {new Date(data.expires_at).toLocaleString("es-PE", { timeZone: "America/Lima" })}
              {data.views_left !== null ? ` · visitas restantes: ${data.views_left}` : ""}. Los documentos originales se presentan en la diligencia.
            </p>
          </div>
        )}
        <p className="text-center text-xs text-[#5b6b7c]">Generado con EscudoSST</p>
      </div>
    </main>
  );
}
