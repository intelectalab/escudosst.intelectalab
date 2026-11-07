"use client";

// Gestionar mi cita (SCR-WEB-53, RN-WEB-71): ver, reprogramar o cancelar sin cuenta. Si el enlace no sirve, el
// mensaje es el mismo exista o no la cita.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { DEMO_API, fetchSlots, limaLong, type Slot, SlotPicker } from "@/components/site/DemoBooking";
import { Icon } from "@/components/ui/Icon";

type Info = { status: string; starts_at: string; ends_at: string; meeting_provider: string; meeting_url: string | null; host_name: string | null; recording_consent: boolean; can_change: boolean };
const STATUS: Record<string, string> = { proposed: "Por confirmar", confirmed: "Confirmada", rescheduled: "Reprogramada", cancelled: "Cancelada", completed: "Realizada", no_show: "No asistió" };
const PROV: Record<string, string> = { meet: "Google Meet", teams: "Microsoft Teams", phone: "Llamada telefónica" };

export function DemoManage({ token }: { token: string }) {
  const [info, setInfo] = useState<Info | null | "invalid">(null);
  const [mode, setMode] = useState<"view" | "move" | "cancel">("view");
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [pick, setPick] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [msg, setMsg] = useState<{ t: string; ok: boolean } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const r = await fetch(`${DEMO_API}/api/v1/public/demo/manage/${token}`, { headers: { Accept: "application/json" } }).catch(() => null);
    setInfo(r?.ok ? ((await r.json()) as { data: Info }).data : "invalid");
  }, [token]);
  useEffect(() => {
    load();
  }, [load]);

  async function post(path: string, body: unknown, ok: string) {
    setBusy(true);
    setMsg(null);
    const r = await fetch(`${DEMO_API}/api/v1/public/demo/manage/${token}/${path}`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }).catch(() => null);
    const j = r ? await r.json().catch(() => ({})) : {};
    if (r?.ok) {
      setMsg({ t: ok, ok: true });
      setMode("view");
      await load();
    } else setMsg({ t: j.detail ?? "No se pudo. Inténtalo otra vez.", ok: false });
    setBusy(false);
  }

  if (info === null) return <div className="site-card mx-auto h-64 max-w-xl animate-pulse" />;
  if (info === "invalid") {
    return (
      <div className="site-card mx-auto max-w-xl p-8 text-center">
        <h1 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">El enlace no es válido o ya venció</h1>
        <p className="mt-2 text-[var(--site-text-2)]">Si quieres otra demo, puedes agendarla de nuevo.</p>
        <Link href="/demo/agendar" className="site-btn site-btn-primary mt-5 inline-flex min-h-11">
          Agendar una demo
        </Link>
      </div>
    );
  }

  return (
    <div className="site-card mx-auto max-w-2xl space-y-5 p-6 sm:p-8">
      <div>
        <p className="site-eyebrow">Tu demo</p>
        <h1 className="mt-1 font-display text-[24px] font-bold text-[var(--site-navy-title)] sm:text-[30px]">{limaLong(info.starts_at)}</h1>
        <p className="text-sm text-[var(--site-text-2)]">
          Hora de Lima · {PROV[info.meeting_provider] ?? info.meeting_provider}
          {info.host_name ? ` · con ${info.host_name}` : ""}
        </p>
        <span className={`mt-3 inline-block rounded-full px-3 py-1 text-xs font-bold ${info.status === "cancelled" ? "bg-[#FDECEB] text-[#a3261f]" : info.status === "proposed" ? "bg-[#FFF5D6] text-[#8a6a00]" : "bg-[#E8F7EE] text-[#17703e]"}`}>
          {STATUS[info.status] ?? info.status}
        </span>
      </div>
      {info.meeting_url && (
        <a href={info.meeting_url} target="_blank" rel="noopener noreferrer" className="site-btn site-btn-primary min-h-11">
          Entrar a la reunión
        </a>
      )}
      <p className="text-sm text-[var(--site-text-2)]">{info.recording_consent ? "Nos diste permiso para grabar la reunión." : "La reunión solo se graba si nos das tu consentimiento."}</p>
      {msg && <p className={`rounded-xl px-4 py-3 text-sm font-semibold ${msg.ok ? "bg-[#E8F7EE] text-[#17703e]" : "bg-[#FDECEB] text-[#a3261f]"}`}>{msg.t}</p>}

      {info.can_change && mode === "view" && (
        <div className="flex flex-col gap-2 sm:flex-row">
          <a href={`${DEMO_API}/api/v1/public/demo/manage/${token}/ics`} className="site-btn site-btn-outline min-h-11">
            <Icon name="clock" size={16} /> Agregar a mi calendario
          </a>
          <button
            className="site-btn site-btn-outline min-h-11"
            onClick={() => {
              setMode("move");
              setPick(null);
              fetchSlots().then((d) => setSlots(d.slots));
            }}
          >
            Cambiar horario
          </button>
          <button className="site-btn min-h-11 text-[#a3261f] hover:bg-[#FDECEB]" onClick={() => setMode("cancel")}>
            Cancelar la demo
          </button>
        </div>
      )}

      {mode === "move" && (
        <div className="space-y-4 border-t border-[var(--site-border)] pt-5">
          <p className="font-semibold text-[var(--site-navy-title)]">Elige el nuevo horario</p>
          {slots === null ? <div className="h-40 animate-pulse rounded-xl bg-[#eef1f5]" /> : <SlotPicker slots={slots} value={pick} onChange={setPick} />}
          <div className="flex flex-col gap-2 sm:flex-row">
            <button disabled={!pick || busy} onClick={() => post("reschedule", { starts_at: pick }, "Listo: cambiamos tu horario. Te enviamos un correo.")} className="site-btn site-btn-primary min-h-11 disabled:opacity-60">
              Confirmar el cambio
            </button>
            <button onClick={() => setMode("view")} className="site-btn site-btn-outline min-h-11">
              Volver
            </button>
          </div>
        </div>
      )}

      {mode === "cancel" && (
        <div className="space-y-3 border-t border-[var(--site-border)] pt-5">
          <label className="block text-sm">
            <span className="font-semibold text-[var(--site-navy-title)]">¿Nos cuentas por qué? (opcional)</span>
            <textarea className="input mt-1 min-h-20" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          </label>
          <div className="flex flex-col gap-2 sm:flex-row">
            <button disabled={busy} onClick={() => post("cancel", { reason: reason || null }, "Tu demo quedó cancelada.")} className="site-btn min-h-11 bg-[#E2453C] text-white hover:bg-[#c8372f] disabled:opacity-60">
              Sí, cancelar la demo
            </button>
            <button onClick={() => setMode("view")} className="site-btn site-btn-outline min-h-11">
              Volver
            </button>
          </div>
        </div>
      )}
      {!info.can_change && info.status !== "cancelled" && <p className="text-sm text-[var(--site-text-2)]">Esta cita ya no se puede cambiar desde aquí. Si necesitas algo, escríbenos.</p>}
      {info.status === "cancelled" && (
        <Link href="/demo/agendar" className="site-btn site-btn-primary inline-flex min-h-11">
          Agendar otra demo
        </Link>
      )}
    </div>
  );
}
