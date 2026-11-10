"use client";

// Aviso de «modo de prueba» para módulos que dependen de un proveedor externo (IA, pagos, OSE, WhatsApp, firma digital)
// mientras la plataforma no configure sus claves (Panel › Proveedores y claves).

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Status = Record<string, { name: string; live: boolean; test_note: string }>;
let cache: Promise<Status> | null = null;

export function useProviders() {
  const [s, setS] = useState<Status | null>(null);
  useEffect(() => {
    cache ??= api<{ data: Status }>("/providers/status").then((r) => r.data).catch(() => ({}) as Status);
    cache.then(setS);
  }, []);
  return s;
}

export function TestModeBanner({ code }: { code: "ai" | "payments" | "einvoice" | "whatsapp" | "esign" }) {
  const s = useProviders();
  const p = s?.[code];
  if (!p || p.live) return null;
  return (
    <div role="status" className="flex items-start gap-3 rounded-xl border border-amber/40 bg-amber-soft px-4 py-3 text-sm text-amber-ink">
      <span className="mt-0.5 rounded-md bg-white/70 px-2 py-0.5 text-xs font-bold uppercase tracking-wide">Modo de prueba</span>
      <span>
        <b>{p.name}</b> aún no está conectado. {p.test_note} Cuando EscudoSST active el servicio, funcionará de verdad sin cambiar nada.
      </span>
    </div>
  );
}
