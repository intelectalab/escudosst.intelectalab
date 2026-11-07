"use client";

// Agenda pública de demos (SCR-WEB-51 a 53, RN-WEB-68 a 73): el visitante elige un horario libre (hora de Lima),
// deja sus datos y recibe un correo con «Agregar a mi calendario» y un enlace para reprogramar o cancelar.
// La duración sale del panel; la reunión solo se graba con su permiso.

import Link from "next/link";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { Alert, Check, Field, Honeypot } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { SECTORS } from "@/lib/accounts";
import { track } from "@/lib/track";

export const DEMO_API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
export type Slot = { starts_at: string; ends_at: string };
const TZ = "America/Lima";
const PROVIDERS: [string, string, string][] = [
  ["meet", "Google Meet", "play"],
  ["teams", "Microsoft Teams", "people"],
  ["phone", "Llamada", "user"],
];

export const limaDay = (iso: string) => new Date(iso).toLocaleDateString("en-CA", { timeZone: TZ });
export const limaTime = (iso: string) => new Date(iso).toLocaleTimeString("es-PE", { hour: "numeric", minute: "2-digit", timeZone: TZ });
export const limaLong = (iso: string) => {
  const t = new Date(iso).toLocaleString("es-PE", { weekday: "long", day: "numeric", month: "long", hour: "numeric", minute: "2-digit", timeZone: TZ });
  return t.charAt(0).toUpperCase() + t.slice(1);
};

export async function fetchSlots(): Promise<{ result: string; duration_minutes?: number; slots: Slot[] }> {
  const from = new Date().toLocaleDateString("en-CA", { timeZone: TZ });
  const to = new Date(Date.now() + 30 * 86_400_000).toLocaleDateString("en-CA", { timeZone: TZ });
  try {
    const r = await fetch(`${DEMO_API}/api/v1/public/demo/slots?from=${from}&to=${to}`, { headers: { Accept: "application/json" } });
    return ((await r.json()) as { data: { result: string; duration_minutes?: number; slots: Slot[] } }).data;
  } catch {
    return { result: "error", slots: [] };
  }
}

/** Tira de días (desplazable, sin desbordar la página) y horarios en botones grandes. */
export function SlotPicker({ slots, value, onChange }: { slots: Slot[]; value: string | null; onChange: (iso: string) => void }) {
  const byDay = useMemo(() => {
    const m = new Map<string, Slot[]>();
    for (const s of slots) {
      const k = limaDay(s.starts_at);
      m.set(k, [...(m.get(k) ?? []), s]);
    }
    return m;
  }, [slots]);
  const days = useMemo(() => [...byDay.keys()], [byDay]);
  const [day, setDay] = useState<string | null>(value ? limaDay(value) : (days[0] ?? null));
  useEffect(() => {
    if (!day && days[0]) setDay(days[0]);
  }, [day, days]);

  if (!days.length) return <p className="rounded-xl bg-[#FFF8E1] p-4 text-sm text-[#5C4500]">No hay horarios libres en los próximos días. Escríbenos y coordinamos uno.</p>;
  return (
    <div className="space-y-4">
      <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2" role="listbox" aria-label="Día">
        {days.map((d) => {
          const dt = new Date(`${d}T12:00:00`);
          const on = d === day;
          return (
            <button
              key={d}
              type="button"
              role="option"
              aria-selected={on}
              onClick={() => setDay(d)}
              className={`flex w-[68px] shrink-0 flex-col items-center rounded-xl border px-2 py-2 transition ${on ? "border-[var(--site-action)] bg-[var(--site-action)] text-white shadow" : "border-[#d5dde7] bg-white hover:border-[var(--site-action)]"}`}
            >
              <span className="text-[11px] font-semibold uppercase">{dt.toLocaleDateString("es-PE", { weekday: "short" })}</span>
              <span className="font-display text-xl font-bold leading-tight">{dt.getDate()}</span>
              <span className="text-[11px]">{dt.toLocaleDateString("es-PE", { month: "short" })}</span>
            </button>
          );
        })}
      </div>
      {day && (
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
          {(byDay.get(day) ?? []).map((s) => (
            <button
              key={s.starts_at}
              type="button"
              onClick={() => onChange(s.starts_at)}
              aria-pressed={value === s.starts_at}
              className={`min-h-12 rounded-xl border text-sm font-bold transition ${value === s.starts_at ? "border-[var(--site-action)] bg-[#EAF3FC] text-[var(--site-action)] ring-2 ring-[var(--site-action)]/20" : "border-[#d5dde7] bg-white hover:border-[var(--site-action)]"}`}
            >
              {limaTime(s.starts_at)}
            </button>
          ))}
        </div>
      )}
      <p className="text-xs text-[var(--site-text-2)]">Horarios en hora de Lima (Perú).</p>
    </div>
  );
}

