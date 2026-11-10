"use client";

// Marca blanca (M-CON): si la empresa la gestiona una consultora con marca activa, el sistema muestra el nombre,
// logo y colores de la consultora (y, si así lo eligió, «Con tecnología EscudoSST»). También el panel donde la consultora
// configura su marca.

import { useCallback, useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { api, ApiError } from "@/lib/api";

type Brand = { brand_name: string; logo: string | null; primary_color: string | null; secondary_color: string | null; pdf_footer_text: string | null; show_powered_by: boolean; active: boolean };
let cache: { company: string; p: Promise<Brand | null> } | null = null;

export function useCompanyBrand(companyId: string): Brand | null {
  const [b, setB] = useState<Brand | null>(null);
  useEffect(() => {
    if (!cache || cache.company !== companyId) {
      cache = { company: companyId, p: api<{ data: Brand | null }>("/brand", { withCompany: true }).then((r) => r.data).catch(() => null) };
    }
    cache.p.then(setB);
  }, [companyId]);
  useEffect(() => {
    const root = document.documentElement;
    if (b?.primary_color) {
      root.style.setProperty("--brand", b.primary_color);
      root.style.setProperty("--brand-strong", b.secondary_color ?? b.primary_color);
    } else {
      root.style.removeProperty("--brand");
      root.style.removeProperty("--brand-strong");
    }
  }, [b]);
  return b;
}

/** Logo del menú: el de la consultora si tiene marca blanca activa; si no, el de EscudoSST. */
export function CompanyBrand({ companyId }: { companyId: string }) {
  const b = useCompanyBrand(companyId);
  if (!b) return <AppBrand light />;
  return (
    <div className="space-y-1">
      <div className="flex items-center gap-2.5">
        {b.logo && (
          <span className="grid h-[34px] shrink-0 place-items-center rounded-lg bg-white px-1.5">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={b.logo} alt={b.brand_name} className="h-[28px] w-auto max-w-[120px] object-contain" />
          </span>
        )}
        <span className="font-display text-xl font-semibold text-white">{b.brand_name}</span>
      </div>
      {b.show_powered_by && <p className="text-[10px] uppercase tracking-wider text-white/50">Con tecnología EscudoSST</p>}
    </div>
  );
}

/** Panel de la consultora: su marca para las empresas cliente. */
export function WhiteLabelPanel() {
  const [d, setD] = useState<(Brand & { remove_logo?: boolean; newLogo?: string | null }) | null>(null);
  const [clients, setClients] = useState(0);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [forbidden, setForbidden] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Brand | null; clients: number }>("/con/white-label", { withCompany: true })
      .then((r) => { setD(r.data ?? { brand_name: "", logo: null, primary_color: "#1F7FD1", secondary_color: "#0D3B6E", pdf_footer_text: "", show_powered_by: true, active: false }); setClients(r.clients); })
      .catch((e: ApiError) => setForbidden(e.detail ?? e.title));
  }, []);
  useEffect(load, [load]);
  if (forbidden) return <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">{forbidden}</p>;
  if (!d) return null;
  const pickLogo = (f: File) => {
    if (f.size > 300000) return setMsg({ text: "El logo debe pesar menos de 300 KB.", ok: false });
    const r = new FileReader();
    r.onload = () => setD({ ...d, newLogo: String(r.result), logo: String(r.result), remove_logo: false });
    r.readAsDataURL(f);
  };
  const save = () =>
    api("/con/white-label", { method: "PUT", withCompany: true, body: { brand_name: d.brand_name, primary_color: d.primary_color, secondary_color: d.secondary_color,
      logo: d.newLogo ?? null, remove_logo: !!d.remove_logo, pdf_footer_text: d.pdf_footer_text, show_powered_by: d.show_powered_by, active: d.active } })
      .then(() => { setMsg({ text: d.active ? "Marca guardada y activa: tus empresas cliente ya la ven." : "Marca guardada (aún no activa).", ok: true }); cache = null; load(); })
      .catch((e: ApiError) => setMsg({ text: Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title, ok: false }));

  return (
    <section className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <div>
        <h2 className="font-display text-lg font-semibold">Marca blanca</h2>
        <p className="text-sm text-muted">Tus {clients} {clients === 1 ? "empresa cliente ve" : "empresas cliente ven"} EscudoSST con tu nombre, logo y colores (menú, inicio, certificados y fotochecks).</p>
      </div>
      <div className="grid gap-4 md:grid-cols-[1fr_16rem]">
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Nombre de tu consultora"><input className="input" maxLength={60} value={d.brand_name} onChange={(e) => setD({ ...d, brand_name: e.target.value })} /></FormField>
          <FormField label="Logo (PNG o JPG, menos de 300 KB)">
            <input type="file" accept="image/png,image/jpeg" className="input" onChange={(e) => e.target.files?.[0] && pickLogo(e.target.files[0])} />
          </FormField>
          <FormField label="Color principal"><input type="color" className="input h-10 p-1" value={d.primary_color ?? "#1F7FD1"} onChange={(e) => setD({ ...d, primary_color: e.target.value.toUpperCase() })} /></FormField>
          <FormField label="Color oscuro"><input type="color" className="input h-10 p-1" value={d.secondary_color ?? "#0D3B6E"} onChange={(e) => setD({ ...d, secondary_color: e.target.value.toUpperCase() })} /></FormField>
          <div className="sm:col-span-2"><FormField label="Pie de página en documentos (opcional)"><input className="input" maxLength={200} value={d.pdf_footer_text ?? ""} onChange={(e) => setD({ ...d, pdf_footer_text: e.target.value })} /></FormField></div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={d.show_powered_by} onChange={(e) => setD({ ...d, show_powered_by: e.target.checked })} /> Mostrar «Con tecnología EscudoSST»</label>
          <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={d.active} onChange={(e) => setD({ ...d, active: e.target.checked })} /> Activar la marca para mis clientes</label>
        </div>
        <div className="space-y-2">
          <p className="text-xs font-semibold text-muted">Vista previa del menú</p>
          <div className="rounded-xl p-4" style={{ background: d.secondary_color ?? "#0D3B6E" }}>
            <div className="flex items-center gap-2">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              {d.logo && <span className="rounded bg-white px-1"><img src={d.logo} alt="" className="h-7 object-contain" /></span>}
              <span className="font-display font-semibold text-white">{d.brand_name || "Tu consultora"}</span>
            </div>
            <p className="mt-3 rounded-md px-2 py-1 text-xs font-semibold text-white" style={{ background: d.primary_color ?? "#1F7FD1" }}>Inicio</p>
          </div>
          {d.logo && <button type="button" className="text-xs font-semibold text-bad" onClick={() => setD({ ...d, logo: null, newLogo: null, remove_logo: true })}>Quitar logo</button>}
        </div>
      </div>
      <Button variant="primary" onClick={save} disabled={d.brand_name.trim().length < 2}>Guardar marca</Button>
      {msg && <p className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </section>
  );
}
