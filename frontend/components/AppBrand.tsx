"use client";

// Marca en el sistema (login, panel de la plataforma y panel de las empresas): el logo que subió el dueño en
// Sitio web › Logo y menú; si no hay, el de EscudoSST. Se guarda 5 minutos por pestaña.
// `light` = sobre fondo oscuro: usa el «logo para fondo oscuro» y, si no hay, el normal sobre una placa blanca.

import { useEffect, useState } from "react";
import { Brand } from "@/components/Brand";
import { assetUrl } from "@/lib/site";

type Marca = { logo: string | null; logoDark: string | null; name: string; logoUrl?: string | null };

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const KEY = "escudo.brand";
let pending: Promise<Marca> | null = null;

function load(): Promise<Marca> {
  const cached = typeof window !== "undefined" ? window.sessionStorage.getItem(KEY) : null;
  // Se guarda 5 minutos: si el dueño cambia o quita el logo, las pestañas abiertas lo ven sin cerrar sesión.
  if (cached) {
    const c = JSON.parse(cached) as Marca & { at?: number };
    if (c.at && Date.now() - c.at < 5 * 60 * 1000) return Promise.resolve(c);
  }
  // M-CON: en el dominio propio de una consultora (verificado) se muestra su marca desde el login.
  const host = typeof window !== "undefined" ? window.location.hostname : "";
  const main = (() => { try { return new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "").hostname; } catch { return ""; } })();
  if (host && main && host !== main && host !== "localhost" && host !== "127.0.0.1") {
    pending ??= fetch(`${API}/api/v1/public/brand-by-host?host=${encodeURIComponent(host)}`, { headers: { Accept: "application/json" } })
      .then((r) => r.json())
      .then((j) => {
        const b = j.data;
        const m: Marca = b ? { logo: null, logoDark: null, name: b.brand_name || "EscudoSST", logoUrl: b.logo ?? null } : { logo: null, logoDark: null, name: "EscudoSST" };
        window.sessionStorage.setItem(KEY, JSON.stringify({ ...m, at: Date.now() }));
        return m;
      })
      .catch(() => ({ logo: null, logoDark: null, name: "EscudoSST" }));
    return pending;
  }
  pending ??= fetch(`${API}/api/v1/public/site`, { headers: { Accept: "application/json" } })
    .then((r) => r.json())
    .then((j) => {
      const s = j.data?.settings ?? {};
      const m: Marca = { logo: s.logo_file_id ?? null, logoDark: s.logo_dark_file_id ?? null, name: s.trade_name || "EscudoSST" };
      window.sessionStorage.setItem(KEY, JSON.stringify({ ...m, at: Date.now() }));
      return m;
    })
    .catch(() => ({ logo: null, logoDark: null, name: "EscudoSST" }));
  return pending;
}

export function AppBrand({ light = false, compact = false }: { light?: boolean; compact?: boolean }) {
  const [m, setM] = useState<Marca | null>(null);
  useEffect(() => {
    load().then(setM);
  }, []);

  const file = m && (light ? (m.logoDark ?? m.logo) : m.logo);
  const src = m?.logoUrl ?? (file ? assetUrl(file) : null);
  if (!m || !src) return <Brand light={light} name={m?.name} compact={compact} />;
  const plate = light && !m.logoDark;   // logo normal sobre fondo oscuro: placa blanca para que se vea
  return (
    <div className="flex items-center gap-2.5">
      <span className={`grid h-[34px] shrink-0 place-items-center ${plate ? "rounded-lg bg-white px-1.5" : ""}`}>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src} alt={m.name} className={`h-[28px] w-auto object-contain ${compact ? "max-w-[30px]" : "max-w-[120px]"}`} />
      </span>
      {!compact && <span className={`font-display text-xl font-semibold ${light ? "text-white" : "text-fg"}`}>{m.name}</span>}
    </div>
  );
}
