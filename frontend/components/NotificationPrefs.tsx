"use client";

// SCR-NOT-03 · Mis preferencias de avisos: por módulo, correo y WhatsApp; modo resumen diario; número de WhatsApp.
// La campana siempre muestra todos los avisos y los críticos llegan aunque se apague un canal (RN-NOT-05).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Switch } from "@/components/ui/Switch";

type Prefs = {
  modules: { code: string; name: string }[];
  channels: { email: Record<string, boolean>; whatsapp: Record<string, boolean> };
  digest_daily: boolean;
  whatsapp: { verified: boolean; last4: string | null };
};

export function NotificationPrefs() {
  const [p, setP] = useState<Prefs | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => {
    api<{ data: Prefs }>("/me/notification-prefs", { withCompany: true }).then((r) => setP(r.data)).catch(() => setP(null));
  }, []);
  useEffect(load, [load]);

  if (!p) return null;
  const set = (ch: "email" | "whatsapp", code: string, v: boolean) => setP({ ...p, channels: { ...p.channels, [ch]: { ...p.channels[ch], [code]: v } } });
  const save = () => {
    setBusy(true);
    api<{ data: Prefs }>("/me/notification-prefs", { method: "PUT", withCompany: true, body: { digest_daily: p.digest_daily, channels: p.channels } })
      .then((r) => { setP(r.data); setMsg({ text: "Preferencias guardadas.", ok: true }); })
      .catch((e: ApiError) => setMsg({ text: e.detail ?? e.title, ok: false }))
      .finally(() => setBusy(false));
  };

  return (
    <section id="avisos" className="scroll-mt-24 space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <div>
        <h2 className="font-display text-lg font-semibold">Mis avisos</h2>
        <p className="text-sm text-muted">Elige por dónde te avisamos de cada módulo. En la campana siempre verás todos tus avisos, y los críticos (como un accidente grave) te llegan igual.</p>
      </div>
      <label className="flex items-center justify-between gap-3 rounded-xl border border-line p-3">
        <span>
          <b className="block text-sm">Resumen diario por correo</b>
          <span className="block text-sm text-muted">Recibe un solo correo al día con tus avisos, en lugar de uno por cada aviso.</span>
        </span>
        <Switch checked={p.digest_daily} onChange={(v) => setP({ ...p, digest_daily: v })} label="Resumen diario por correo" />
      </label>
      <p className="text-sm">
        WhatsApp: {p.whatsapp.verified ? `activo en tu número terminado en ${p.whatsapp.last4 ?? "…"}.` : "no tienes un número aceptado para avisos. Pide a RR.HH. o a SST que lo registren en tu ficha."}
      </p>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-muted">
              <th className="py-2 pr-3 font-medium">Módulo</th>
              <th className="px-3 py-2 font-medium">Correo</th>
              <th className="px-3 py-2 font-medium">WhatsApp</th>
            </tr>
          </thead>
          <tbody>
            {p.modules.map((m) => (
              <tr key={m.code} className="border-t border-line">
                <td className="py-2 pr-3">{m.name}</td>
                <td className="px-3 py-2"><Switch checked={p.channels.email[m.code] ?? true} onChange={(v) => set("email", m.code, v)} label={`Correo de ${m.name}`} /></td>
                <td className="px-3 py-2"><Switch checked={p.channels.whatsapp[m.code] ?? true} onChange={(v) => set("whatsapp", m.code, v)} label={`WhatsApp de ${m.name}`} disabled={!p.whatsapp.verified} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Button variant="primary" onClick={save} disabled={busy}>Guardar preferencias</Button>
      {msg && <p className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </section>
  );
}
