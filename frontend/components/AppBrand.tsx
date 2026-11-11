"use client";

// Marca en el sistema (login, panel de la plataforma y panel de las empresas): el logo que subió el dueño en
// Sitio web › Logo y menú; si no hay, el de EscudoSST. Se lee una sola vez por pestaña.
// `light` = sobre fondo oscuro: usa el «logo para fondo oscuro» y, si no hay, el normal sobre una placa blanca.

import { useEffect, useState } from "react";
import { Brand } from "@/components/Brand";
import { assetUrl } from "@/lib/site";

type Marca = { logo: string | null; logoDark: string | null; name: string };

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const KEY = "escudo.brand";
let pending: Promise<Marca> | null = null;

function load(): Promise<Marca> {
  const cached = typeof window !== "undefined" ? window.sessionStorage.getItem(KEY) : null;
  if (cached) return Promise.resolve(JSON.parse(cached) as Marca);
  pending ??= fetch(`${API}/api/v1/public/site`, { headers: { Accept: "application/json" } })
    .then((r) => r.json())
    .then((j) => {
      const s = j.data?.settings ?? {};
      const m: Marca = { logo: s.logo_file_id ?? null, logoDark: s.logo_dark_file_id ?? null, name: s.trade_name || "EscudoSST" };
      window.sessionStorage.setItem(KEY, JSON.stringify(m));
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
  if (!m || !file) return <Brand light={light} name={m?.name} compact={compact} />;
  const plate = light && !m.logoDark;   // logo normal sobre fondo oscuro: placa blanca para que se vea
  return (
    <div className="flex items-center gap-2.5">
      <span className={`grid h-[34px] shrink-0 place-items-center ${plate ? "rounded-lg bg-white px-1.5" : ""}`}>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={assetUrl(file) ?? ""} alt={m.name} className={`h-[28px] w-auto object-contain ${compact ? "max-w-[30px]" : "max-w-[120px]"}`} />
      </span>
      {!compact && <span className={`font-display text-xl font-semibold ${light ? "text-white" : "text-fg"}`}>{m.name}</span>}
    </div>
  );
}
