"use client";

// Sitio web › Lineamientos de contenido (SCR-WEB-68): reglas para publicar solo contenido veraz, las frases bloqueadas
// (no se puede publicar nada que las contenga) y la revisión automática de lo que hoy está en el sitio.

import Link from "next/link";
import { useEffect, useState } from "react";
import { dt, Guide, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api } from "@/lib/api";

type Data = {
  terms: string[];
  comparison_terms: string[];
  rules: [string, string][];
  findings: { source: string; title: string; term: string; published: boolean; href: string }[];
  checked_at: string;
};

export default function LineamientosPage() {
  const { res, error, load } = usePanelList<{ data: Data }>("/internal/site/guidelines");
  const d = res?.data;
  const [terms, setTerms] = useState("");
  const [comparison, setComparison] = useState("");
  const [toast, setToast] = useState<string | null>(null);
  const { message, saving, run } = useSave();

  useEffect(() => {
    if (d) {
      setTerms(d.terms.join("\n"));
      setComparison(d.comparison_terms.join("\n"));
    }
  }, [d]);

  async function save() {
    const lines = (v: string) => v.split("\n").map((x) => x.trim()).filter(Boolean);
    const r = await run(() => api("/internal/site/guidelines", { method: "PUT", body: { terms: lines(terms), comparison_terms: lines(comparison) } }));
    if (r !== null) {
      setToast("Lineamientos guardados y contenido revisado de nuevo.");
      load();
    }
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Lineamientos de contenido"]]}
        icon="checklist"
        title="Lineamientos de contenido"
        description="Para publicar solo lo que EscudoSST puede respaldar. Si un texto contiene una frase bloqueada, el sistema no deja publicarlo (páginas, novedades, webinars, ventanas, integraciones y ajustes del sitio)."
      />
      <Guide
        what="Reglas para que el sitio publique solo lo que EscudoSST puede respaldar. Se basan en el Código de Protección y Defensa del Consumidor (Ley 29571), que exige publicidad veraz, y en las correcciones del diseño del sitio."
        where="Se aplican solas: si un texto tiene una frase bloqueada, el sistema no deja publicarlo (páginas, novedades, webinars, ventanas e integraciones)."
        steps={["Mira la «Revisión automática»: lista los textos que incumplen.", "Agrega o quita frases bloqueadas (una por línea).", "«Guardar»."]}
      />
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
      {d && (
        <>
          <section className={`form-section space-y-3 ${d.findings.some((x) => x.published) ? "border-bad/40" : ""}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-display text-base font-bold">Revisión automática</h2>
              <span className="text-xs text-muted">Revisado: {dt(d.checked_at)}</span>
            </div>
            {d.findings.length === 0 ? (
              <p className="flex items-center gap-2 rounded-xl bg-ok-soft px-4 py-3 text-sm text-ok">
                <Icon name="check" size={17} /> Todo lo que hay en el sitio cumple los lineamientos.
              </p>
            ) : (
              <ul className="divide-y divide-line">
                {d.findings.map((x, k) => (
                  <li key={k} className="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                    <Chip tone={x.published ? "bad" : "amber"}>{x.published ? "Publicado" : "Borrador"}</Chip>
                    <span className="min-w-0 flex-1">
                      <b>{x.source}:</b> {x.title} — contiene «{x.term}»
                    </span>
                    <Link href={x.href} className="text-xs font-semibold text-brand-strong hover:underline">
                      Corregir
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="form-section space-y-3">
            <h2 className="font-display text-base font-bold">Reglas</h2>
            <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
              {d.rules.map(([t, text]) => (
                <li key={t} className="flex items-start gap-3 rounded-xl bg-bg p-3 text-sm">
                  <Icon name="check" size={16} className="mt-0.5 shrink-0 text-ok" />
                  <span>
                    <b className="block">{t}</b>
                    <span className="text-muted">{text}</span>
                  </span>
                </li>
              ))}
            </ul>
          </section>

          <section className="form-section space-y-4">
            {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <label className="block">
                <span className="mb-1.5 block text-sm font-semibold">Frases bloqueadas en todo el sitio</span>
                <span className="mb-2 block text-xs text-muted">Una por línea. No distingue mayúsculas. Ej.: «Cero multas», «ISO 27001».</span>
                <textarea className="input min-h-[220px] font-mono text-xs" value={terms} onChange={(e) => setTerms(e.target.value)} />
              </label>
              <label className="block">
                <span className="mb-1.5 block text-sm font-semibold">Además, en la comparativa</span>
                <span className="mb-2 block text-xs text-muted">La página «EscudoSST vs Excel y papel» no puede tener precios. Ej.: «S/», «precio».</span>
                <textarea className="input min-h-[220px] font-mono text-xs" value={comparison} onChange={(e) => setComparison(e.target.value)} />
              </label>
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <Button variant="primary" icon="check" loading={saving} onClick={save}>
                Guardar
              </Button>
              <span className="text-xs text-muted">Solo el Dueño puede cambiar las listas.</span>
            </div>
          </section>
        </>
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}