type Done = { starts_at: string; host_name: string | null; meeting_provider: string; recording_consent: boolean; manage_token: string | null };

export function DemoBooking() {
  const [data, setData] = useState<{ result: string; duration_minutes?: number; slots: Slot[] } | null>(null);
  const [slot, setSlot] = useState<string | null>(null);
  const [f, setF] = useState({ full_name: "", email: "", phone: "", company_name: "", ruc: "", headcount: "", economic_sector: "", message: "", meeting_provider: "meet", website: "" });
  const [consultancy, setConsultancy] = useState(false);
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [recording, setRecording] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState<Done | null>(null);
  const [key] = useState(() => (typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : ""));
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];

  const load = () => fetchSlots().then(setData);
  useEffect(() => {
    load();
  }, []);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!slot) return setMessage("Elige un día y una hora.");
    setSaving(true);
    setMessage(null);
    setErrors({});
    try {
      const res = await fetch(`${DEMO_API}/api/v1/public/demo/book`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", ...(key ? { "Idempotency-Key": key } : {}) },
        body: JSON.stringify({
          ...f, starts_at: slot, phone: f.phone || null, ruc: f.ruc || null, headcount: f.headcount ? Number(f.headcount) : null, economic_sector: f.economic_sector || null,
          message: f.message || null, is_consultancy: consultancy, privacy_consent: privacy, marketing_consent: marketing, recording_consent: recording,
        }),
      });
      const j = await res.json().catch(() => ({}));
      if (res.ok) {
        track("demo_booked", "agenda");
        setDone(j.data);
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (res.status === 409) {
        setMessage(j.detail ?? "Ese horario acaba de ocuparse. Elige otro, por favor.");
        setSlot(null);
        load();
      } else {
        setErrors(j.errors ?? {});
        setMessage(res.status === 429 ? "Recibimos varios envíos seguidos. Espera unos minutos." : "Revisa los campos marcados.");
      }
    } catch {
      setMessage("No hay conexión. Revisa tu internet e inténtalo otra vez.");
    }
    setSaving(false);
  }

  if (done) {
    const prov = PROVIDERS.find((p) => p[0] === done.meeting_provider)?.[1] ?? done.meeting_provider;
    return (
      <div className="site-card mx-auto max-w-2xl p-6 text-center sm:p-10" role="status">
        <span className="mx-auto grid h-16 w-16 place-items-center rounded-full bg-[#E8F7EE] text-[#22A35A]">
          <Icon name="check" size={30} />
        </span>
        <h2 className="mt-4 font-display text-2xl font-bold text-[var(--site-navy-title)]">¡Listo! Reservaste tu demo</h2>
        <p className="mt-3 font-display text-[22px] font-bold text-[var(--site-action)] sm:text-[26px]">{limaLong(done.starts_at)}</p>
        <p className="text-sm text-[var(--site-text-2)]">Hora de Lima · {prov}{done.host_name ? ` · con ${done.host_name}` : ""}</p>
        <p className="mx-auto mt-4 max-w-md text-[var(--site-text-2)]">Te enviamos un correo con los detalles. Nuestro equipo confirmará la reunión y te mandará el enlace.</p>
        <p className="mx-auto mt-2 max-w-md text-sm text-[var(--site-text-2)]">{done.recording_consent ? "Nos diste permiso para grabar la reunión." : "La reunión solo se graba si nos das tu consentimiento."}</p>
        {done.manage_token && (
          <div className="mt-6 flex flex-col justify-center gap-2 sm:flex-row">
            <a href={`${DEMO_API}/api/v1/public/demo/manage/${done.manage_token}/ics`} className="site-btn site-btn-primary min-h-11">
              <Icon name="clock" size={16} /> Agregar a mi calendario
            </a>
            <Link href={`/demo/gestionar/${done.manage_token}`} className="site-btn site-btn-outline min-h-11">
              Reprogramar o cancelar
            </Link>
          </div>
        )}
      </div>
    );
  }

  if (!data) return <div className="site-card h-80 animate-pulse" />;
  if (data.result !== "ok") {
    return (
      <div className="site-card p-8 text-center">
        <p className="font-display text-xl font-bold text-[var(--site-navy-title)]">La agenda en línea no está disponible ahora</p>
        <p className="mt-2 text-[var(--site-text-2)]">Déjanos tus datos y te escribimos para coordinar el horario.</p>
        <Link href="/demo" className="site-btn site-btn-primary mt-5 inline-flex min-h-11">
          Pedir una demo
        </Link>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="relative grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <Honeypot value={f.website} onChange={(v) => set("website", v)} />
      <div className="site-card min-w-0 p-5 sm:p-6 lg:self-start">
        <p className="flex items-center gap-2 font-display text-lg font-bold text-[var(--site-navy-title)]">
          <span className="grid h-8 w-8 place-items-center rounded-lg bg-[#EAF3FC] text-[var(--site-action)]">1</span> Elige día y hora
        </p>
        {data.duration_minutes && <p className="mb-4 mt-1 text-sm text-[var(--site-text-2)]">La reunión dura {data.duration_minutes} minutos.</p>}
        <SlotPicker slots={data.slots} value={slot} onChange={setSlot} />
        {err("starts_at") && <p className="mt-2 text-sm font-medium text-[#A3271F]">{err("starts_at")}</p>}
      </div>

      <div className="site-card min-w-0 space-y-4 p-5 sm:p-6">
        <p className="flex items-center gap-2 font-display text-lg font-bold text-[var(--site-navy-title)]">
          <span className="grid h-8 w-8 place-items-center rounded-lg bg-[#EAF3FC] text-[var(--site-action)]">2</span> Tus datos
        </p>
        <Alert text={message} />
        {slot && (
          <p className="rounded-xl bg-[#EAF3FC] px-4 py-3 text-sm font-semibold text-[var(--site-action)]">
            <Icon name="clock" size={15} className="mr-1 inline" /> {limaLong(slot)}
          </p>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Nombre y apellidos" error={err("full_name")}>
            <input className="input" maxLength={120} autoComplete="name" value={f.full_name} onChange={(e) => set("full_name", e.target.value)} />
          </Field>
          <Field label="Correo de trabajo" error={err("email")}>
            <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} onChange={(e) => set("email", e.target.value)} />
          </Field>
          <Field label="Empresa" error={err("company_name")}>
            <input className="input" maxLength={160} autoComplete="organization" value={f.company_name} onChange={(e) => set("company_name", e.target.value)} />
          </Field>
          <Field label="RUC" optional error={err("ruc")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.ruc} onChange={(e) => set("ruc", e.target.value.replace(/\D/g, "").slice(0, 11))} />
          </Field>
          <Field label="Trabajadores" optional error={err("headcount")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.headcount} onChange={(e) => set("headcount", e.target.value.replace(/\D/g, "").slice(0, 7))} />
          </Field>
          <Field label="Rubro" optional error={err("economic_sector")}>
            <select className="input" value={f.economic_sector} onChange={(e) => set("economic_sector", e.target.value)}>
              <option value="">Elige</option>
              {SECTORS.map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <fieldset>
          <legend className="mb-1.5 text-sm font-semibold text-[var(--site-navy-title)]">¿Cómo prefieres la reunión?</legend>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {PROVIDERS.map(([v, l, icon]) => (
              <label key={v} className={`flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-3 text-sm font-semibold ${f.meeting_provider === v ? "border-[var(--site-action)] bg-[var(--site-surface)] text-[var(--site-action)]" : "border-[#d5dde7]"}`}>
                <input type="radio" name="provider" className="sr-only" checked={f.meeting_provider === v} onChange={() => set("meeting_provider", v)} />
                <Icon name={icon} size={16} /> {l}
              </label>
            ))}
          </div>
        </fieldset>
        <Field label="Celular" optional={f.meeting_provider !== "phone"} error={err("phone")}>
          <input className="input" type="tel" maxLength={30} autoComplete="tel" value={f.phone} onChange={(e) => set("phone", e.target.value)} />
        </Field>
        <Field label="¿Qué te gustaría ver?" optional error={err("message")}>
          <textarea className="input min-h-20" maxLength={2000} value={f.message} onChange={(e) => set("message", e.target.value)} />
        </Field>
        <Check checked={consultancy} onChange={setConsultancy}>
          Soy una consultora SST (asesoro a varias empresas).
        </Check>
        <Check checked={privacy} onChange={setPrivacy} error={err("privacy_consent")}>
          Acepto la{" "}
          <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
            Política de privacidad
          </Link>{" "}
          para que agenden y me contacten por esta demo.
        </Check>
        <Check checked={marketing} onChange={setMarketing}>
          Quiero recibir novedades y ofertas de EscudoSST (opcional).
        </Check>
        <Check checked={recording} onChange={setRecording}>
          Autorizo que la reunión se grabe para uso interno (opcional). Si no lo marcas, no se graba.
        </Check>
        <button type="submit" disabled={saving || !slot} className="site-btn site-btn-primary w-full disabled:opacity-60">
          {saving ? "Reservando…" : slot ? "Reservar mi demo" : "Elige un horario para continuar"}
        </button>
      </div>
    </form>
  );
}
