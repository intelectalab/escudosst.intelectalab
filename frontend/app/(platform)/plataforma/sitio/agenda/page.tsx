"use client";

// Sitio web › Agenda de demos (SCR-WEB-54 a 58): calendario por mes o lista, solicitudes sin horario, ficha de cada
// reunión (asignar, confirmar con enlace, mover, cancelar, resultado), anfitriones con su horario y bloqueos, y ajustes.
// Solo el Dueño asigna y cambia ajustes; Soporte ve y confirma solo las reuniones donde es el anfitrión.

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Meeting = {
  id: string;
  starts_at: string;
  ends_at: string;
  status: "proposed" | "confirmed" | "rescheduled" | "cancelled" | "completed" | "no_show";
  meeting_provider: string;
  meeting_url: string | null;
  host_id: string | null;
  recording_consent: boolean;
  outcome: string | null;
  outcome_notes: string | null;
  cancel_reason: string | null;
  reminder_24h_sent_at: string | null;
  reminder_1h_sent_at: string | null;
  created_at: string;
  full_name: string;
  email: string;
  phone: string | null;
  company_name: string;
  ruc: string | null;
  headcount: number | null;
  message: string | null;
};
type Pending = { id: string; full_name: string; email: string; company_name: string; phone: string | null; headcount: number | null; message: string | null; created_at: string };
type Rule = { weekday: number; start_time: string; end_time: string };
type Host = { id: string; user_id: string; display_name: string; email: string; color: string | null; max_per_day: number | null; is_active: boolean; calendar_provider: string; calendar_account: string | null; calendar_error: string | null; user_name: string | null; rules: Rule[] };
type Settings = { public_booking_enabled: boolean; duration_minutes: number; buffer_minutes: number; min_notice_hours: number; max_days_ahead: number; default_meeting_provider: string; reminder_24h: boolean; reminder_1h: boolean };
type Data = {
  meetings: Meeting[];
  pending: Pending[];
  hosts: { google_ready: boolean; list: Host[]; blackouts: { id: string; host_id: string | null; starts_at: string; ends_at: string; reason: string | null }[]; team: { id: string; full_name: string; email: string }[] };
  settings: Settings;
  providers: Record<string, string>;
  outcomes: Record<string, string>;
};

const TZ = "America/Lima";
const STATUS: Record<Meeting["status"], [string, "amber" | "ok" | "brand" | "na" | "bad"]> = {
  proposed: ["Por confirmar", "amber"],
  confirmed: ["Confirmada", "ok"],
  rescheduled: ["Reprogramada", "brand"],
  cancelled: ["Cancelada", "na"],
  completed: ["Realizada", "ok"],
  no_show: ["No asistió", "bad"],
};
const WEEKDAYS = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"];
const COLORS = ["#1F7FD1", "#22A35A", "#F08A24", "#5B47C9", "#C8372F", "#16897b"];
const dayKey = (iso: string) => new Date(iso).toLocaleDateString("en-CA", { timeZone: TZ });
const time = (iso: string) => new Date(iso).toLocaleTimeString("es-PE", { hour: "numeric", minute: "2-digit", timeZone: TZ });
const long = (iso: string) => {
  const t = new Date(iso).toLocaleString("es-PE", { weekday: "long", day: "numeric", month: "long", hour: "numeric", minute: "2-digit", timeZone: TZ });
  return t.charAt(0).toUpperCase() + t.slice(1);
};
const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.");

