"use client";

// ISO: evaluar cada cláusula con los datos reales de los módulos. PSE: aplicar el paquete del sector a la empresa.

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";

// RN-ISO-01/03: cobertura derivada por cláusula.
const COVERAGE: Record<string, [string, string]> = {
  covered: ["Cubierta", "bg-ok-soft text-ok"], review: ["Por revisar", "bg-amber-soft text-amber-ink"], none: ["Sin evidencia", "bg-bad-soft text-bad"], not_applicable: ["No aplica", "bg-bg text-muted"],
};
const ISO_ST: Record<string, [string, string]> = {
  implemented: ["Implementado", "bg-ok-soft text-ok"], verified: ["Verificado", "bg-ok-soft text-ok"], in_progress: ["En curso", "bg-amber-soft text-amber-ink"],
  not_started: ["Sin evidencia", "bg-bad-soft text-bad"], not_applicable: ["No aplica", "bg-bg text-muted"],
};

export function IsoAssess({ onDone }: { onDone: () => void }) {
  const [std, setStd] = useState("ISO45001");
  const [res, setRes] = useState<{ standard: string; implemented: number; total: number; review?: number; clauses: { clause: string; title: string; status: string; coverage?: string; evidence: string }[] } | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const run = () => {
    setBusy(true);
    setMsg(null);
    api<{ data: NonNullable<typeof res> }>("/iso/assess", { method: "POST", withCompany: true, body: { standard: std } })
      .then((r) => { setRes(r.data); onDone(); })
      .catch((e: ApiError) => setMsg(e.detail ?? e.title))
      .finally(() => setBusy(false));
  };
  return (
    <section className="space-y-3 rounded-xl border border-line p-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <span className="flex-1">Revisa cada cláusula con lo que ya registraste en EscudoSST (política, IPERC, capacitaciones, auditorías, acciones…).</span>
        <select className="input !w-40" value={std} onChange={(e) => setStd(e.target.value)} aria-label="Norma">
          <option value="ISO45001">ISO 45001</option>
          <option value="ISO9001">ISO 9001</option>
          <option value="ISO14001">ISO 14001</option>
        </select>
        <Button variant="primary" loading={busy} onClick={run}>Evaluar con mis datos</Button>
      </div>
      {msg && <p className="text-bad">{msg}</p>}
      {res && (
        <>
          <p className="font-semibold">{res.standard}: {res.implemented} de {res.total} cláusulas cubiertas{res.review ? ` · ${res.review} por revisar` : ""}.</p>
          <ul className="space-y-1">
            {res.clauses.map((c) => {
              const [l, cls] = (c.coverage ? COVERAGE[c.coverage] : undefined) ?? ISO_ST[c.status] ?? [c.status, "bg-bg"];
              return (
                <li key={c.clause} className="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-line px-3 py-2">
                  <span><b>{c.clause}. {c.title}</b><span className="block text-xs text-muted">{c.evidence}</span></span>
                  <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${cls}`}>{l}</span>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </section>
  );
}

type Pack = { id: string; code: string; name: string; sector: string | null; description: string | null; version_number: number; items: number;
  install_status: string | null; applied_at: string | null; installed_version: number | null; update_available: boolean | null };
type PreviewItem = { id: string; type_label: string; name: string; version: number; action: "install" | "link" | "installed" | "unsupported"; detail: string };
type UpdateItem = { id: string; type_label: string; name: string; change: "new" | "changed"; from_version: number | null; to_version: number; edited: boolean };
type InvRow = { pack: string; status: string; type: string; name: string; installed_version: number | null; latest_version: number | null; records: number; edited: boolean };
const ACTION: Record<PreviewItem["action"], [string, string]> = {
  install: ["Se copia", "bg-brand-soft text-brand-strong"], link: ["Se vincula", "bg-ok-soft text-ok"], installed: ["Ya instalado", "bg-bg text-muted"], unsupported: ["Sin contenido aún", "bg-bg text-muted"],
};
const errText = (e: unknown) => (e instanceof ApiError ? e.detail ?? e.title : "No se pudo completar.");
const toggle = (set: Set<string>, id: string) => {
  const n = new Set(set);
  if (n.has(id)) n.delete(id);
  else n.add(id);
  return n;
};

/**
 * Paquetes sectoriales (M-PSE): SCR-PSE-01 catálogo, SCR-PSE-02 instalar con vista previa y elección de ítems,
 * SCR-PSE-03 actualizaciones por ítem (lo editado por la empresa pide confirmación), desinstalar e inventario (§11).
 */
export function PseApply({ onDone }: { onDone: () => void }) {
  const [packs, setPacks] = useState<Pack[] | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [wizard, setWizard] = useState<{ pack: Pack; items: PreviewItem[]; picked: Set<string> } | null>(null);
  const [updates, setUpdates] = useState<{ pack: Pack; items: UpdateItem[]; picked: Set<string>; confirm: Set<string> } | null>(null);
  const [inv, setInv] = useState<InvRow[] | null>(null);
  const [busy, setBusy] = useState(false);
  const load = () => api<{ data: Pack[] }>("/pse/packs", { withCompany: true }).then((r) => setPacks(r.data)).catch(() => setPacks([]));
  useEffect(() => {
    load();
  }, []);

  const openWizard = (p: Pack) =>
    api<{ data: { items: PreviewItem[] } }>(`/pse/packs/${p.id}/preview`, { withCompany: true })
      .then((r) => setWizard({ pack: p, items: r.data.items, picked: new Set(r.data.items.filter((i) => i.action === "install" || i.action === "link").map((i) => i.id)) }))
      .catch((e) => setMsg({ text: errText(e), ok: false }));

  async function install() {
    if (!wizard) return;
    setBusy(true);
    try {
      const r = await api<{ data: { installed: unknown[]; linked: unknown[]; skipped: unknown[] } }>(`/pse/packs/${wizard.pack.id}/install`, { method: "POST", withCompany: true, body: { items: [...wizard.picked] } });
      setMsg({ text: `${wizard.pack.name}: ${r.data.installed.length} copiados a tu empresa, ${r.data.linked.length} vinculados sin duplicar${r.data.skipped.length ? `, ${r.data.skipped.length} sin contenido` : ""}.`, ok: true });
      setWizard(null);
      load();
      onDone();
    } catch (e) {
      setMsg({ text: errText(e), ok: false });
    } finally {
      setBusy(false);
    }
  }

  const openUpdates = (p: Pack) =>
    api<{ data: { items: UpdateItem[] } }>(`/pse/packs/${p.id}/updates`, { withCompany: true })
      .then((r) => setUpdates({ pack: p, items: r.data.items, picked: new Set(r.data.items.filter((i) => !i.edited).map((i) => i.id)), confirm: new Set() }))
      .catch((e) => setMsg({ text: errText(e), ok: false }));

  async function applyUpdates() {
    if (!updates) return;
    setBusy(true);
    try {
      const r = await api<{ data: { updated: unknown[]; added: unknown[]; kept: unknown[] } }>(`/pse/packs/${updates.pack.id}/updates`, { method: "POST", withCompany: true, body: { items: [...updates.picked], confirm: [...updates.confirm] } });
      setMsg({ text: `${updates.pack.name}: ${r.data.updated.length} actualizados, ${r.data.added.length} nuevos${r.data.kept.length ? `, ${r.data.kept.length} se conservaron como los editaste` : ""}.`, ok: true });
      setUpdates(null);
      load();
      onDone();
    } catch (e) {
      setMsg({ text: errText(e), ok: false });
    } finally {
      setBusy(false);
    }
  }

  async function uninstall(p: Pack) {
    if (!window.confirm(`¿Desinstalar «${p.name}»? El contenido que ya usas (plantillas, capacitaciones, permisos) se conserva; solo deja de estar ligado al paquete.`)) return;
    try {
      await api(`/pse/packs/${p.id}/uninstall`, { method: "POST", withCompany: true });
      setMsg({ text: `${p.name}: desinstalado. Tu contenido se conservó.`, ok: true });
      load();
    } catch (e) {
      setMsg({ text: errText(e), ok: false });
    }
  }

  const showInventory = () => api<{ data: InvRow[] }>("/pse/inventory", { withCompany: true }).then((r) => setInv(r.data)).catch((e) => setMsg({ text: errText(e), ok: false }));

  if (!packs) return null;
  return (
    <section className="space-y-3 rounded-xl border border-line p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p>Elige el paquete de tu sector. Su contenido se copia a tu empresa y lo puedes editar; instalar dos veces no duplica nada.</p>
        <Button size="sm" variant="secondary" onClick={showInventory}>Ver contenido instalado</Button>
      </div>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {packs.map((p) => {
          const installed = !!p.install_status && p.install_status !== "uninstalled";
          const current = p.installed_version ?? p.version_number;
          return (
            <div key={p.id} className="flex flex-col justify-between gap-2 rounded-lg border border-line p-3">
              <div>
                <b>{p.name}</b>
                <span className="block text-xs text-muted">Versión {p.version_number} · {p.items} elementos{p.description ? ` · ${p.description}` : ""}</span>
                {installed && <span className="mt-1 block text-xs font-semibold text-ok">Instalado (versión {current})</span>}
                {installed && (p.update_available || current < p.version_number) && <span className="block text-xs font-semibold text-amber-ink">Hay una actualización disponible</span>}
              </div>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" variant={installed ? "secondary" : "primary"} onClick={() => openWizard(p)}>{installed ? "Agregar lo que falte" : "Instalar"}</Button>
                {installed && <Button size="sm" variant="secondary" onClick={() => openUpdates(p)}>Actualizaciones</Button>}
                {installed && <Button size="sm" variant="danger-ghost" onClick={() => uninstall(p)}>Desinstalar</Button>}
              </div>
            </div>
          );
        })}
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}

      {wizard && (
        <Modal
          title={`Instalar ${wizard.pack.name}`}
          subtitle="Elige qué instalar. Lo que tu empresa ya tiene con el mismo código se vincula sin duplicar."
          onClose={() => setWizard(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setWizard(null)}>Cancelar</Button>
              <Button variant="primary" loading={busy} disabled={wizard.picked.size === 0} onClick={install}>Instalar {wizard.picked.size} elemento(s)</Button>
            </>
          }
        >
          <ul className="divide-y divide-line rounded-lg border border-line">
            {wizard.items.map((i) => (
              <li key={i.id} className="flex items-start gap-3 p-3">
                <input type="checkbox" className="mt-1" aria-label={`Instalar ${i.name}`} disabled={i.action === "installed" || i.action === "unsupported"}
                  checked={wizard.picked.has(i.id)} onChange={() => setWizard({ ...wizard, picked: toggle(wizard.picked, i.id) })} />
                <span className="min-w-0 flex-1"><b>{i.name}</b><span className="block text-xs text-muted">{i.type_label} · {i.detail}</span></span>
                <span className={`whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-semibold ${ACTION[i.action][1]}`}>{ACTION[i.action][0]}</span>
              </li>
            ))}
          </ul>
        </Modal>
      )}

      {updates && (
        <Modal
          title={`Actualizaciones de ${updates.pack.name}`}
          subtitle="Elige qué actualizar. Lo que tu empresa editó solo se reemplaza si lo confirmas."
          onClose={() => setUpdates(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setUpdates(null)}>Cerrar</Button>
              {updates.items.length > 0 && <Button variant="primary" loading={busy} disabled={updates.picked.size === 0} onClick={applyUpdates}>Aplicar a {updates.picked.size}</Button>}
            </>
          }
        >
          {updates.items.length === 0 ? (
            <p className="text-muted">Tienes la última versión de todo el contenido.</p>
          ) : (
            <ul className="divide-y divide-line rounded-lg border border-line">
              {updates.items.map((i) => (
                <li key={i.id} className="space-y-1 p-3">
                  <label className="flex items-start gap-3">
                    <input type="checkbox" className="mt-1" checked={updates.picked.has(i.id)} onChange={() => setUpdates({ ...updates, picked: toggle(updates.picked, i.id) })} />
                    <span className="min-w-0 flex-1">
                      <b>{i.name}</b>
                      <span className="block text-xs text-muted">{i.type_label} · {i.change === "new" ? `Nuevo en la versión ${i.to_version}` : `Cambió: versión ${i.from_version} → ${i.to_version}`}</span>
                    </span>
                  </label>
                  {i.edited && (
                    <label className="ml-7 flex items-center gap-2 text-xs text-amber-ink">
                      <input type="checkbox" checked={updates.confirm.has(i.id)} onChange={() => setUpdates({ ...updates, confirm: toggle(updates.confirm, i.id) })} />
                      Tu empresa lo editó: reemplazar con la versión del paquete
                    </label>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Modal>
      )}

      {inv && (
        <Modal title="Contenido sectorial instalado" onClose={() => setInv(null)}>
          {inv.length === 0 ? (
            <p className="text-muted">Aún no instalaste paquetes.</p>
          ) : (
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Contenido instalado">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-muted"><th className="p-2">Paquete</th><th className="p-2">Tipo</th><th className="p-2">Elemento</th><th className="p-2">Versión</th><th className="p-2">Estado</th></tr>
                </thead>
                <tbody>
                  {inv.map((r, k) => (
                    <tr key={k} className="border-b border-line last:border-0">
                      <td className="p-2">{r.pack}</td>
                      <td className="p-2">{r.type}</td>
                      <td className="p-2">{r.name}</td>
                      <td className="whitespace-nowrap p-2">{r.installed_version ?? "—"}{r.latest_version && r.installed_version && r.latest_version > r.installed_version ? ` (hay ${r.latest_version})` : ""}</td>
                      <td className="p-2">{r.status === "uninstalled" ? "Desinstalado" : r.edited ? "Editado por tu empresa" : "Como el paquete"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Modal>
      )}
    </section>
  );
}
