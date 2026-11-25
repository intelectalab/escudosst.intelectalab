"use client";

// Página pública de verificación de certificados de una empresa (M-CAP SCR-CAP-12/13): nombre de la empresa, su texto propio y el
// campo «Código del certificado». El QR del certificado ya lleva a la verificación con el código.

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

type Page = { result: string; company?: string; enabled?: boolean; text?: string };

function useCompanyPage(company: string) {
  const [p, setP] = useState<Page | null>(null);
  useEffect(() => {
    api<{ data: Page }>(`/public/verify/company/${encodeURIComponent(company)}`).then((r) => setP(r.data)).catch(() => setP({ result: "error" }));
  }, [company]);
  return p;
}

/** Texto de la empresa sobre el resultado de una verificación hecha desde su página. */
export function CompanyVerifyText({ company }: { company: string }) {
  const p = useCompanyPage(company);
  if (!p || p.result !== "ok" || !p.text) return null;
  return <p className="site-card whitespace-pre-line p-4 text-sm">{p.text}</p>;
}

export function CompanyVerifyPage({ company }: { company: string }) {
  const p = useCompanyPage(company);
  const router = useRouter();
  const [code, setCode] = useState("");
  if (!p) return <p className="site-card p-6">Cargando…</p>;
  if (p.result !== "ok") return <p className="site-card p-6">No encontramos la página de verificación de esta empresa.</p>;
  const clean = code.replace(/[^A-Za-z0-9-]/g, "").toUpperCase();
  return (
    <div className="space-y-4">
      <p className="text-lg font-semibold">{p.company}</p>
      {!p.enabled ? (
        <p className="site-card p-6">La empresa no tiene activada la verificación pública de sus certificados.</p>
      ) : (
        <>
          {p.text && <p className="site-card whitespace-pre-line p-4 text-sm">{p.text}</p>}
          <form className="site-card space-y-3 p-6" onSubmit={(e) => { e.preventDefault(); if (clean.length >= 4) router.push(`/verificar/certificado/${clean}?empresa=${company}`); }}>
            <label className="block text-sm font-semibold">Código del certificado
              <input className="mt-1 w-full rounded-lg border border-[var(--site-line,#d0d7e2)] px-3 py-2 font-mono uppercase" value={code} maxLength={40}
                autoComplete="off" onChange={(e) => setCode(e.target.value)} placeholder="El código impreso junto al QR" />
            </label>
            <button type="submit" disabled={clean.length < 4} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Verificar</button>
            <p className="text-xs text-[var(--site-text-2)]">También puedes escanear el QR del certificado con la cámara de tu teléfono: abre esta verificación directamente.</p>
          </form>
        </>
      )}
    </div>
  );
}
