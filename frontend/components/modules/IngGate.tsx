"use client";

// Portería (M-ING SCR-ING-01/02/03): el operador elige el punto de acceso, escanea el QR del fotocheck con la cámara
// (BarcodeDetector del navegador) o escribe el código/DNI, y ve a pantalla completa «HABILITADO» (verde) o
// «NO HABILITADO» (rojo) con nombre, foto, empresa y motivo sin términos médicos. Registra visitantes con la
// inducción de visita aceptada y muestra quiénes están dentro. Botones de 48 px para usar con guantes o en tablet.

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { api, API_URL, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";

type Point = { id: string; code: string; name: string; site: string | null; allows_visitors: boolean };
type Kpis = { granted_today: number; denied_today: number; overridden_today: number; inside: number };
type Person = { kind: string; name: string | null; company: string | null; document_masked: string | null; position: string | null; photo_file_id: string | null };
export type ScanResult = { id?: string; result: "granted" | "denied" | "overridden" | "unknown"; granted: boolean; direction?: string; occurred_at?: string; person: Person | null; reasons: string[] };
export type InsidePerson = { event_id: string; kind: string; name: string; company: string; document_masked: string | null; host_name: string | null; access_point: string; since: string; overridden: boolean };

const POINT_KEY = "escudo.garita.point";
const BIG = "h-12 min-h-12 rounded-xl px-5 text-base font-semibold";

type Detector = { detect: (src: HTMLVideoElement) => Promise<{ rawValue: string }[]> };
type DetectorCtor = new (o: { formats: string[] }) => Detector;

const errText = (e: unknown) => (e instanceof ApiError ? (e.errors ? Object.values(e.errors).flat()[0] : null) ?? e.detail ?? e.title : "No se pudo completar.");
const hour = (iso: string) => new Date(iso.replace(" ", "T")).toLocaleTimeString("es-PE", { hour: "2-digit", minute: "2-digit" });
const initials = (n: string | null) => (n ?? "?").split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join("").toUpperCase();

export function IngGate() {
  const [points, setPoints] = useState<Point[] | null>(null);
  const [kpis, setKpis] = useState<Kpis | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [point, setPoint] = useState("");
  const [direction, setDirection] = useState<"in" | "out">("in");
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<(ScanResult & { code: string }) | null>(null);
  const [visitor, setVisitor] = useState(false);
  const [inside, setInside] = useState<InsidePerson[]>([]);
  const [toast, setToast] = useState<{ m: string; t?: "ok" | "bad" } | null>(null);

  const loadInside = useCallback(() => {
    api<{ data: { people: InsidePerson[]; kpis: Kpis } }>("/ing/inside", { withCompany: true })
      .then((r) => {
        setInside(r.data.people);
        setKpis(r.data.kpis);
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    api<{ data: { points: Point[]; kpis: Kpis } }>("/ing/gate", { withCompany: true })
      .then((r) => {
        setPoints(r.data.points);
        setKpis(r.data.kpis);
        let saved = "";
        try {
          saved = localStorage.getItem(POINT_KEY) ?? "";
        } catch {
          /* sin almacenamiento */
        }
        setPoint(r.data.points.some((p) => p.id === saved) ? saved : r.data.points[0]?.id ?? "");
      })
      .catch((e) => setError(errText(e)));
    loadInside();
  }, [loadInside]);

  function choosePoint(id: string) {
    setPoint(id);
    try {
      localStorage.setItem(POINT_KEY, id);
    } catch {
      /* sin almacenamiento */
    }
  }

  async function verify(raw: string, override?: string) {
    const value = raw.trim();
    if (!value || !point || busy) return;
    setBusy(true);
    try {
      const r = await api<{ data: ScanResult }>("/ing/gate/scan", {
        method: "POST",
        withCompany: true,
        body: { access_point_id: point, code: value, direction, ...(override ? { override_reason: override } : {}) },
      });
      setResult({ ...r.data, code: value });
      setCode("");
      if (navigator.vibrate) navigator.vibrate(r.data.granted ? 80 : [120, 80, 120]);
      loadInside();
    } catch (e) {
      setToast({ m: errText(e), t: "bad" });
    } finally {
      setBusy(false);
    }
  }

  if (error) {
    return (
      <div className="space-y-3 rounded-xl border border-line bg-surface p-6">
        <h1 className="text-xl font-semibold">Portería</h1>
        <p className="text-muted">{error}</p>
        <Link href="/inicio" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">Volver al inicio</Link>
      </div>
    );
  }
  if (!points) return <div className="h-72 animate-pulse rounded-xl bg-surface" />;

  const current = points.find((p) => p.id === point);

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <nav aria-label="Migas de pan" className="text-xs text-muted">EscudoSST › Contratistas › <Link href="/m/ing" className="hover:underline">Ingreso por QR</Link> › <span className="text-fg">Portería</span></nav>
          <h1 className="font-display text-2xl font-bold">Portería</h1>
        </div>
        {kpis && (
          <div className="flex gap-2 text-center">
            <Mini label="Ingresos hoy" value={kpis.granted_today} color="text-ok" />
            <Mini label="No habilitados" value={kpis.denied_today} color="text-bad" />
            <Mini label="Dentro ahora" value={kpis.inside} color="text-brand" />
          </div>
        )}
      </div>

      {points.length === 0 ? (
        <div className="rounded-xl border border-line bg-surface p-6 text-muted">
          No hay puntos de acceso activos. El Jefe SST los crea en <Link href="/m/ing" className="font-semibold text-brand hover:underline">Ingreso por QR › Puntos de acceso</Link>.
        </div>
      ) : (
        <>
          <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
            <label className="block">
              <span className="mb-1.5 block text-sm font-semibold">Punto de acceso</span>
              <select className="input !h-12 text-base" value={point} onChange={(e) => choosePoint(e.target.value)}>
                {points.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}{p.site ? ` · ${p.site}` : ""}</option>
                ))}
              </select>
            </label>
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Movimiento">
              {([["in", "Ingreso"], ["out", "Salida"]] as const).map(([v, l]) => (
                <button key={v} type="button" role="radio" aria-checked={direction === v} onClick={() => setDirection(v)}
                  className={`${BIG} border ${direction === v ? "border-brand bg-brand text-white" : "border-line bg-surface text-fg hover:border-brand"}`}>
                  {l}
                </button>
              ))}
            </div>
          </section>

          <Scanner onCode={(c) => verify(c)} paused={!!result || busy || visitor} />

          <section className="space-y-2 rounded-xl border border-line bg-surface p-4">
            <form className="flex flex-col gap-2 sm:flex-row" onSubmit={(e) => { e.preventDefault(); verify(code); }}>
              <label className="sr-only" htmlFor="gate-code">Código del fotocheck o DNI</label>
              <input id="gate-code" className="input !h-12 flex-1 text-base" inputMode="text" autoComplete="off" placeholder="N.° de fotocheck (FOT-2026-00001) o DNI"
                value={code} onChange={(e) => setCode(e.target.value)} />
              <button type="submit" disabled={!code.trim() || busy} className={`${BIG} bg-brand text-white disabled:opacity-50`}>
                {busy ? "Verificando…" : "Verificar"}
              </button>
            </form>
            <p className="text-xs text-muted">Si la cámara no lee el QR, escribe el número del fotocheck o el DNI. Cada verificación queda registrada y no se puede modificar.</p>
          </section>

          {current?.allows_visitors !== false && (
            <button type="button" onClick={() => setVisitor(true)} className={`${BIG} flex w-full items-center justify-center gap-2 border border-line bg-surface hover:border-brand`}>
              <Icon name="user" size={20} /> Registrar visitante
            </button>
          )}

          <InsideList people={inside} onExit={async (p) => {
            try {
              await api("/ing/gate/exit", { method: "POST", withCompany: true, body: { event_id: p.event_id, access_point_id: point } });
              setToast({ m: `Salida registrada: ${p.name}` });
              loadInside();
            } catch (e) {
              setToast({ m: errText(e), t: "bad" });
            }
          }} />
        </>
      )}

      {result && <ResultScreen r={result} busy={busy} onClose={() => setResult(null)} onOverride={(reason) => verify(result.code, reason)} />}
      {visitor && current && (
        <VisitorModal point={current} direction={direction} onClose={() => setVisitor(false)} onDone={(r) => { setVisitor(false); setResult({ ...r, code: "" }); loadInside(); }} />
      )}
      {toast && <Toast message={toast.m} tone={toast.t} onClose={() => setToast(null)} />}
    </div>
  );
}