export default function AgendaPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [month, setMonth] = useState(() => new Date());
  const [view, setView] = useState<"month" | "list">("month");
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const [tab, setTab] = useState<"calendar" | "hosts" | "settings">("calendar");
  useEffect(() => {
    const q = new URLSearchParams(window.location.search);
    if (q.get("tab") === "hosts") setTab("hosts");
    const g = q.get("google");
    if (g) {
      setToast(g === "ok" ? { text: "Google Calendar conectado. Las demos confirmadas con Meet crearán su enlace solas.", tone: "ok" } : { text: g === "cancelado" ? "No se conectó: cancelaste el permiso en Google." : "No se pudo conectar con Google. Inténtalo de nuevo.", tone: "bad" });
      window.history.replaceState(null, "", window.location.pathname);
    }
  }, []);
  const [data, setData] = useState<Data | null>(null);
  const [open, setOpen] = useState<Meeting | null>(null);
  const [propose, setPropose] = useState<Pending | null>(null);
  const [hostEdit, setHostEdit] = useState<Host | "new" | null>(null);
  const [hostFilter, setHostFilter] = useState("");
  const load = useCallback(() => {
    const first = new Date(month.getFullYear(), month.getMonth(), 1);
    const from = new Date(first);
    from.setDate(from.getDate() - 7);
    const to = new Date(month.getFullYear(), month.getMonth() + 1, 7);
    const f = (d: Date) => d.toLocaleDateString("en-CA");
    api<{ data: Data }>(`/internal/site/demos?from=${f(from)}&to=${f(to)}`).then((r) => {
      setData(r.data);
      setOpen((o) => (o ? (r.data.meetings.find((m) => m.id === o.id) ?? null) : null));
    });
  }, [month]);
  useEffect(load, [load]);

  const hosts = data?.hosts.list ?? [];
  const hostOf = (id: string | null) => hosts.find((h) => h.id === id);
  const meetings = (data?.meetings ?? []).filter((m) => !hostFilter || m.host_id === hostFilter);
  const upcoming = meetings.filter((m) => new Date(m.starts_at) >= new Date() && ["proposed", "confirmed", "rescheduled"].includes(m.status));
  const done = (t: string) => {
    setToast({ text: t, tone: "ok" });
    load();
  };

  const grid = useMemo(() => {
    const first = new Date(month.getFullYear(), month.getMonth(), 1);
    const start = new Date(first);
    start.setDate(1 - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      return d;
    });
  }, [month]);
  const byDay = useMemo(() => {
    const m = new Map<string, Meeting[]>();
    meetings.forEach((x) => m.set(dayKey(x.starts_at), [...(m.get(dayKey(x.starts_at)) ?? []), x]));
    return m;
  }, [meetings]);
  const today = new Date().toLocaleDateString("en-CA", { timeZone: TZ });

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Agenda de demos"]]}
        icon="clock"
        title="Agenda de demos"
        description="Las demos que la gente reserva en /demo/agendar y las que propones tú. Confirma cada una con el enlace de Meet o Teams: el visitante recibe el correo y su recordatorio."
        aside={
          <a href="/demo/agendar" target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-semibold hover:bg-bg">
            <Icon name="globe" size={16} /> Ver la agenda pública
          </a>
        }
      />

      <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Por confirmar" value={data ? upcoming.filter((m) => m.status === "proposed").length : "–"} icon="bell" tone="amber" hint="Falta el enlace o el anfitrión" />
        <StatCard label="Próximas" value={data ? upcoming.length : "–"} icon="clock" tone="brand" hint="Desde hoy" />
        <StatCard label="Sin horario" value={data ? data.pending.length : "–"} icon="people" tone="bad" hint="Pidieron demo sin elegir hora" />
        <StatCard
          label="Agenda pública"
          value={data ? (data.settings.public_booking_enabled ? "Activa" : "Apagada") : "–"}
          icon="globe"
          tone={data?.settings.public_booking_enabled ? "ok" : "na"}
          hint={data ? `${data.settings.duration_minutes} min por demo` : ""}
          onClick={() => setTab("settings")}
        />
      </div>

      <div className="flex flex-wrap gap-1 rounded-xl border border-line bg-surface p-1 text-sm font-semibold sm:w-fit">
        {(
          [
            ["calendar", "Calendario"],
            ["hosts", "Anfitriones y horarios"],
            ["settings", "Ajustes"],
          ] as const
        ).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={`flex-1 rounded-lg px-4 py-2 sm:flex-none ${tab === k ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>
            {l}
          </button>
        ))}
      </div>

      {!data ? (
        <div className="h-96 animate-pulse rounded-2xl bg-line/40" />
      ) : tab === "calendar" ? (
        <div className="grid grid-cols-1 gap-5 2xl:grid-cols-[minmax(0,1fr)_340px]">
          <section className="min-w-0 rounded-2xl border border-line bg-surface p-3 shadow-sm sm:p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <button onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))} className="grid h-9 w-9 place-items-center rounded-lg border border-line hover:bg-bg" aria-label="Mes anterior">
                <Icon name="back" size={16} />
              </button>
              <b className="min-w-[150px] text-center font-display">{((t) => t.charAt(0).toUpperCase() + t.slice(1))(month.toLocaleDateString("es-PE", { month: "long", year: "numeric" }))}</b>
              <button onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))} className="grid h-9 w-9 place-items-center rounded-lg border border-line hover:bg-bg" aria-label="Mes siguiente">
                <Icon name="chevron" size={16} />
              </button>
              <button onClick={() => setMonth(new Date())} className="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand hover:bg-brand-soft">
                Hoy
              </button>
              <span className="flex-1" />
              {hosts.length > 1 && (
                <select className="input h-9 w-auto py-1 text-sm" value={hostFilter} onChange={(e) => setHostFilter(e.target.value)} aria-label="Anfitrión">
                  <option value="">Todos los anfitriones</option>
                  {hosts.map((h) => (
                    <option key={h.id} value={h.id}>
                      {h.display_name}
                    </option>
                  ))}
                </select>
              )}
              <div className="inline-flex rounded-lg border border-line p-0.5 text-xs font-semibold">
                {(["month", "list"] as const).map((v) => (
                  <button key={v} onClick={() => setView(v)} className={`rounded-md px-3 py-1 ${view === v ? "bg-brand text-white" : "text-muted"}`}>
                    {v === "month" ? "Mes" : "Lista"}
                  </button>
                ))}
              </div>
            </div>

            {view === "month" ? (
              <div className="-mx-1 overflow-x-auto px-1">
                <div className="grid min-w-[640px] grid-cols-7 gap-1 text-xs">
                  {["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"].map((d) => (
                    <div key={d} className="px-1 pb-1 font-semibold text-muted">
                      {d}
                    </div>
                  ))}
                  {grid.map((d) => {
                    const k = d.toLocaleDateString("en-CA");
                    const items = byDay.get(k) ?? [];
                    const out = d.getMonth() !== month.getMonth();
                    return (
                      <div key={k} className={`min-h-[92px] rounded-lg border p-1 ${k === today ? "border-brand bg-brand-soft/40" : "border-line"} ${out ? "opacity-45" : ""}`}>
                        <span className={`text-[11px] font-semibold ${k === today ? "text-brand" : "text-muted"}`}>{d.getDate()}</span>
                        <div className="mt-0.5 space-y-0.5">
                          {items.slice(0, 3).map((m) => (
                            <button
                              key={m.id}
                              onClick={() => setOpen(m)}
                              className={`block w-full truncate rounded px-1 py-0.5 text-left text-[11px] font-semibold text-white ${m.status === "cancelled" ? "line-through opacity-50" : ""}`}
                              style={{ background: hostOf(m.host_id)?.color ?? "#6b7684" }}
                              title={`${time(m.starts_at)} · ${m.company_name}`}
                            >
                              {m.status === "proposed" && "● "}
                              {time(m.starts_at)} {m.company_name}
                            </button>
                          ))}
                          {items.length > 3 && <span className="block px-1 text-[10px] text-muted">+{items.length - 3} más</span>}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            ) : meetings.length === 0 ? (
              <p className="p-6 text-center text-sm text-muted">No hay demos en este mes.</p>
            ) : (
              <ul className="divide-y divide-line">
                {meetings.map((m) => (
                  <li key={m.id}>
                    <button onClick={() => setOpen(m)} className="flex w-full flex-wrap items-center gap-2 px-2 py-3 text-left hover:bg-bg">
                      <span className="h-8 w-1.5 shrink-0 rounded-full" style={{ background: hostOf(m.host_id)?.color ?? "#6b7684" }} />
                      <span className="min-w-0 flex-1">
                        <b className="block truncate text-sm">{m.company_name}</b>
                        <span className="text-xs text-muted">
                          {long(m.starts_at)} · {hostOf(m.host_id)?.display_name ?? "Sin anfitrión"}
                        </span>
                      </span>
                      <Chip tone={STATUS[m.status][1]} dot>
                        {STATUS[m.status][0]}
                      </Chip>
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {hosts.length > 0 && (
              <p className="mt-3 flex flex-wrap gap-3 text-xs text-muted">
                {hosts.map((h) => (
                  <span key={h.id} className="inline-flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-full" style={{ background: h.color ?? "#6b7684" }} /> {h.display_name}
                  </span>
                ))}
                <span>● = por confirmar</span>
              </p>
            )}
          </section>

          <section className="rounded-2xl border border-line bg-surface shadow-sm 2xl:self-start">
            <p className="border-b border-line px-4 py-3 text-sm font-semibold">Pidieron demo sin horario ({data.pending.length})</p>
            {data.pending.length === 0 ? (
              <p className="p-4 text-sm text-muted">{owner ? "Nada pendiente." : "Solo el Dueño ve esta lista."}</p>
            ) : (
              <ul className="divide-y divide-line">
                {data.pending.map((p) => (
                  <li key={p.id} className="px-4 py-3">
                    <b className="block text-sm">{p.company_name}</b>
                    <span className="block text-xs text-muted">
                      {p.full_name} · {new Date(p.created_at).toLocaleDateString("es-PE", { timeZone: TZ })}
                    </span>
                    {owner && hosts.length > 0 && (
                      <Button size="sm" variant="soft" icon="clock" className="mt-2" onClick={() => setPropose(p)}>
                        Proponer horario
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {owner && hosts.length === 0 && <p className="border-t border-line p-4 text-xs text-muted">Agrega un anfitrión en «Anfitriones y horarios» para poder proponer horarios.</p>}
          </section>
        </div>
      ) : tab === "hosts" ? (
        <HostsTab data={data} owner={owner} onEdit={setHostEdit} onDone={done} onError={(t) => setToast({ text: t, tone: "bad" })} />
      ) : (
        <SettingsTab s={data.settings} providers={data.providers} owner={owner} onDone={done} />
      )}

      {open && data && (
        <MeetingModal
          m={open}
          data={data}
          owner={owner}
          onClose={() => setOpen(null)}
          onDone={(t) => done(t)}
          onError={(t) => setToast({ text: t, tone: "bad" })}
        />
      )}
      {propose && data && (
        <ProposeModal
          p={propose}
          data={data}
          onClose={() => setPropose(null)}
          onDone={() => {
            setPropose(null);
            done("Horario propuesto. Le enviamos un correo al visitante.");
          }}
        />
      )}
      {hostEdit && data && (
        <HostModal
          h={hostEdit === "new" ? null : hostEdit}
          team={data.hosts.team}
          used={hosts.map((h) => h.color ?? "")}
          onClose={() => setHostEdit(null)}
          onDone={() => {
            setHostEdit(null);
            done("Anfitrión guardado.");
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

// ---------------------------------------------------------------- Reunión

function MeetingModal({ m, data, owner, onClose, onDone, onError }: { m: Meeting; data: Data; owner: boolean; onClose: () => void; onDone: (t: string) => void; onError: (t: string) => void }) {
  const [mode, setMode] = useState<"view" | "confirm" | "move" | "cancel" | "complete">("view");
  const [url, setUrl] = useState(m.meeting_url ?? "");
  const [provider, setProvider] = useState(m.meeting_provider);
  const [slot, setSlot] = useState("");
  const [slots, setSlots] = useState<{ host_id: string; starts_at: string }[] | null>(null);
  const [reason, setReason] = useState("");
  const [outcome, setOutcome] = useState("interested");
  const [noShow, setNoShow] = useState(false);
  const [notes, setNotes] = useState(m.outcome_notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const host = data.hosts.list.find((h) => h.id === m.host_id);
  const active = ["proposed", "confirmed", "rescheduled"].includes(m.status);
  const past = new Date(m.starts_at) < new Date();

  async function run(action: string, body: object, ok: string) {
    setSaving(true);
    setError(null);
    try {
      await api(`/internal/site/demos/meetings/${m.id}/${action}`, { method: "POST", body });
      onDone(ok);
      setMode("view");
    } catch (e) {
      setError(errText(e));
    }
    setSaving(false);
  }

  useEffect(() => {
    if (mode !== "move" || slots) return;
    const f = new Date().toLocaleDateString("en-CA", { timeZone: TZ });
    const t = new Date(Date.now() + 30 * 86_400_000).toLocaleDateString("en-CA", { timeZone: TZ });
    api<{ data: { host_id: string; starts_at: string }[] }>(`/internal/site/demos/free-slots?from=${f}&to=${t}&exclude=${m.id}`).then((r) => setSlots(r.data.filter((s) => s.host_id === m.host_id)));
  }, [mode, slots, m.id, m.host_id]);

  async function assign(hostId: string) {
    try {
      await api(`/internal/site/demos/meetings/${m.id}/assign`, { method: "POST", body: { host_id: hostId } });
      onDone("Anfitrión asignado.");
    } catch (e) {
      onError(errText(e));
    }
  }

  const footer =
    mode === "view" ? (
      <>
        {active && (
          <Button className="sm:mr-auto" onClick={() => setMode("cancel")}>
            Cancelar demo
          </Button>
        )}
        {active && !past && (
          <Button icon="clock" onClick={() => setMode("move")}>
            Mover
          </Button>
        )}
        {m.status !== "confirmed" && active && (
          <Button variant="primary" icon="check" onClick={() => setMode("confirm")}>
            Confirmar
          </Button>
        )}
        {["confirmed", "rescheduled"].includes(m.status) && past && (
          <Button variant="primary" icon="check" onClick={() => setMode("complete")}>
            Registrar resultado
          </Button>
        )}
        {!active && <Button onClick={onClose}>Cerrar</Button>}
      </>
    ) : (
      <>
        <Button onClick={() => setMode("view")}>Volver</Button>
        <Button
          variant="primary"
          icon="check"
          loading={saving}
          disabled={(mode === "move" && !slot) || (mode === "cancel" && reason.trim().length < 3)}
          onClick={() =>
            mode === "confirm"
              ? run("confirm", { meeting_provider: provider, meeting_url: url || null }, "Demo confirmada. Le enviamos el enlace al visitante.")
              : mode === "move"
                ? run("move", { starts_at: slot }, "Demo movida. Le avisamos al visitante.")
                : mode === "cancel"
                  ? run("cancel", { reason }, "Demo cancelada. Le avisamos al visitante.")
                  : run("complete", { no_show: noShow, outcome: noShow ? null : outcome, notes: notes || null }, "Resultado guardado.")
          }
        >
          {mode === "confirm" ? "Confirmar y enviar" : mode === "move" ? "Mover y avisar" : mode === "cancel" ? "Cancelar y avisar" : "Guardar"}
        </Button>
      </>
    );

  return (
    <Modal title={m.company_name} subtitle={long(m.starts_at)} icon="clock" size="lg" onClose={onClose} footer={footer}>
      {mode === "view" && (
        <div className="space-y-4">
          <span className="flex flex-wrap gap-1.5">
            <Chip tone={STATUS[m.status][1]} dot>
              {STATUS[m.status][0]}
            </Chip>
            <Chip tone="na">{data.providers[m.meeting_provider]}</Chip>
            <Chip tone={m.recording_consent ? "ok" : "na"}>{m.recording_consent ? "Permite grabar" : "No grabar"}</Chip>
          </span>
          <div className="grid grid-cols-1 gap-x-6 gap-y-1 rounded-xl border border-line p-3 text-sm sm:grid-cols-2">
            <p>
              <span className="text-muted">Contacto: </span>
              {m.full_name}
            </p>
            <p className="truncate">
              <span className="text-muted">Correo: </span>
              <a href={`mailto:${m.email}`} className="text-brand hover:underline">
                {m.email}
              </a>
            </p>
            {m.phone && (
              <p>
                <span className="text-muted">Celular: </span>
                {m.phone}
              </p>
            )}
            {m.ruc && (
              <p>
                <span className="text-muted">RUC: </span>
                {m.ruc}
              </p>
            )}
            {m.headcount && (
              <p>
                <span className="text-muted">Trabajadores: </span>
                {m.headcount}
              </p>
            )}
            {m.meeting_url && (
              <p className="truncate sm:col-span-2">
                <span className="text-muted">Enlace: </span>
                <a href={m.meeting_url} target="_blank" rel="noopener noreferrer" className="text-brand hover:underline">
                  {m.meeting_url}
                </a>
              </p>
            )}
          </div>
          {m.message && <p className="whitespace-pre-line rounded-xl bg-bg p-3 text-sm">{m.message}</p>}
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-muted">Anfitrión:</span>
            {owner && active ? (
              <select className="input h-9 w-auto py-1" value={m.host_id ?? ""} onChange={(e) => e.target.value && assign(e.target.value)}>
                {!m.host_id && <option value="">Elige</option>}
                {data.hosts.list
                  .filter((h) => h.is_active)
                  .map((h) => (
                    <option key={h.id} value={h.id}>
                      {h.display_name}
                    </option>
                  ))}
              </select>
            ) : (
              <b>{host?.display_name ?? "Sin asignar"}</b>
            )}
          </div>
          <ul className="space-y-1 border-l-2 border-line pl-3 text-xs text-muted">
            <li>Reservada el {new Date(m.created_at).toLocaleString("es-PE", { timeZone: TZ })}</li>
            {m.reminder_24h_sent_at && <li>Recordatorio del día anterior enviado</li>}
            {m.reminder_1h_sent_at && <li>Recordatorio de una hora antes enviado</li>}
            {m.cancel_reason && <li>Cancelada: {m.cancel_reason}</li>}
            {m.outcome && <li>Resultado: {data.outcomes[m.outcome]}</li>}
            {m.outcome_notes && <li>Notas: {m.outcome_notes}</li>}
          </ul>
        </div>
      )}
      {mode === "confirm" && (
        <div className="space-y-4">
          <FormField label="Modalidad">
            <select className="input" value={provider} onChange={(e) => setProvider(e.target.value)}>
              {Object.entries(data.providers).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          {provider === "meet" && host?.calendar_provider === "google" && !m.meeting_url && (
            <p className="rounded-xl bg-ok-soft p-3 text-sm text-ok">Google Calendar está conectado: si dejas el enlace vacío, se crea solo el Meet y la invitación llega al visitante.</p>
          )}
          {provider !== "phone" && (
            <FormField label={provider === "meet" && host?.calendar_provider === "google" ? "Enlace de la reunión (opcional)" : "Enlace de la reunión"} hint="Crea la reunión en Google Meet o Teams y pega aquí el enlace.">
              <input className="input" placeholder="https://meet.google.com/…" value={url} onChange={(e) => setUrl(e.target.value)} autoFocus />
            </FormField>
          )}
          <p className="rounded-xl bg-bg p-3 text-sm">{m.recording_consent ? "El visitante autorizó la grabación." : "El visitante NO autorizó grabar: no actives la grabación."}</p>
        </div>
      )}
      {mode === "move" && (
        <div className="space-y-3">
          <p className="text-sm text-muted">Horarios libres de {host?.display_name ?? "su anfitrión"} en los próximos 30 días:</p>
          {slots === null ? (
            <div className="h-32 animate-pulse rounded-xl bg-line/40" />
          ) : slots.length === 0 ? (
            <p className="text-sm text-muted">No hay horarios libres. Revisa la disponibilidad del anfitrión.</p>
          ) : (
            <select className="input" value={slot} onChange={(e) => setSlot(e.target.value)}>
              <option value="">Elige el nuevo horario</option>
              {slots.map((s) => (
                <option key={s.starts_at} value={s.starts_at}>
                  {long(s.starts_at)}
                </option>
              ))}
            </select>
          )}
        </div>
      )}
      {mode === "cancel" && (
        <FormField label="Motivo" hint="Se le envía al visitante por correo.">
          <textarea className="input min-h-20" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus />
        </FormField>
      )}
      {mode === "complete" && (
        <div className="space-y-4">
          <SwitchRow label="No asistió" hint="Marca si la persona no se conectó." checked={noShow} onChange={setNoShow} />
          {!noShow && (
            <FormField label="Resultado">
              <select className="input" value={outcome} onChange={(e) => setOutcome(e.target.value)}>
                {Object.entries(data.outcomes).map(([k, l]) => (
                  <option key={k} value={k}>
                    {l}
                  </option>
                ))}
              </select>
            </FormField>
          )}
          <FormField label="Notas (opcional)">
            <textarea className="input min-h-20" maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>
        </div>
      )}
      {error && <p className="mt-3 text-sm font-medium text-bad">● {error}</p>}
    </Modal>
  );
}

function ProposeModal({ p, data, onClose, onDone }: { p: Pending; data: Data; onClose: () => void; onDone: () => void }) {
  const active = data.hosts.list.filter((h) => h.is_active);
  const [host, setHost] = useState(active[0]?.id ?? "");
  const [slots, setSlots] = useState<{ host_id: string; starts_at: string }[] | null>(null);
  const [slot, setSlot] = useState("");
  const [provider, setProvider] = useState(data.settings.default_meeting_provider);
  const [url, setUrl] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    const f = new Date().toLocaleDateString("en-CA", { timeZone: TZ });
    const t = new Date(Date.now() + 30 * 86_400_000).toLocaleDateString("en-CA", { timeZone: TZ });
    api<{ data: { host_id: string; starts_at: string }[] }>(`/internal/site/demos/free-slots?from=${f}&to=${t}`).then((r) => setSlots(r.data));
  }, []);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api("/internal/site/demos/meetings", { method: "POST", body: { demo_request_id: p.id, host_id: host, starts_at: slot, meeting_provider: provider, meeting_url: url || null } });
      onDone();
    } catch (x) {
      setError(errText(x));
      setSaving(false);
    }
  }

  const mine = (slots ?? []).filter((s) => s.host_id === host);
  return (
    <Modal
      title="Proponer horario"
      subtitle={`${p.company_name} · ${p.full_name}`}
      icon="clock"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="propose-form" icon="check" loading={saving} disabled={!slot}>
            {provider === "phone" || url ? "Confirmar y enviar" : "Proponer y enviar"}
          </Button>
        </>
      }
    >
      <form id="propose-form" onSubmit={save} className="space-y-4">
        <FormField label="Anfitrión">
          <select className="input" value={host} onChange={(e) => (setHost(e.target.value), setSlot(""))}>
            {active.map((h) => (
              <option key={h.id} value={h.id}>
                {h.display_name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Horario libre">
          {slots === null ? (
            <div className="h-10 animate-pulse rounded-lg bg-line/40" />
          ) : (
            <select className="input" value={slot} onChange={(e) => setSlot(e.target.value)}>
              <option value="">{mine.length ? "Elige" : "Sin horarios libres"}</option>
              {mine.map((s) => (
                <option key={s.starts_at} value={s.starts_at}>
                  {long(s.starts_at)}
                </option>
              ))}
            </select>
          )}
        </FormField>
        <FormField label="Modalidad">
          <select className="input" value={provider} onChange={(e) => setProvider(e.target.value)}>
            {Object.entries(data.providers).map(([k, l]) => (
              <option key={k} value={k}>
                {l}
              </option>
            ))}
          </select>
        </FormField>
        {provider !== "phone" && (
          <FormField label="Enlace de la reunión (opcional)" hint="Si lo pegas ahora, la demo queda confirmada. Si no, queda «por confirmar».">
            <input className="input" placeholder="https://meet.google.com/…" value={url} onChange={(e) => setUrl(e.target.value)} />
          </FormField>
        )}
        {error && <p className="text-sm font-medium text-bad">● {error}</p>}
      </form>
    </Modal>
  );
}

// ---------------------------------------------------------------- Anfitriones

function HostsTab({ data, owner, onEdit, onDone, onError }: { data: Data; owner: boolean; onEdit: (h: Host | "new") => void; onDone: (t: string) => void; onError: (t: string) => void }) {
  const [b, setB] = useState({ host_id: "", starts_at: "", ends_at: "", reason: "" });
  const [saving, setSaving] = useState(false);

  async function addBlock(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      await api("/internal/site/demos/blackouts", { method: "POST", body: { host_id: b.host_id || null, starts_at: new Date(b.starts_at).toISOString(), ends_at: new Date(b.ends_at).toISOString(), reason: b.reason || null } });
      setB({ host_id: "", starts_at: "", ends_at: "", reason: "" });
      onDone("Bloqueo agregado: esas horas ya no se ofrecen.");
    } catch (x) {
      onError(errText(x));
    }
    setSaving(false);
  }

  async function delBlock(id: string) {
    try {
      await api(`/internal/site/demos/blackouts/${id}`, { method: "DELETE" });
      onDone("Bloqueo quitado.");
    } catch (x) {
      onError(errText(x));
    }
  }

  return (
    <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
      <section className="min-w-0 space-y-3">
        {owner && (
          <Button variant="primary" icon="plus" onClick={() => onEdit("new")}>
            Agregar anfitrión
          </Button>
        )}
        {data.hosts.list.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-line bg-surface p-8 text-center text-sm text-muted">
            Aún no hay anfitriones. Agrega a las personas del equipo que atienden demos y su horario semanal.
          </div>
        ) : (
          <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            {data.hosts.list.map((h) => (
              <li key={h.id} className="rounded-2xl border border-line bg-surface p-4 shadow-sm">
                <div className="flex items-start gap-3">
                  <span className="mt-1 h-4 w-4 shrink-0 rounded-full" style={{ background: h.color ?? "#6b7684" }} />
                  <span className="min-w-0 flex-1">
                    <b className="block">{h.display_name}</b>
                    <span className="block truncate text-xs text-muted">
                      {h.user_name} · {h.email}
                    </span>
                  </span>
                  <Chip tone={h.is_active ? "ok" : "na"}>{h.is_active ? "Activo" : "Inactivo"}</Chip>
                </div>
                <ul className="mt-3 space-y-0.5 text-xs">
                  {h.rules.length === 0 ? (
                    <li className="text-bad">Sin horario: no se le ofrecen demos.</li>
                  ) : (
                    WEEKDAYS.map((w, i) => {
                      const r = h.rules.filter((x) => x.weekday === i + 1);
                      return r.length ? (
                        <li key={w} className="flex gap-2">
                          <span className="w-20 text-muted">{w}</span>
                          {r.map((x) => `${x.start_time}–${x.end_time}`).join(", ")}
                        </li>
                      ) : null;
                    })
                  )}
                </ul>
                {h.max_per_day ? <p className="mt-2 text-xs text-muted">Máximo {h.max_per_day} por día</p> : null}
                <GoogleBox h={h} ready={data.hosts.google_ready} canManage={owner} onDone={onDone} onError={onError} />
                {owner && (
                  <Button size="sm" variant="ghost" icon="pen" className="mt-2" onClick={() => onEdit(h)}>
                    Editar
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
      <section className="rounded-2xl border border-line bg-surface p-4 shadow-sm xl:self-start">
        <p className="font-semibold">Bloqueos y feriados</p>
        <p className="text-xs text-muted">Horas en las que no se ofrecen demos (vacaciones, eventos). Los feriados nacionales ya se excluyen solos.</p>
        <ul className="mt-3 space-y-2">
          {data.hosts.blackouts.length === 0 && <li className="text-sm text-muted">Sin bloqueos próximos.</li>}
          {data.hosts.blackouts.map((x) => (
            <li key={x.id} className="flex items-start gap-2 rounded-lg bg-bg p-2 text-xs">
              <span className="min-w-0 flex-1">
                <b className="block">{x.host_id ? data.hosts.list.find((h) => h.id === x.host_id)?.display_name : "Todos"}</b>
                {new Date(x.starts_at).toLocaleString("es-PE", { timeZone: TZ, dateStyle: "short", timeStyle: "short" })} → {new Date(x.ends_at).toLocaleString("es-PE", { timeZone: TZ, dateStyle: "short", timeStyle: "short" })}
                {x.reason && <span className="block text-muted">{x.reason}</span>}
              </span>
              {owner && (
                <button onClick={() => delBlock(x.id)} className="text-muted hover:text-bad" aria-label="Quitar bloqueo">
                  <Icon name="close" size={14} />
                </button>
              )}
            </li>
          ))}
        </ul>
        {owner && (
          <form onSubmit={addBlock} className="mt-4 space-y-2 border-t border-line pt-3">
            <select className="input" value={b.host_id} onChange={(e) => setB({ ...b, host_id: e.target.value })} aria-label="A quién aplica">
              <option value="">Todos los anfitriones</option>
              {data.hosts.list.map((h) => (
                <option key={h.id} value={h.id}>
                  {h.display_name}
                </option>
              ))}
            </select>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <label className="text-xs text-muted">
                Desde
                <input className="input mt-0.5" type="datetime-local" value={b.starts_at} onChange={(e) => setB({ ...b, starts_at: e.target.value })} />
              </label>
              <label className="text-xs text-muted">
                Hasta
                <input className="input mt-0.5" type="datetime-local" value={b.ends_at} onChange={(e) => setB({ ...b, ends_at: e.target.value })} />
              </label>
            </div>
            <input className="input" placeholder="Motivo (opcional)" maxLength={200} value={b.reason} onChange={(e) => setB({ ...b, reason: e.target.value })} />
            <Button type="submit" size="sm" variant="soft" icon="plus" loading={saving} disabled={!b.starts_at || !b.ends_at}>
              Agregar bloqueo
            </Button>
          </form>
        )}
      </section>
    </div>
  );
}

function GoogleBox({ h, ready, canManage, onDone, onError }: { h: Host; ready: boolean; canManage: boolean; onDone: (t: string) => void; onError: (t: string) => void }) {
  const me = usePlatformUser();
  const mine = canManage || h.user_id === me.id;
  const [busy, setBusy] = useState(false);

  async function connect() {
    setBusy(true);
    try {
      const r = await api<{ data: { url: string } }>(`/internal/site/demos/hosts/${h.id}/google/connect`);
      window.location.href = r.data.url;
    } catch (e) {
      onError(errText(e));
      setBusy(false);
    }
  }

  async function disconnect() {
    if (!window.confirm("¿Desconectar Google Calendar de este anfitrión? Las demos ya creadas siguen en su calendario.")) return;
    try {
      await api(`/internal/site/demos/hosts/${h.id}/google`, { method: "DELETE" });
      onDone("Google Calendar desconectado.");
    } catch (e) {
      onError(errText(e));
    }
  }

  return (
    <div className={`mt-3 rounded-xl border p-3 text-xs ${h.calendar_provider === "google" ? (h.calendar_error ? "border-bad/30 bg-bad-soft" : "border-ok/30 bg-ok-soft") : "border-line bg-bg"}`}>
      {h.calendar_provider === "google" ? (
        <>
          <p className="font-semibold">Google Calendar conectado{h.calendar_account ? ` · ${h.calendar_account}` : ""}</p>
          {h.calendar_error && <p className="mt-1 text-bad">{h.calendar_error}</p>}
          {mine && (
            <div className="mt-2 flex gap-2">
              {h.calendar_error && (
                <Button size="sm" variant="soft" onClick={connect} loading={busy}>
                  Volver a conectar
                </Button>
              )}
              <Button size="sm" variant="ghost" onClick={disconnect}>
                Desconectar
              </Button>
            </div>
          )}
        </>
      ) : (
        <>
          <p className="text-muted">Google Calendar: sin conectar. Las demos se ven aquí y llegan por correo.</p>
          {mine &&
            (ready ? (
              <Button size="sm" variant="soft" icon="clock" className="mt-2" onClick={connect} loading={busy}>
                Conectar Google Calendar
              </Button>
            ) : (
              <p className="mt-1 text-amber-ink">Para conectarlo, primero hay que cargar las credenciales de Google en el servidor.</p>
            ))}
        </>
      )}
    </div>
  );
}

function HostModal({ h, team, used, onClose, onDone }: { h: Host | null; team: { id: string; full_name: string; email: string }[]; used: string[]; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({
    user_id: h?.user_id ?? team[0]?.id ?? "",
    display_name: h?.display_name ?? "",
    email: h?.email ?? team[0]?.email ?? "",
    color: h?.color ?? COLORS.find((c) => !used.includes(c)) ?? COLORS[0],
    max_per_day: h?.max_per_day ? String(h.max_per_day) : "",
    is_active: h?.is_active ?? true,
  });
  const [rules, setRules] = useState<Rule[]>(h?.rules ?? [1, 2, 3, 4, 5].map((d) => ({ weekday: d, start_time: "09:00", end_time: "13:00" })));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(h ? `/internal/site/demos/hosts/${h.id}` : "/internal/site/demos/hosts", {
        method: h ? "PATCH" : "POST",
        body: { ...f, max_per_day: f.max_per_day ? Number(f.max_per_day) : null, rules },
      });
      onDone();
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { display_name: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  const setRule = (i: number, k: keyof Rule, v: string | number) => setRules(rules.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  const ruleErr = Object.entries(errors).find(([k]) => k.startsWith("rules."))?.[1]?.[0];

  return (
    <Modal
      title={h ? "Editar anfitrión" : "Agregar anfitrión"}
      icon="user"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="host-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="host-form" onSubmit={save} className="space-y-4" noValidate>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Persona del equipo" error={errors.user_id?.[0]}>
            <select
              className="input"
              value={f.user_id}
              onChange={(e) => {
                const u = team.find((t) => t.id === e.target.value);
                setF({ ...f, user_id: e.target.value, email: u?.email ?? f.email });
              }}
            >
              {team.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.full_name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Nombre que ve el cliente" error={errors.display_name?.[0]} hint="Ej.: «María, asesora comercial». Sin títulos que no estén verificados.">
            <input className="input" maxLength={80} value={f.display_name} onChange={(e) => setF({ ...f, display_name: e.target.value })} />
          </FormField>
          <FormField label="Correo para avisos" error={errors.email?.[0]}>
            <input className="input" type="email" maxLength={160} value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
          </FormField>
          <FormField label="Máximo de demos por día (opcional)" error={errors.max_per_day?.[0]}>
            <input className="input" inputMode="numeric" value={f.max_per_day} onChange={(e) => setF({ ...f, max_per_day: e.target.value.replace(/\D/g, "").slice(0, 2) })} />
          </FormField>
        </div>
        <div>
          <p className="text-sm font-semibold">Color en el calendario</p>
          <div className="mt-2 flex gap-2">
            {COLORS.map((c) => (
              <button key={c} type="button" onClick={() => setF({ ...f, color: c })} aria-label={`Color ${c}`} aria-pressed={f.color === c} className={`h-8 w-8 rounded-full ring-offset-2 ${f.color === c ? "ring-2 ring-fg" : ""}`} style={{ background: c }} />
            ))}
          </div>
        </div>
        <div>
          <div className="flex items-center justify-between gap-2">
            <p className="text-sm font-semibold">Horario semanal (hora de Lima)</p>
            <Button size="sm" variant="ghost" icon="plus" onClick={() => setRules([...rules, { weekday: 1, start_time: "15:00", end_time: "18:00" }])}>
              Agregar tramo
            </Button>
          </div>
          <ul className="mt-2 space-y-2">
            {rules.map((r, i) => (
              <li key={i} className="flex flex-wrap items-center gap-2">
                <select className="input h-10 w-36" value={r.weekday} onChange={(e) => setRule(i, "weekday", Number(e.target.value))} aria-label="Día">
                  {WEEKDAYS.map((w, j) => (
                    <option key={w} value={j + 1}>
                      {w}
                    </option>
                  ))}
                </select>
                <input className="input h-10 w-28" type="time" value={r.start_time} onChange={(e) => setRule(i, "start_time", e.target.value)} aria-label="Desde" />
                <span className="text-muted">a</span>
                <input className="input h-10 w-28" type="time" value={r.end_time} onChange={(e) => setRule(i, "end_time", e.target.value)} aria-label="Hasta" />
                <button type="button" onClick={() => setRules(rules.filter((_, j) => j !== i))} className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-bg hover:text-bad" aria-label="Quitar tramo">
                  <Icon name="close" size={14} />
                </button>
              </li>
            ))}
          </ul>
          {ruleErr && <p className="mt-1 text-xs font-medium text-bad">● {ruleErr}</p>}
        </div>
        <SwitchRow label="Activo" hint="Si lo apagas, deja de recibir demos nuevas (las ya agendadas se mantienen)." checked={f.is_active} onChange={(v) => setF({ ...f, is_active: v })} />
        <p className="rounded-xl bg-bg p-3 text-xs text-muted">Después de guardar, conecta su Google Calendar desde la tarjeta del anfitrión: las demos confirmadas se agregan a su calendario con el enlace de Meet.</p>
      </form>
    </Modal>
  );
}

// ---------------------------------------------------------------- Ajustes

function SettingsTab({ s, providers, owner, onDone }: { s: Settings; providers: Record<string, string>; owner: boolean; onDone: (t: string) => void }) {
  const [f, setF] = useState(s);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const num = (k: keyof Settings, label: string, hint: string, max: number) => (
    <FormField label={label} hint={hint}>
      <input className="input" inputMode="numeric" disabled={!owner} value={String(f[k])} onChange={(e) => setF({ ...f, [k]: Math.min(max, Number(e.target.value.replace(/\D/g, "")) || 0) })} />
    </FormField>
  );

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api("/internal/site/demos/settings", { method: "PATCH", body: f });
      onDone("Ajustes de la agenda guardados.");
    } catch (x) {
      setError(errText(x));
    }
    setSaving(false);
  }

  return (
    <form onSubmit={save} className="max-w-3xl space-y-4 rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-6">
      <SwitchRow label="Agenda pública activa" hint="Permite que la gente elija su horario en /demo/agendar. Necesita al menos un anfitrión con horario." checked={f.public_booking_enabled} onChange={(v) => owner && setF({ ...f, public_booking_enabled: v })} />
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {num("duration_minutes", "Duración de la demo (minutos)", "Entre 10 y 240. Es lo que se muestra al visitante.", 240)}
        {num("buffer_minutes", "Margen entre demos (minutos)", "Tiempo libre entre una y otra.", 120)}
        {num("min_notice_hours", "Aviso mínimo (horas)", "No se ofrecen horarios más cercanos que esto.", 720)}
        {num("max_days_ahead", "Días hacia adelante", "Hasta cuántos días se puede reservar.", 180)}
      </div>
      <FormField label="Modalidad por defecto">
        <select className="input" disabled={!owner} value={f.default_meeting_provider} onChange={(e) => setF({ ...f, default_meeting_provider: e.target.value })}>
          {Object.entries(providers).map(([k, l]) => (
            <option key={k} value={k}>
              {l}
            </option>
          ))}
        </select>
      </FormField>
      <SwitchRow label="Recordatorio el día anterior" hint="Correo al visitante 24 horas antes (solo demos confirmadas)." checked={f.reminder_24h} onChange={(v) => owner && setF({ ...f, reminder_24h: v })} />
      <SwitchRow label="Recordatorio una hora antes" hint="Correo al visitante una hora antes (solo demos confirmadas)." checked={f.reminder_1h} onChange={(v) => owner && setF({ ...f, reminder_1h: v })} />
      {error && <p className="text-sm font-medium text-bad">● {error}</p>}
      {owner && (
        <Button variant="primary" type="submit" icon="check" loading={saving}>
          Guardar ajustes
        </Button>
      )}
    </form>
  );
}

