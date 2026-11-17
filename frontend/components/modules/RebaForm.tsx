"use client";

// Evaluación ergonómica REBA (M-PSI SCR-PSI-02): se eligen las posturas y ajustes de cada segmento según el método, el sistema
// calcula el puntaje (tablas A, B y C) y el nivel de riesgo, y al guardar queda la evaluación del puesto. Un nivel alto o muy alto
// genera la acción correctiva (RN-PSI-03).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type Res = { table_a: number; score_a: number; table_b: number; score_b: number; table_c: number; score: number; risk_level: string; action: string; id?: string };
type Opt = { id: string; name: string };
const LEVEL: Record<string, [string, string]> = { low: ["Bajo", "text-ok"], medium: ["Medio", "text-amber-ink"], high: ["Alto", "text-bad"], very_high: ["Muy alto", "text-bad"] };

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

export function RebaForm({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [f, setF] = useState({ trunk: 1, trunkTwist: false, neck: 1, neckTwist: false, legs: 1, knees: 0, load: 0, shock: false, upper: 1, abduct: false, shoulder: false, support: false,
    lower: 1, wrist: 1, wristDev: false, coupling: 0, act1: false, act2: false, act3: false });
  const [positions, setPositions] = useState<Opt[]>([]);
  const [position, setPosition] = useState("");
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [task, setTask] = useState("");
  const [rec, setRec] = useState("");
  const [res, setRes] = useState<Res | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));

  const body = {
    trunk: Math.min(5, f.trunk + (f.trunkTwist ? 1 : 0)), neck: Math.min(3, f.neck + (f.neckTwist ? 1 : 0)), legs: Math.min(4, f.legs + f.knees),
    load: Math.min(3, f.load + (f.shock ? 1 : 0)),
    upper_arm: Math.max(1, Math.min(6, f.upper + (f.abduct ? 1 : 0) + (f.shoulder ? 1 : 0) - (f.support ? 1 : 0))), lower_arm: f.lower, wrist: Math.min(3, f.wrist + (f.wristDev ? 1 : 0)),
    coupling: f.coupling, activity: [f.act1, f.act2, f.act3].filter(Boolean).length,
  };
  const key = JSON.stringify(body);
  useEffect(() => {
    api<{ data: Res }>("/psi/reba", { method: "POST", withCompany: true, body: JSON.parse(key) }).then((r) => setRes(r.data)).catch(() => setRes(null));
  }, [key]);
  useEffect(() => { api<{ data: Opt[] }>("/records/TEN/positions?per_page=100", { withCompany: true }).then((r) => setPositions(r.data ?? [])).catch(() => setPositions([])); }, []);

  const save = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: Res }>("/psi/reba", { method: "POST", withCompany: true, body: { ...body, save: true, position_id: position, assessed_on: date, task: task || null, recommendations: rec || null } });
      setMsg({ ok: true, text: `Evaluación guardada con puntaje ${r.data.score} (${LEVEL[r.data.risk_level]?.[0]}).${["high", "very_high"].includes(r.data.risk_level) ? " Se creó la acción correctiva." : ""}` });
      onSaved();
    } catch (e) {
      const err = e as ApiError;
      setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) });
    } finally { setBusy(false); }
  };

  return (
    <Card title="Evaluación ergonómica REBA">
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="space-y-2">
          <h4 className="text-sm font-semibold">Grupo A: tronco, cuello y piernas</h4>
          <Sel label="Tronco" value={f.trunk} onChange={(v) => set({ trunk: v })} options={[[1, "1 · Erguido"], [2, "2 · Flexión o extensión de 0° a 20°"], [3, "3 · Flexión de 20° a 60° o extensión mayor de 20°"], [4, "4 · Flexión mayor de 60°"]]} />
          <Chk label="Tronco con torsión o inclinación lateral (+1)" checked={f.trunkTwist} onChange={(v) => set({ trunkTwist: v })} />
          <Sel label="Cuello" value={f.neck} onChange={(v) => set({ neck: v })} options={[[1, "1 · Flexión de 0° a 20°"], [2, "2 · Flexión mayor de 20° o extensión"]]} />
          <Chk label="Cuello con torsión o inclinación lateral (+1)" checked={f.neckTwist} onChange={(v) => set({ neckTwist: v })} />
          <Sel label="Piernas" value={f.legs} onChange={(v) => set({ legs: v })} options={[[1, "1 · Soporte bilateral, andando o sentado"], [2, "2 · Soporte unilateral o postura inestable"]]} />
          <Sel label="Rodillas" value={f.knees} onChange={(v) => set({ knees: v })} options={[[0, "Sin flexión importante (+0)"], [1, "Flexión de 30° a 60° (+1)"], [2, "Flexión mayor de 60°, sin estar sentado (+2)"]]} />
          <Sel label="Carga o fuerza" value={f.load} onChange={(v) => set({ load: v })} options={[[0, "Menos de 5 kg (+0)"], [1, "De 5 a 10 kg (+1)"], [2, "Más de 10 kg (+2)"]]} />
          <Chk label="Fuerza aplicada bruscamente (+1)" checked={f.shock} onChange={(v) => set({ shock: v })} />
        </div>
        <div className="space-y-2">
          <h4 className="text-sm font-semibold">Grupo B: brazo, antebrazo y muñeca</h4>
          <Sel label="Brazo" value={f.upper} onChange={(v) => set({ upper: v })} options={[[1, "1 · De 20° de extensión a 20° de flexión"], [2, "2 · Extensión mayor de 20° o flexión de 20° a 45°"], [3, "3 · Flexión de 45° a 90°"], [4, "4 · Flexión mayor de 90°"]]} />
          <Chk label="Brazo abducido o rotado (+1)" checked={f.abduct} onChange={(v) => set({ abduct: v })} />
          <Chk label="Hombro elevado (+1)" checked={f.shoulder} onChange={(v) => set({ shoulder: v })} />
          <Chk label="Brazo apoyado o postura a favor de la gravedad (−1)" checked={f.support} onChange={(v) => set({ support: v })} />
          <Sel label="Antebrazo" value={f.lower} onChange={(v) => set({ lower: v })} options={[[1, "1 · Flexión de 60° a 100°"], [2, "2 · Flexión menor de 60° o mayor de 100°"]]} />
          <Sel label="Muñeca" value={f.wrist} onChange={(v) => set({ wrist: v })} options={[[1, "1 · Flexión o extensión de 0° a 15°"], [2, "2 · Flexión o extensión mayor de 15°"]]} />
          <Chk label="Muñeca con desviación o torsión (+1)" checked={f.wristDev} onChange={(v) => set({ wristDev: v })} />
          <Sel label="Agarre" value={f.coupling} onChange={(v) => set({ coupling: v })} options={[[0, "Bueno (+0)"], [1, "Regular (+1)"], [2, "Malo (+2)"], [3, "Inaceptable (+3)"]]} />
          <h4 className="pt-1 text-sm font-semibold">Actividad</h4>
          <Chk label="Una o más partes del cuerpo estáticas más de 1 minuto (+1)" checked={f.act1} onChange={(v) => set({ act1: v })} />
          <Chk label="Movimientos repetitivos, más de 4 veces por minuto (+1)" checked={f.act2} onChange={(v) => set({ act2: v })} />
          <Chk label="Cambios posturales importantes o posturas inestables (+1)" checked={f.act3} onChange={(v) => set({ act3: v })} />
        </div>
      </div>
      {res && (
        <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Kpi label="Puntaje A" value={String(res.score_a)} sub={`Tabla A ${res.table_a}`} />
          <Kpi label="Puntaje B" value={String(res.score_b)} sub={`Tabla B ${res.table_b}`} />
          <Kpi label="Puntaje REBA" value={String(res.score)} sub={`Tabla C ${res.table_c}`} />
          <Kpi label="Nivel de riesgo" value={LEVEL[res.risk_level]?.[0] ?? res.risk_level} color={LEVEL[res.risk_level]?.[1]} sub={res.action} />
        </div>
      )}
      {!readOnly && (
        <div className="mt-4 grid gap-2 sm:grid-cols-2">
          <select className="input" aria-label="Puesto evaluado" value={position} onChange={(e) => setPosition(e.target.value)}>
            <option value="">Puesto evaluado…</option>{positions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <input type="date" className="input" aria-label="Fecha de la evaluación" value={date} onChange={(e) => setDate(e.target.value)} />
          <input className="input sm:col-span-2" placeholder="Tarea evaluada (opcional)" value={task} onChange={(e) => setTask(e.target.value)} />
          <textarea className="input min-h-[56px] sm:col-span-2" placeholder="Recomendaciones (opcional)" value={rec} onChange={(e) => setRec(e.target.value)} />
          <div className="sm:col-span-2"><Button variant="primary" loading={busy} disabled={busy || !position || !res} onClick={save}>Guardar evaluación</Button></div>
        </div>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </Card>
  );
}
