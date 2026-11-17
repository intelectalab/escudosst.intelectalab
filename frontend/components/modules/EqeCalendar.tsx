"use client";

// Calendario de mantenimiento (M-EQE SCR-EQE-04): recargas, pruebas hidrostáticas, inspecciones y mantenimientos por mes, con las
// vencidas en rojo; y por sede los extintores operativos frente al mínimo configurado (RN-EQE-08).

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Card } from "./Summary";

type Item = { id: string; code: string; equipment_type: string; site: string | null; kind: string; due: string; overdue: boolean };
type Site = { id: string; name: string; min_extinguishers: number | null; operational: number; gap: boolean };
const KIND: Record<string, string> = { recharge: "Recarga", hydrostatic_test: "Prueba hidrostática", inspection: "Inspección", maintenance: "Mantenimiento" };
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];

export function EqeCalendar() {
  const [year, setYear] = useState(new Date().getFullYear());
  const [d, setD] = useState<{ items: Item[]; sites: Site[] } | null>(null);
  useEffect(() => { api<{ data: { items: Item[]; sites: Site[] } }>(`/eqe/calendar?year=${year}`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)); }, [year]);
  if (!d) return null;
  const overdue = d.items.filter((i) => i.overdue);
  return (
    <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card title="Calendario de mantenimiento">
        <input type="number" className="input mb-3 w-28" aria-label="Año" value={year} onChange={(e) => setYear(Number(e.target.value))} />
        {overdue.length > 0 && <p className="mb-2 text-sm font-semibold text-bad">Vencidos: {overdue.length}</p>}
        <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
          {MONTHS.map((m, i) => {
            const list = d.items.filter((x) => !x.overdue && Number(x.due.slice(0, 4)) === year && Number(x.due.slice(5, 7)) === i + 1);
            return (
              <div key={m} className="rounded-lg border border-line p-2 text-xs">
                <p className="mb-1 font-semibold">{m}</p>
                {list.length === 0 ? <p className="text-muted">—</p> : list.map((x) => <p key={`${x.id}-${x.kind}`}>{x.due.slice(8, 10)} · {KIND[x.kind] ?? x.kind} · <b>{x.code}</b></p>)}
              </div>
            );
          })}
        </div>
        {overdue.length > 0 && (
          <ul className="mt-3 space-y-0.5 text-xs text-bad">{overdue.map((x) => <li key={`${x.id}-${x.kind}`}>{KIND[x.kind] ?? x.kind} vencida el {x.due.split("-").reverse().join("/")} · {x.code}{x.site ? ` · ${x.site}` : ""}</li>)}</ul>
        )}
      </Card>
      <Card title="Extintores por sede">
        <ul className="space-y-1 text-sm">
          {d.sites.map((s) => (
            <li key={s.id} className={`flex justify-between border-b border-line pb-1 last:border-0 ${s.gap ? "text-bad" : ""}`}>
              <span>{s.name}</span><span>{s.operational}{s.min_extinguishers != null ? ` de ${s.min_extinguishers} mínimo` : " (sin mínimo configurado)"}</span>
            </li>
          ))}
        </ul>
        <p className="mt-2 text-xs text-muted">El mínimo por sede se configura en la sede («Mínimo de extintores»).</p>
      </Card>
    </div>
  );
}
