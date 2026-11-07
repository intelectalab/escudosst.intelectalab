"use client";

// Avisos del estado del servicio por correo (RN-WEB-54): doble confirmación y baja de un clic. No crea un interesado.

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Honeypot } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export function StatusSubscribe() {
  const [email, setEmail] = useState("");
  const [privacy, setPrivacy] = useState(false);
  const [hp, setHp] = useState("");
  const [state, setState] = useState<"idle" | "sending" | "sent">("idle");
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setState("sending");
    setError(null);
    try {
      const res = await fetch(`${API}/api/v1/public/status/subscribe`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ email, privacy_consent: privacy, website_hp: hp }),
      });
      const j = await res.json().catch(() => ({}));
      if (res.ok) return setState("sent");
      setError(res.status === 429 ? "Recibimos varios envíos seguidos. Espera unos minutos." : (Object.values(j.errors ?? {})[0] as string[] | undefined)?.[0] ?? "No pudimos suscribirte.");
    } catch {
      setError("No hay conexión. Inténtalo otra vez.");
    }
    setState("idle");
  }

  if (state === "sent") {
    return (
      <p className="flex items-start gap-2 rounded-xl bg-[#E8F7EE] p-4 text-sm font-semibold text-[#17703e]" role="status">
        <Icon name="mail" size={18} /> Revisa tu correo y abre el enlace para confirmar los avisos.
      </p>
    );
  }
  return (
    <form onSubmit={submit} noValidate className="relative space-y-3">
      <Honeypot value={hp} onChange={setHp} />
      <div className="flex flex-col gap-2 sm:flex-row">
        <label className="min-w-0 flex-1">
          <span className="sr-only">Tu correo</span>
          <input className="input" type="email" placeholder="tu@empresa.com" maxLength={160} value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <button type="submit" disabled={state === "sending"} className="site-btn site-btn-primary min-h-11 disabled:opacity-60">
          {state === "sending" ? "Enviando…" : "Avisarme"}
        </button>
      </div>
      <label className="flex items-start gap-2 text-sm text-[var(--site-text-2)]">
        <input type="checkbox" className="mt-0.5 h-4 w-4 accent-[var(--site-action)]" checked={privacy} onChange={(e) => setPrivacy(e.target.checked)} />
        <span>
          Acepto la{" "}
          <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
            Política de privacidad
          </Link>{" "}
          para recibir avisos de interrupciones y mantenimientos.
        </span>
      </label>
      {error && <p className="text-sm font-medium text-[#A3271F]">{error}</p>}
    </form>
  );
}