function Mini({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div className="rounded-xl border border-line bg-surface px-3 py-1.5">
      <p className={`font-display text-xl font-bold ${color}`}>{value}</p>
      <p className="text-[11px] text-muted">{label}</p>
    </div>
  );
}

/** Cámara con BarcodeDetector (Chrome/Android/Edge). Si el navegador no lo tiene, se usa el campo manual. */
function Scanner({ onCode, paused }: { onCode: (c: string) => void; paused: boolean }) {
  const video = useRef<HTMLVideoElement>(null);
  const stream = useRef<MediaStream | null>(null);
  const [on, setOn] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const supported = typeof window !== "undefined" && "BarcodeDetector" in window && !!navigator.mediaDevices?.getUserMedia;
  const pausedRef = useRef(paused);
  pausedRef.current = paused;
  const cb = useRef(onCode);
  cb.current = onCode;

  const stop = useCallback(() => {
    stream.current?.getTracks().forEach((t) => t.stop());
    stream.current = null;
    setOn(false);
  }, []);

  useEffect(() => stop, [stop]);

  useEffect(() => {
    if (!on || !video.current) return;
    const Ctor = (window as unknown as { BarcodeDetector: DetectorCtor }).BarcodeDetector;
    const detector = new Ctor({ formats: ["qr_code"] });
    let last = "";
    let lastAt = 0;
    let alive = true;
    const tick = async () => {
      if (!alive) return;
      const v = video.current;
      if (v && v.readyState >= 2 && !pausedRef.current) {
        try {
          const codes = await detector.detect(v);
          const raw = codes[0]?.rawValue;
          // El mismo QR no se lee dos veces seguidas en 4 s (la persona sigue frente a la cámara).
          if (raw && (raw !== last || Date.now() - lastAt > 4000)) {
            last = raw;
            lastAt = Date.now();
            cb.current(raw);
          }
        } catch {
          /* cuadro sin QR */
        }
      }
      setTimeout(tick, 300);
    };
    tick();
    return () => {
      alive = false;
    };
  }, [on]);

  async function start() {
    setMsg(null);
    try {
      const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false });
      stream.current = s;
      setOn(true);
      requestAnimationFrame(() => {
        if (video.current) {
          video.current.srcObject = s;
          video.current.play().catch(() => undefined);
        }
      });
    } catch {
      setMsg("No se pudo abrir la cámara. Revisa el permiso del navegador o escribe el código.");
    }
  }

  if (!supported) {
    return (
      <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">
        Este navegador no lee códigos QR con la cámara. Usa Chrome en Android o escribe el número del fotocheck o el DNI.
      </p>
    );
  }
  return (
    <section className="space-y-2 rounded-xl border border-line bg-surface p-4">
      {on ? (
        <>
          <div className="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-2xl border-4 border-brand bg-black">
            <video ref={video} className="h-full w-full object-cover" muted playsInline />
            <div className="pointer-events-none absolute inset-x-6 top-1/2 h-0.5 bg-[#F4B90F]" aria-hidden="true" />
          </div>
          <p className="text-center text-sm text-muted" aria-live="polite">{paused ? "En pausa" : "Apunta la cámara al QR del fotocheck"}</p>
          <button type="button" onClick={stop} className={`${BIG} w-full border border-line bg-surface hover:border-brand`}>Apagar cámara</button>
        </>
      ) : (
        <button type="button" onClick={start} className={`${BIG} flex w-full items-center justify-center gap-2 bg-brand text-white`}>
          <Icon name="idcard" size={20} /> Escanear QR con la cámara
        </button>
      )}
      {msg && <p className="text-sm text-bad">{msg}</p>}
    </section>
  );
}

