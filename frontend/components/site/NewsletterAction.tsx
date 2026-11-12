"use client";

// Confirmar la suscripción o darse de baja con el enlace del correo. La baja pide un clic en la página (no basta
// abrir el enlace), así los antivirus que «abren» los enlaces del correo no dan de baja a nadie por su cuenta.

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { Icon } from "@/components/ui/Icon";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export function NewsletterAction({ kind, token, issue }: { kind: "confirm" | "unsubscribe"; token: string; issue?: string }) {
  const [state, setState] = useState<"idle" | "loading" | "ok" | "invalid" | "error">(kind === "confirm" ? "loading" : "idle");
  const ran = useRef(false);

  async function run() {
    setState("loading");
    try {
      const url = kind === "confirm" ? `${API}/api/v1/public/newsletter/confirm` : `${API}/api/v1/public/newsletter/unsubscribe/${token}${issue ? `?e=${encodeURIComponent(issue)}` : ""}`;
      const res = await fetch(url, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: kind === "confirm" ? JSON.stringify({ token }) : undefined });
      const j = await res.json().catch(() => ({}));
      const r = j?.data?.result;
      setState(r === "confirmed" || r === "unsubscribed" ? "ok" : res.ok || res.status === 404 ? "invalid" : "error");
    } catch {
      setState("error");
    }
  }

  useEffect(() => {
    if (kind === "confirm" && !ran.current) {
      ran.current = true;
      run();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const box = (icon: string, tone: string, title: string, text: React.ReactNode, cta?: React.ReactNode) => (
    <div className="site-card mx-auto max-w-xl p-8 text-center" role="status">
      <span className={`mx-auto grid h-14 w-14 place-items-center rounded-full ${tone}`}>
        <Icon name={icon} size={26} />
      </span>
      <h1 className="mt-4 font-display text-2xl font-bold text-[var(--site-navy-title)]">{title}</h1>
      <div className="mx-auto mt-2 max-w-md text-[var(--site-text-2)]">{text}</div>
      {cta && <div className="mt-5 flex flex-wrap justify-center gap-2">{cta}</div>}
    </div>
  );
  const again = (
    <Link href="/boletin#suscribirme" className="site-btn site-btn-primary min-h-11">
      Suscribirme de nuevo
    </Link>
  );

  if (state === "loading") return box("clock", "bg-[#EAF3FC] text-[#1b6fb7]", "Un momento…", "Estamos procesando tu enlace.");
  if (state === "error")
    return box(
      "close",
      "bg-[#FDECEB] text-[#C8372F]",
      "No pudimos completar la acción",
      "Revisa tu conexión e inténtalo otra vez.",
      <button onClick={run} className="site-btn site-btn-primary min-h-11">
        Reintentar
      </button>,
    );
  if (kind === "confirm") {
    return state === "ok"
      ? box(
          "check",
          "bg-[#E8F7EE] text-[#22A35A]",
          "¡Suscripción confirmada!",
          "Desde ahora recibirás los correos que elegiste. Puedes darte de baja con un clic en cualquiera de ellos.",
          <Link href="/boletin" className="site-btn site-btn-outline min-h-11">
            Ver ediciones anteriores
          </Link>,
        )
      : box("clock", "bg-[#FFF5D6] text-[#B88700]", "El enlace venció o ya se usó", "Si ya lo confirmaste, no tienes que hacer nada más. Si no, suscríbete de nuevo y te enviaremos otro enlace.", again);
  }
  if (state === "ok") return box("check", "bg-[#E8F7EE] text-[#22A35A]", "Te diste de baja", "Ya no recibirás más correos del boletín ni avisos de estado. Si fue un error, puedes volver a suscribirte cuando quieras.", again);
  if (state === "invalid") return box("close", "bg-[#FDECEB] text-[#C8372F]", "El enlace no es válido", "Puede que ya no estés suscrito. Si sigues recibiendo correos, escríbenos.", again);
  return box(
    "mail",
    "bg-[#EAF3FC] text-[#1b6fb7]",
    "¿Darte de baja?",
    "Dejarás de recibir el boletín normativo y los avisos de estado del servicio.",
    <button onClick={run} className="site-btn site-btn-primary min-h-11">
      Sí, darme de baja
    </button>,
  );
}
