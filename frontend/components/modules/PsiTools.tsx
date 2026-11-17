"use client";

// Ergonomía y psicosocial (M-PSI): formulario RULA con su cálculo y lista de verificación de la R.M. 375-2008-TR (SCR-PSI-02),
// informe ergonómico por puesto con indicadores (§11) y cuestionario psicosocial propio con versiones y baremo (RN-PSI-04).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type Opt = { id: string; name: string };
const LEVEL: Record<string, [string, string]> = { low: ["Bajo", "text-ok"], medium: ["Medio", "text-amber-ink"], high: ["Alto", "text-bad"], very_high: ["Muy alto", "text-bad"] };
const errText = (e: unknown) => { const x = e as ApiError; return x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title); };

function Sel({ label, value, onChange, options }: { label: string; value: number; onChange: (v: number) => void; options: [number, string][] }) {
  return (
    <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">{label}</span>
      <select className="input" value={value} onChange={(e) => onChange(Number(e.target.value))}>{options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
    </label>
  );
}
function Chk({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} />{label}</label>;
}
function usePositions() {
  const [p, setP] = useState<Opt[]>([]);
  useEffect(() => { api<{ data: Opt[] }>("/records/TEN/positions?per_page=100", { withCompany: true }).then((r) => setP(r.data ?? [])).catch(() => setP([])); }, []);
  return p;
}

type Rula = { table_a: number; score_c: number; table_b: number; score_d: number; score: number; risk_level: string; action: string };

export function RulaForm({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [f, setF] = useState({ upper: 1, shoulder: false, abduct: false, support: false, lower: 1, midline: false, wrist: 1, wristDev: false, twist: 1, armMuscle: false, armForce: 0,
    neck: 1, neckTwist: false, neckSide: false, trunk: 1, trunkTwist: false, trunkSide: false, legs: 1, bodyMuscle: false, bodyForce: 0 });
  const positions = usePositions();
  const [position, setPosition] = useState("");
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [task, setTask] = useState("");
  const [rec, setRec] = useState("");
  const [res, setRes] = useState<Rula | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const body = {
    upper_arm: Math.max(1, Math.min(6, f.upper + (f.shoulder ? 1 : 0) + (f.abduct ? 1 : 0) - (f.support ? 1 : 0))), lower_arm: Math.min(3, f.lower + (f.midline ? 1 : 0)),
    wrist: Math.min(4, f.wrist + (f.wristDev ? 1 : 0)), wrist_twist: f.twist, arm_muscle: f.armMuscle ? 1 : 0, arm_force: f.armForce,
    neck: Math.min(6, f.neck + (f.neckTwist ? 1 : 0) + (f.neckSide ? 1 : 0)), trunk: Math.min(6, f.trunk + (f.trunkTwist ? 1 : 0) + (f.trunkSide ? 1 : 0)), legs: f.legs,
    body_muscle: f.bodyMuscle ? 1 : 0, body_force: f.bodyForce,
  };
  const key = JSON.stringify(body);
  useEffect(() => { api<{ data: Rula }>("/psi/rula", { method: "POST", withCompany: true, body: JSON.parse(key) }).then((r) => setRes(r.data)).catch(() => setRes(null)); }, [key]);
  const force: [number, string][] = [[0, "Menos de 2 kg intermitente (+0)"], [1, "De 2 a 10 kg intermitente (+1)"], [2, "De 2 a 10 kg estática o repetida (+2)"], [3, "Más de 10 kg o golpes (+3)"]];
  const save = async () => {
    setMsg(null);
    try {
      const r = await api<{ data: Rula }>("/psi/rula", { method: "POST", withCompany: true, body: { ...body, save: true, position_id: position, assessed_on: date, task: task || null, recommendations: rec || null } });
      setMsg({ ok: true, text: `Evaluación guardada con puntaje ${r.data.score} (${LEVEL[r.data.risk_level]?.[0]}).${["high", "very_high"].includes(r.data.risk_level) ? " Se creó la acción correctiva." : ""}` });
      onSaved();
    } catch (e) { setMsg({ ok: false, text: errText(e) }); }
  };
  return (
    <Card title="Evaluación ergonómica RULA">
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="space-y-2">
          <h4 className="text-sm font-semibold">Grupo A: brazo, antebrazo y muñeca</h4>
          <Sel label="Brazo" value={f.upper} onChange={(v) => set({ upper: v })} options={[[1, "1 · De 20° de extensión a 20° de flexión"], [2, "2 · Extensión mayor de 20° o flexión de 20° a 45°"], [3, "3 · Flexión de 45° a 90°"], [4, "4 · Flexión mayor de 90°"]]} />
          <Chk label="Hombro elevado (+1)" checked={f.shoulder} onChange={(v) => set({ shoulder: v })} />
          <Chk label="Brazo abducido (+1)" checked={f.abduct} onChange={(v) => set({ abduct: v })} />
          <Chk label="Brazo apoyado o persona inclinada (−1)" checked={f.support} onChange={(v) => set({ support: v })} />
          <Sel label="Antebrazo" value={f.lower} onChange={(v) => set({ lower: v })} options={[[1, "1 · Flexión de 60° a 100°"], [2, "2 · Flexión menor de 60° o mayor de 100°"]]} />
          <Chk label="Antebrazo cruza la línea media o trabaja hacia un lado (+1)" checked={f.midline} onChange={(v) => set({ midline: v })} />
          <Sel label="Muñeca" value={f.wrist} onChange={(v) => set({ wrist: v })} options={[[1, "1 · Posición neutra"], [2, "2 · Flexión o extensión de 0° a 15°"], [3, "3 · Flexión o extensión mayor de 15°"]]} />
          <Chk label="Muñeca con desviación radial o cubital (+1)" checked={f.wristDev} onChange={(v) => set({ wristDev: v })} />
          <Sel label="Giro de muñeca" value={f.twist} onChange={(v) => set({ twist: v })} options={[[1, "1 · En el rango medio"], [2, "2 · En o cerca del final del rango"]]} />
          <Chk label="Postura estática más de 1 minuto o repetida más de 4 veces por minuto (+1)" checked={f.armMuscle} onChange={(v) => set({ armMuscle: v })} />
          <Sel label="Fuerza o carga" value={f.armForce} onChange={(v) => set({ armForce: v })} options={force} />
        </div>
        <div className="space-y-2">
          <h4 className="text-sm font-semibold">Grupo B: cuello, tronco y piernas</h4>
          <Sel label="Cuello" value={f.neck} onChange={(v) => set({ neck: v })} options={[[1, "1 · Flexión de 0° a 10°"], [2, "2 · Flexión de 10° a 20°"], [3, "3 · Flexión mayor de 20°"], [4, "4 · En extensión"]]} />
          <Chk label="Cuello rotado (+1)" checked={f.neckTwist} onChange={(v) => set({ neckTwist: v })} />
          <Chk label="Cuello con inclinación lateral (+1)" checked={f.neckSide} onChange={(v) => set({ neckSide: v })} />
          <Sel label="Tronco" value={f.trunk} onChange={(v) => set({ trunk: v })} options={[[1, "1 · Sentado con buen apoyo"], [2, "2 · Flexión de 0° a 20°"], [3, "3 · Flexión de 20° a 60°"], [4, "4 · Flexión mayor de 60°"]]} />
          <Chk label="Tronco rotado (+1)" checked={f.trunkTwist} onChange={(v) => set({ trunkTwist: v })} />
          <Chk label="Tronco con inclinación lateral (+1)" checked={f.trunkSide} onChange={(v) => set({ trunkSide: v })} />
          <Sel label="Piernas" value={f.legs} onChange={(v) => set({ legs: v })} options={[[1, "1 · Apoyadas y equilibradas"], [2, "2 · Sin apoyo o desequilibradas"]]} />
          <Chk label="Postura estática más de 1 minuto o repetida más de 4 veces por minuto (+1)" checked={f.bodyMuscle} onChange={(v) => set({ bodyMuscle: v })} />
          <Sel label="Fuerza o carga" value={f.bodyForce} onChange={(v) => set({ bodyForce: v })} options={force} />
        </div>
      </div>
      {res && (
        <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Kpi label="Puntaje C (brazos)" value={String(res.score_c)} sub={`Tabla A ${res.table_a}`} />
          <Kpi label="Puntaje D (cuerpo)" value={String(res.score_d)} sub={`Tabla B ${res.table_b}`} />
          <Kpi label="Puntaje RULA" value={String(res.score)} />
          <Kpi label="Nivel de riesgo" value={LEVEL[res.risk_level]?.[0] ?? res.risk_level} color={LEVEL[res.risk_level]?.[1]} sub={res.action} />
        </div>
      )}
      {!readOnly && (
        <div className="mt-4 grid gap-2 sm:grid-cols-2">
          <select className="input" aria-label="Puesto evaluado" value={position} onChange={(e) => setPosition(e.target.value)}><option value="">Puesto evaluado…</option>{positions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
          <input type="date" className="input" aria-label="Fecha de la evaluación" value={date} onChange={(e) => setDate(e.target.value)} />
          <input className="input sm:col-span-2" placeholder="Tarea evaluada (opcional)" value={task} onChange={(e) => setTask(e.target.value)} />
          <textarea className="input min-h-[56px] sm:col-span-2" placeholder="Recomendaciones (opcional)" value={rec} onChange={(e) => setRec(e.target.value)} />
          <div className="sm:col-span-2"><Button variant="primary" disabled={!position || !res} onClick={save}>Guardar evaluación</Button></div>
        </div>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </Card>
  );
}

export function Rm375Form({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [items, setItems] = useState<string[]>([]);
  const [ans, setAns] = useState<Record<string, { present: boolean; notes: string }>>({});
  const positions = usePositions();
  const [position, setPosition] = useState("");
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [level, setLevel] = useState("");
  const [rec, setRec] = useState("");
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  useEffect(() => { api<{ data: { items: string[] } }>("/psi/rm375", { withCompany: true }).then((r) => setItems(r.data.items)).catch(() => setItems([])); }, []);
  const save = async () => {
    setMsg(null);
    try {
      const answers = Object.fromEntries(items.map((i) => [i, { present: !!ans[i]?.present, notes: ans[i]?.notes || null }]));
      const r = await api<{ data: { present: number; total: number } }>("/psi/rm375", { method: "POST", withCompany: true, body: { answers, risk_level: level, position_id: position, assessed_on: date, recommendations: rec || null } });
      setMsg({ ok: true, text: `Lista guardada: ${r.data.present} de ${r.data.total} factores presentes.` });
      onSaved();
    } catch (e) { setMsg({ ok: false, text: errText(e) }); }
  };
  return (
    <Card title="Lista de verificación R.M. 375-2008-TR">
      <p className="mb-2 text-xs text-muted">Marca los factores de riesgo disergonómico presentes en el puesto. La lista es un parámetro por confirmar con el especialista.</p>
      <ul className="space-y-2">
        {items.map((i) => (
          <li key={i} className="grid gap-2 sm:grid-cols-[1fr_minmax(0,1fr)] sm:items-center">
            <Chk label={i} checked={!!ans[i]?.present} onChange={(v) => setAns({ ...ans, [i]: { present: v, notes: ans[i]?.notes ?? "" } })} />
            <input className="input" placeholder="Observación (opcional)" value={ans[i]?.notes ?? ""} onChange={(e) => setAns({ ...ans, [i]: { present: !!ans[i]?.present, notes: e.target.value } })} />
          </li>
        ))}
      </ul>
      {!readOnly && (
        <div className="mt-3 grid gap-2 sm:grid-cols-3">
          <select className="input" aria-label="Puesto evaluado" value={position} onChange={(e) => setPosition(e.target.value)}><option value="">Puesto evaluado…</option>{positions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
          <input type="date" className="input" aria-label="Fecha" value={date} onChange={(e) => setDate(e.target.value)} />
          <select className="input" aria-label="Nivel de riesgo" value={level} onChange={(e) => setLevel(e.target.value)}><option value="">Nivel de riesgo resultante…</option>{Object.entries(LEVEL).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}</select>
          <textarea className="input min-h-[56px] sm:col-span-3" placeholder="Recomendaciones (opcional)" value={rec} onChange={(e) => setRec(e.target.value)} />
          <div className="sm:col-span-3"><Button variant="primary" disabled={!position || !level || items.length === 0} onClick={save}>Guardar lista</Button></div>
        </div>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </Card>
  );
}

export type ErgoReport = {
  company: { name: string; legal_name: string; ruc: string };
  indicators: { positions: number; evaluated: number; evaluated_pct: number | null; high: number; high_actions_closed: number; by_level: Record<string, number> };
  positions: { position_id: string; position: string; workers: number; id: string | null; method: string | null; assessed_on: string | null; score: string | null; risk_level: string | null;
    recommendations: string | null; assessor: string | null; action_code: string | null; action_status: string | null }[];
};
export const METHOD: Record<string, string> = { REBA: "REBA", RULA: "RULA", OWAS: "OWAS", NIOSH: "NIOSH", OCRA: "OCRA", checklist_rm375: "Lista R.M. 375", other: "Otro" };

export function ErgoSummary() {
  const [d, setD] = useState<ErgoReport | null>(null);
  useEffect(() => { api<{ data: ErgoReport }>("/psi/ergonomics/report", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)); }, []);
  if (!d) return null;
  const i = d.indicators;
  return (
    <Card title="Evaluación ergonómica por puesto" action={<a href="/imprimir/informe-ergonomico" target="_blank" rel="noreferrer" className="text-sm font-semibold text-brand-strong underline">Informe en PDF</a>}>
      <div className="grid gap-3 sm:grid-cols-3">
        <Kpi label="Puestos evaluados" value={`${i.evaluated} de ${i.positions}`} sub={i.evaluated_pct != null ? `${i.evaluated_pct} %` : undefined} />
        <Kpi label="Con nivel alto o muy alto" value={String(i.high)} color={i.high ? "text-bad" : "text-ok"} />
        <Kpi label="Acciones de nivel alto cerradas" value={`${i.high_actions_closed} de ${i.high}`} />
      </div>
    </Card>
  );
}

type Instr = { id: string; version_number: number; title: string; status: string; questions: { dimensions: { code: string; name: string; items: { id: string; text: string }[] }[] }; cutoffs: { medium: number; high: number };
  published_at: string | null; published_by: string | null };
const ISTATUS: Record<string, string> = { draft: "Borrador", current: "Vigente", obsolete: "Obsoleta" };

export function PsiInstruments({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<{ versions: Instr[]; can_edit: boolean } | null>(null);
  const [edit, setEdit] = useState<{ id: string; title: string; dims: { name: string; items: string[] }[]; medium: number; high: number } | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => api<{ data: { versions: Instr[]; can_edit: boolean } }>("/psi/instruments", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)), []);
  useEffect(() => { void load(); }, [load]);
  if (!d) return null;
  const draft = d.versions.find((v) => v.status === "draft");
  const current = d.versions.find((v) => v.status === "current");
  const open = (v: Instr) => setEdit({ id: v.id, title: v.title, dims: v.questions.dimensions.map((x) => ({ name: x.name, items: x.items.map((y) => y.text) })), medium: v.cutoffs.medium, high: v.cutoffs.high });
  const act = async (fn: () => Promise<unknown>, ok: string) => { setMsg(null); try { await fn(); setMsg({ ok: true, text: ok }); await load(); } catch (e) { setMsg({ ok: false, text: errText(e) }); } };
  const saveDraft = () => edit && act(() => api(`/psi/instruments/${edit.id}`, { method: "PUT", withCompany: true, body: { title: edit.title, cutoffs: { medium: edit.medium, high: edit.high },
    questions: { dimensions: edit.dims.map((x) => ({ name: x.name, items: x.items.filter((t) => t.trim()).map((t) => ({ text: t })) })) } } }), "Borrador guardado.");
  return (
    <Card title="Cuestionario psicosocial de la empresa">
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mb-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      <p className="text-sm">{current ? <>Vigente: <b>{current.title} v{current.version_number}</b> · {current.questions.dimensions.length} dimensiones · baremo: medio desde {current.cutoffs.medium}, alto desde {current.cutoffs.high}.</> : "Las encuestas usan el cuestionario base de EscudoSST. Puedes crear el propio de la empresa."}</p>
      <p className="mb-2 text-xs text-muted">Las encuestas nuevas copian la versión vigente; las ya creadas conservan sus preguntas.</p>
      {d.can_edit && !readOnly && !draft && <Button size="sm" onClick={() => act(() => api("/psi/instruments", { method: "POST", withCompany: true }), "Se creó una nueva versión en borrador.")}>Nueva versión</Button>}
      {draft && !edit && d.can_edit && !readOnly && (
        <div className="flex flex-wrap gap-2"><Button size="sm" onClick={() => open(draft)}>Editar borrador v{draft.version_number}</Button>
          <Button size="sm" variant="primary" onClick={() => act(() => api(`/psi/instruments/${draft.id}/publish`, { method: "POST", withCompany: true }), "Versión publicada: las encuestas nuevas la usarán.")}>Publicar v{draft.version_number}</Button></div>
      )}
      {edit && (
        <div className="mt-3 space-y-3 rounded-lg border border-line p-3 text-sm">
          <input className="input" aria-label="Título" value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} />
          {edit.dims.map((dim, k) => (
            <div key={k} className="space-y-1 rounded border border-line p-2">
              <div className="flex gap-2"><input className="input" placeholder="Nombre de la dimensión" value={dim.name} onChange={(e) => setEdit({ ...edit, dims: edit.dims.map((x, j) => (j === k ? { ...x, name: e.target.value } : x)) })} />
                <button type="button" className="text-xs text-bad" onClick={() => setEdit({ ...edit, dims: edit.dims.filter((_, j) => j !== k) })}>Quitar</button></div>
              {dim.items.map((t, n) => (
                <input key={n} className="input" placeholder="Pregunta" value={t} onChange={(e) => setEdit({ ...edit, dims: edit.dims.map((x, j) => (j === k ? { ...x, items: x.items.map((y, m) => (m === n ? e.target.value : y)) } : x)) })} />
              ))}
              <button type="button" className="text-xs font-semibold text-brand-strong" onClick={() => setEdit({ ...edit, dims: edit.dims.map((x, j) => (j === k ? { ...x, items: [...x.items, ""] } : x)) })}>+ Pregunta</button>
            </div>
          ))}
          <button type="button" className="text-sm font-semibold text-brand-strong" onClick={() => setEdit({ ...edit, dims: [...edit.dims, { name: "", items: [""] }] })}>+ Dimensión</button>
          <div className="grid gap-2 sm:grid-cols-2">
            <label className="text-xs text-muted">Riesgo medio desde (0–100)<input type="number" className="input mt-1" value={edit.medium} onChange={(e) => setEdit({ ...edit, medium: Number(e.target.value) })} /></label>
            <label className="text-xs text-muted">Riesgo alto desde (0–100)<input type="number" className="input mt-1" value={edit.high} onChange={(e) => setEdit({ ...edit, high: Number(e.target.value) })} /></label>
          </div>
          <div className="flex gap-2"><Button variant="primary" onClick={saveDraft}>Guardar borrador</Button><Button onClick={() => setEdit(null)}>Cerrar</Button></div>
        </div>
      )}
      {d.versions.length > 0 && (
        <ul className="mt-3 text-xs text-muted">{d.versions.map((v) => <li key={v.id}>v{v.version_number} · {v.title} · {ISTATUS[v.status] ?? v.status}{v.published_by ? ` · publicó ${v.published_by}` : ""}</li>)}</ul>
      )}
    </Card>
  );
}