function usePhoto(id: string | null | undefined) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!id) return;
    let obj: string | null = null;
    fetch(`${API_URL}/api/v1/files/${id}`, { headers: { Authorization: `Bearer ${session.getToken()}`, "X-Company-Id": session.getCompanyId() ?? "" } })
      .then(async (res) => {
        if (res.ok) {
          obj = URL.createObjectURL(await res.blob());
          setUrl(obj);
        }
      })
      .catch(() => undefined);
    return () => {
      if (obj) URL.revokeObjectURL(obj);
    };
  }, [id]);
  return id ? url : null;
}

/** Resultado a pantalla completa. Si no está habilitado, se puede permitir por excepción con motivo (queda alertado). */
function ResultScreen({ r, busy, onClose, onOverride }: { r: ScanResult & { code: string }; busy: boolean; onClose: () => void; onOverride: (reason: string) => void }) {
  const ok = r.granted;
  const photo = usePhoto(r.person?.photo_file_id);
  const [asking, setAsking] = useState(false);
  const [reason, setReason] = useState("");
  const closeBtn = useRef<HTMLButtonElement>(null);
  useEffect(() => closeBtn.current?.focus(), []);
  useEffect(() => {
    const k = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", k);
    return () => document.removeEventListener("keydown", k);
  }, [onClose]);
  const title = r.result === "unknown" ? "NO RECONOCIDO" : ok ? (r.direction === "out" ? "SALIDA REGISTRADA" : "HABILITADO") : "NO HABILITADO";
  const sub = r.result === "overridden" ? "Ingreso por excepción · se avisó al Jefe SST" : r.occurred_at ? `Registrado a las ${hour(r.occurred_at)}` : "No se registró ningún evento";
  return (
    <div role="alertdialog" aria-modal="true" aria-label={title} className={`fixed inset-0 z-[70] flex flex-col overflow-y-auto ${ok ? "bg-ok" : "bg-bad"} text-white`}>
      <div className="flex flex-1 flex-col items-center justify-center gap-4 px-4 py-8 text-center">
        <span className="grid h-24 w-24 place-items-center rounded-full bg-white/20" aria-hidden="true">
          <Icon name={ok ? "check" : "close"} size={56} />
        </span>
        <h2 className="font-display text-4xl font-extrabold tracking-wide sm:text-5xl">{title}</h2>
        <p className="text-sm text-white/90">{sub}</p>
        {r.person && (
          <div className="flex w-full max-w-md items-center gap-4 rounded-2xl bg-white p-4 text-left text-fg">
            {photo ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={photo} alt="" className="h-20 w-20 shrink-0 rounded-xl object-cover" />
            ) : (
              <span className="grid h-20 w-20 shrink-0 place-items-center rounded-xl bg-bg font-display text-2xl font-bold text-muted">{initials(r.person.name)}</span>
            )}
            <div className="min-w-0">
              <p className="text-lg font-bold leading-tight">{r.person.name ?? "Sin nombre"}</p>
              <p className="text-sm text-muted">{r.person.company}</p>
              {r.person.position && <p className="text-sm text-muted">{r.person.position}</p>}
              {r.person.document_masked && <p className="text-xs text-muted">Documento {r.person.document_masked}</p>}
            </div>
          </div>
        )}
        {r.reasons.length > 0 && (
          <ul className="w-full max-w-md space-y-1 rounded-2xl bg-white/15 p-4 text-left text-base">
            {r.reasons.map((x) => (
              <li key={x} className="flex gap-2"><span aria-hidden="true">•</span>{x}</li>
            ))}
          </ul>
        )}
        {!ok && r.result === "denied" && (
          asking ? (
            <form className="w-full max-w-md space-y-2" onSubmit={(e) => { e.preventDefault(); if (reason.trim().length >= 5) onOverride(reason.trim()); }}>
              <label className="block text-left text-sm font-semibold" htmlFor="ovr">Motivo de la excepción (se avisa al Jefe SST)</label>
              <textarea id="ovr" rows={3} className="w-full rounded-xl border-0 p-3 text-base text-fg" value={reason} onChange={(e) => setReason(e.target.value)}
                placeholder="Quién lo autorizó y por qué" autoFocus />
              <button type="submit" disabled={reason.trim().length < 5 || busy} className={`${BIG} w-full bg-white text-bad disabled:opacity-60`}>
                {busy ? "Registrando…" : "Permitir ingreso por excepción"}
              </button>
            </form>
          ) : (
            <button type="button" onClick={() => setAsking(true)} className={`${BIG} border-2 border-white/80 bg-transparent`}>Permitir por excepción</button>
          )
        )}
      </div>
      <div className="sticky bottom-0 p-4">
        <button ref={closeBtn} type="button" onClick={onClose} className={`${BIG} mx-auto block w-full max-w-md bg-white ${ok ? "text-ok" : "text-bad"}`}>
          Escanear otro
        </button>
      </div>
    </div>
  );
}

