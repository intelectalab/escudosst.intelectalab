"use client";

// Confirmar el correo nuevo (pedido desde «Mi cuenta»). Hasta confirmarlo se sigue ingresando con el anterior.

import { useParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { AuthCard, AuthNotice } from "@/components/auth/AuthCard";
import { api, ApiError } from "@/lib/api";

export default function ConfirmarCorreoPage() {
  const { token } = useParams<{ token: string }>();
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current) return;   // en desarrollo React monta dos veces: el enlace es de un solo uso
    ran.current = true;
    api<{ data: { email: string } }>(`/auth/email/confirm/${token}`, { method: "POST" })
      .then((r) => setResult({ ok: true, text: r.data.email }))
      .catch((e) => setResult({ ok: false, text: e instanceof ApiError ? e.message : "No pudimos confirmar el correo." }));
  }, [token]);

  return (
    <AuthCard>
      {!result ? (
        <div className="h-40 animate-pulse rounded-xl bg-line/40" aria-label="Confirmando" />
      ) : result.ok ? (
        <AuthNotice icon="check" tone="ok" title="Correo confirmado">
          <p>
            Desde ahora ingresas con <b className="text-fg">{result.text}</b>.
          </p>
        </AuthNotice>
      ) : (
        <AuthNotice icon="clock" tone="amber" title="No se pudo confirmar">
          <p>{result.text}</p>
          <p>Puedes volver a pedir el cambio desde «Mi cuenta».</p>
        </AuthNotice>
      )}
    </AuthCard>
  );
}
