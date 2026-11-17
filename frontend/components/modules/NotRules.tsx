"use client";

// Reglas de alerta por evento (M-NOT SCR-NOT-01, RN-NOT-03): cada empresa ajusta, sin tocar código, si el aviso sale,
// a qué roles llega, por qué canales y con cuántos días de anticipación (p. ej. «30, 15, 7»).

import { useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";

type Rule = { code: string; module: string; title: string; priority: string; default_roles: string[]; customized: boolean; is_active: boolean; roles: string[]; lead_days: number[]; channels: string[] };

const ROLE: Record<string, string> = {
  sst_manager: "Jefe SST", sst_supervisor: "Supervisor SST", management: "Gerencia", hr: "RR. HH.", area_lead: "Jefe de área", health_staff: "Personal de salud",
  occupational_doctor: "Médico ocupacional", committee_member: "Miembro del comité", consultant: "Consultor", trainer: "Capacitador", account_admin: "Administrador",
};
const CHANNEL: Record<string, string> = { in_app: "En la app", email: "Correo", whatsapp: "WhatsApp" };

export function NotRules({ readOnly }: { readOnly: boolean }) {
  const [rules, setRules] = useState<Rule[] | null>(null);
  const [roles, setRoles] = useState<string[]>([]);
  const [open, setOpen] = useState<string | null>(null);
  const [form, setForm] = useState<{ is_active: boolean; roles: string[]; lead: string; channels: string[] } | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [q, setQ] = useState("");

  const load = () => api<{ data: Rule[]; roles: string[] }>("/not/rules", { withCompany: true }).then((r) => { setRules(r.data); setRoles(r.roles); }).catch(() => setRules([]));
  useEffect(() => { void load(); }, []);

  const groups = useMemo(() => {
    const g: Record<string, Rule[]> = {};
    for (const r of rules ?? []) if (!q || r.title.toLowerCase().includes(q.toLowerCase())) (g[r.module] ??= []).push(r);
    return g;
  }, [rules, q]);
  if (!rules || rules.length === 0) return null;

  const edit = (r: Rule) => { setOpen(r.code); setMsg(null); setForm({ is_active: r.is_active, roles: r.roles, lead: r.lead_days.join(", "), channels: r.channels }); };
  const toggle = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);
  const save = async (code: string) => {
    if (!form) return;
    const lead = form.lead.split(/[,\s]+/).filter(Boolean).map(Number);
    if (lead.some((n) => !Number.isInteger(n) || n < 0)) { setMsg({ ok: false, text: "Escribe los días como números separados por comas, por ejemplo: 30, 15, 7." }); return; }
    try {
      await api(`/not/rules/${code}`, { method: "PUT", withCompany: true, body: { is_active: form.is_active, roles: form.roles, lead_days: lead, channels: form.channels } });
      setMsg({ ok: true, text: "Regla guardada." });
      setOpen(null);
      void load();
    } catch (e) {
      setMsg({ ok: false, text: e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo guardar." });
    }
  };

  return (
    <section className="space-y-3 rounded-xl border border-line bg-surface p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-semibold">Reglas de alerta</p>
          <p className="text-xs text-muted">Elige qué avisos salen, a quién llegan y con cuántos días de anticipación. Sin días, se usa el plazo por defecto.</p>
        </div>
        <input className="input !w-56" placeholder="Buscar aviso" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
      {Object.entries(groups).map(([mod, list]) => (
        <div key={mod}>
          <p className="mb-1 text-xs font-semibold uppercase text-muted">{mod}</p>
          <ul className="divide-y divide-line rounded-lg border border-line">
            {list.map((r) => (
              <li key={r.code} className="px-3 py-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span>
                    <b className={r.is_active ? "" : "text-muted line-through"}>{r.title}</b>
                    <span className="block text-xs text-muted">
                      {r.roles.length ? r.roles.map((x) => ROLE[x] ?? x).join(", ") : "Solo el responsable"} · {r.channels.map((c) => CHANNEL[c] ?? c).join(", ")}
                      {r.lead_days.length > 0 && ` · ${r.lead_days.join(", ")} días antes`}{r.customized && " · ajustada por tu empresa"}
                    </span>
                  </span>
                  {!readOnly && <button type="button" className="text-xs font-semibold text-brand underline" onClick={() => (open === r.code ? setOpen(null) : edit(r))}>{open === r.code ? "Cerrar" : "Ajustar"}</button>}
                </div>
                {open === r.code && form && (
                  <div className="mt-2 space-y-2 rounded-lg bg-bg p-3">
                    <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Enviar este aviso</label>
                    <div>
                      <p className="text-xs text-muted">Roles que lo reciben (además del responsable del registro)</p>
                      <div className="mt-1 flex flex-wrap gap-2">
                        {roles.map((x) => (
                          <label key={x} className="flex items-center gap-1 text-xs"><input type="checkbox" checked={form.roles.includes(x)} onChange={() => setForm({ ...form, roles: toggle(form.roles, x) })} />{ROLE[x] ?? x}</label>
                        ))}
                      </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      {Object.keys(CHANNEL).map((c) => (
                        <label key={c} className="flex items-center gap-1 text-xs"><input type="checkbox" checked={form.channels.includes(c)} onChange={() => setForm({ ...form, channels: toggle(form.channels, c) })} />{CHANNEL[c]}</label>
                      ))}
                    </div>
                    <label className="block text-xs">Días de anticipación (separados por comas)
                      <input className="input mt-1 !w-48" placeholder="30, 15, 7" value={form.lead} onChange={(e) => setForm({ ...form, lead: e.target.value })} />
                    </label>
                    <Button size="sm" variant="primary" onClick={() => void save(r.code)}>Guardar regla</Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </section>
  );
}
