"use client";

// Checklist de la inspección por QR por tipo de equipo (M-EQE SCR-EQE-02). La ficha da «presión, precinto, señalización,
// acceso libre» como lista por defecto; la empresa ajusta los ítems de cada tipo (uno por línea).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Data = { default: string[]; types: Record<string, string[]>; own: string[]; can_edit: boolean };
const TYPE: Record<string, string> = { fire_extinguisher: "Extintor", emergency_light: "Luz de emergencia", alarm: "Alarma", hose_cabinet: "Gabinete contra incendio", eyewash: "Lavaojos",
  shower: "Ducha de emergencia", stretcher: "Camilla", aed: "Desfibrilador", signage: "Señalización", other: "Otro" };

export function EqeChecklists() {
  const [d, setD] = useState<Data | null>(null);
  const [type, setType] = useState("fire_extinguisher");
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  useEffect(() => { api<{ data: Data }>("/eqe/checklists", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)); }, []);
  useEffect(() => { if (d) setText((d.types[type] ?? d.default).join("\n")); }, [d, type]);
  if (!d) return null;

  const save = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const own = Object.fromEntries(d.own.map((t) => [t, d.types[t]]));
      const items = text.split("\n").map((x) => x.trim()).filter(Boolean);
      const body = { checklists: { "*": d.default, ...own, [type]: items } };
      const r = await api<{ data: Data }>("/eqe/checklists", { method: "PUT", body, withCompany: true });
      setD(r.data);
      setMsg({ ok: true, text: `Checklist de «${TYPE[type]}» guardado.` });
    } catch (e) {
      const err = e as ApiError;
      setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Checklist de inspección por tipo de equipo">
      <div className="grid gap-3 sm:grid-cols-[14rem_minmax(0,1fr)]">
        <label className="block text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Tipo de equipo</span>
          <select className="input" value={type} onChange={(e) => setType(e.target.value)}>
            {Object.entries(TYPE).map(([v, l]) => <option key={v} value={v}>{l}{d.own.includes(v) ? " (propio)" : ""}</option>)}
          </select>
          {!d.own.includes(type) && <span className="mt-1 block text-xs text-muted">Usa la lista por defecto.</span>}
        </label>
        <label className="block text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Ítems (uno por línea)</span>
          <textarea className="input min-h-[110px]" value={text} readOnly={!d.can_edit} onChange={(e) => setText(e.target.value)} /></label>
      </div>
      {d.can_edit && <div className="mt-2 flex items-center gap-3"><Button variant="secondary" loading={busy} disabled={busy || !text.trim()} onClick={save}>Guardar checklist</Button>
        {msg && <span role="status" className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</span>}</div>}
    </Card>
  );
}
