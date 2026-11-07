"use client";

// Aviso superior del estado de la suscripción (GET /me/subscription):
//  - por vencer (5 días o menos) y pago pendiente: a quien administra o paga (administrador de cuenta, Jefe SST);
//  - solo lectura o prueba vencida: a todos, porque no podrán registrar.

import { useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";
import { formatDate } from "@/lib/accounts";

type Status = {
  status: string;
  plan_name: string;
  trial_ends_at: string | null;
  current_period_end: string | null;
  grace_until: string | null;
  days_left: number | null;
  reminder_days: number;
  billing_contact: boolean;
};

export function SubscriptionBanner() {
  const [s, setS] = useState<Status | null>(null);

  useEffect(() => {
    api<{ data: Status | null }>("/me/subscription", { withCompany: true })
      .then((r) => setS(r.data))
      .catch(() => undefined);
  }, []);

  if (!s) return null;

  let tone = "";
  let icon = "clock";
  let title = "";
  let text = "";
  const pay = "Para pagar o enviar tu comprobante, comunícate con el equipo de EscudoSST.";

  if (s.status === "read_only" || s.status === "trial_expired") {
    tone = "border-bad/30 bg-bad-soft text-bad";
    icon = "lock";
    title = s.status === "trial_expired" ? "Terminó la prueba gratuita: la cuenta está en solo lectura" : "La cuenta está en solo lectura";
    text = `Puedes ver y descargar tus registros, pero no crear ni editar. ${s.billing_contact ? `Se desbloquea al momento en que se registre el pago. ${pay}` : "Avisa a quien administra la cuenta."}`;
  } else if (s.status === "past_due" && s.billing_contact) {
    tone = "border-amber-ink/30 bg-amber-soft text-amber-ink";
    title = "Tu suscripción venció: pago pendiente";
    text = `Puedes seguir trabajando hasta el ${formatDate(s.grace_until)}. Si para entonces no se registra el pago, la cuenta quedará en solo lectura. ${pay}`;
  } else if ((s.status === "active" || s.status === "trialing") && s.billing_contact && s.days_left !== null && s.days_left >= 0 && s.days_left <= s.reminder_days) {
    tone = "border-amber-ink/30 bg-amber-soft text-amber-ink";
    const when = s.days_left === 0 ? "hoy" : `en ${s.days_left} ${s.days_left === 1 ? "día" : "días"}`;
    const end = s.status === "trialing" ? s.trial_ends_at : s.current_period_end;
    title = s.status === "trialing" ? `Tu prueba gratuita termina ${when}` : `Tu plan ${s.plan_name} vence ${when}`;
    text = `${s.status === "trialing" ? "La prueba termina" : "El periodo termina"} el ${formatDate(end)}. ${pay}`;
  }

  if (!title) return null;
  return (
    <div role="status" className={`mb-5 flex items-start gap-3 rounded-2xl border px-4 py-3 ${tone}`}>
      <Icon name={icon} size={20} className="mt-0.5 shrink-0" />
      <div>
        <p className="font-semibold">{title}</p>
        <p className="mt-0.5 text-sm opacity-90">{text}</p>
      </div>
    </div>
  );
}