/** SCR-ING-02: visitante con inducción de visita aceptada. */
function VisitorModal({ point, direction, onClose, onDone }: { point: Point; direction: "in" | "out"; onClose: () => void; onDone: (r: ScanResult) => void }) {
  const [f, setF] = useState({ full_name: "", document_number: "", host_name: "", purpose: "", induction_accepted: false });
  const [dir, setDir] = useState(direction);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const set = (k: keyof typeof f, v: string | boolean) => setF((x) => ({ ...x, [k]: v }));
  async function save() {
    setBusy(true);
    setErrors({});
    setMsg(null);
    try {
      const r = await api<{ data: ScanResult }>("/ing/gate/visitor", { method: "POST", withCompany: true, body: { ...f, access_point_id: point.id, direction: dir } });
      onDone(r.data);
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(e.errors);
      else setMsg(errText(e));
    } finally {
      setBusy(false);
    }
  }
  const err = (k: string) => errors[k]?.[0] && <span className="mt-1 block text-xs font-medium text-bad">{errors[k][0]}</span>;
  return (
    <Modal title="Registrar visitante" subtitle={point.name} icon="user" onClose={onClose}
      footer={<>
        <button type="button" onClick={onClose} className={`${BIG} border border-line bg-surface`}>Cancelar</button>
        <button type="button" onClick={save} disabled={busy} className={`${BIG} bg-brand text-white disabled:opacity-50`}>{busy ? "Registrando…" : dir === "in" ? "Registrar ingreso" : "Registrar salida"}</button>
      </>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-2">
          {([["in", "Ingreso"], ["out", "Salida"]] as const).map(([v, l]) => (
            <button key={v} type="button" aria-pressed={dir === v} onClick={() => setDir(v)}
              className={`${BIG} border ${dir === v ? "border-brand bg-brand text-white" : "border-line bg-surface"}`}>{l}</button>
          ))}
        </div>
        <label className="block">
          <span className="mb-1.5 block text-sm font-semibold">Nombre completo</span>
          <input className="input !h-12" value={f.full_name} onChange={(e) => set("full_name", e.target.value)} autoComplete="off" />
          {err("full_name")}
        </label>
        <label className="block">
          <span className="mb-1.5 block text-sm font-semibold">DNI o carné de extranjería</span>
          <input className="input !h-12" inputMode="numeric" value={f.document_number} onChange={(e) => set("document_number", e.target.value)} autoComplete="off" />
          <span className="mt-1 block text-xs text-muted">Solo se guardan los últimos 4 dígitos visibles.</span>
          {err("document_number")}
        </label>
        {dir === "in" && (
          <>
            <label className="block">
              <span className="mb-1.5 block text-sm font-semibold">¿A quién visita?</span>
              <input className="input !h-12" value={f.host_name} onChange={(e) => set("host_name", e.target.value)} placeholder="Nombre y área" />
              {err("host_name")}
            </label>
            <label className="block">
              <span className="mb-1.5 block text-sm font-semibold">Motivo (opcional)</span>
              <input className="input !h-12" value={f.purpose} onChange={(e) => set("purpose", e.target.value)} />
            </label>
            <div className="rounded-xl border border-line bg-bg p-3 text-sm">
              <p className="font-semibold">Inducción de visita</p>
              <ul className="mt-1 list-disc space-y-0.5 pl-5 text-muted">
                <li>Permanece con la persona que visitas y sigue la señalización.</li>
                <li>Usa el EPP que te entreguen en las zonas que lo exijan.</li>
                <li>En una emergencia, sigue las rutas de evacuación hasta el punto de reunión.</li>
                <li>Informa de inmediato cualquier incidente al personal de la empresa.</li>
              </ul>
              <label className="mt-3 flex min-h-12 cursor-pointer items-center gap-3">
                <input type="checkbox" className="h-6 w-6 accent-[var(--brand)]" checked={f.induction_accepted} onChange={(e) => set("induction_accepted", e.target.checked)} />
                <span className="font-semibold">El visitante recibió y aceptó la inducción de visita</span>
              </label>
              {err("induction_accepted")}
            </div>
          </>
        )}
        {msg && <p className="text-sm text-bad">{msg}</p>}
      </div>
    </Modal>
  );
}

/** SCR-ING-03: personas dentro (último movimiento de hoy = ingreso permitido). */
export function InsideList({ people, onExit }: { people: InsidePerson[]; onExit?: (p: InsidePerson) => void }) {
  const [q, setQ] = useState("");
  const list = people.filter((p) => !q || `${p.name} ${p.company}`.toLowerCase().includes(q.toLowerCase()));
  return (
    <section className="rounded-xl border border-line bg-surface p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-display text-base font-semibold">Quiénes están dentro <span className="text-brand">({people.length})</span></h3>
        {people.length > 5 && <input className="filter-control !w-48" placeholder="Buscar" aria-label="Buscar persona" value={q} onChange={(e) => setQ(e.target.value)} />}
      </div>
      {list.length === 0 ? (
        <p className="text-sm text-muted">{people.length ? "Nadie coincide con la búsqueda." : "Nadie registró ingreso hoy, o ya salieron todos."}</p>
      ) : (
        <ul className="divide-y divide-line">
          {list.map((p) => (
            <li key={p.event_id} className="flex flex-wrap items-center gap-3 py-2.5">
              <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-bg text-sm font-bold text-muted">{initials(p.name)}</span>
              <div className="min-w-0 flex-1">
                <p className="truncate font-semibold">{p.name}</p>
                <p className="truncate text-xs text-muted">
                  {p.company}{p.host_name ? ` · visita a ${p.host_name}` : ""} · {p.access_point} · desde las {hour(p.since)}
                  {p.overridden && <span className="ml-1 font-semibold text-amber-ink">· por excepción</span>}
                </p>
              </div>
              {onExit && (
                <button type="button" onClick={() => onExit(p)} className="h-12 rounded-xl border border-line px-4 text-sm font-semibold hover:border-brand">
                  Registrar salida
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
