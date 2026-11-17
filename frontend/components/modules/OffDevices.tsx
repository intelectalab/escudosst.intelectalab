"use client";

// Dispositivos de la app sin conexión (M-OFF SCR-OFF-05): por usuario, con su última sincronización; revocar o pedir el
// borrado remoto (el equipo borra sus datos al conectarse, RN-OFF-04) y reporte de sincronización en Excel.

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { offlineState, packageInfo, preparePackage, type OfflinePackageInfo } from "@/lib/offline";
import { ReportButton } from "./CtrExtras";

type Device = { id: string; device_uid: string; platform: string; model: string | null; app_version: string | null; status: string; registered_at: string | null;
  last_sync_at: string | null; revoked_at: string | null; revoke_reason: string | null; user_name: string | null; open_conflicts: number; rejected: number };

const STATUS: Record<string, string> = { active: "Activo", revoked: "Revocado", lost: "Borrado remoto pedido", wiped: "Datos borrados" };
/** El modelo de un equipo web llega como el texto completo del navegador: se resume en «Navegador en Sistema». */
function deviceName(model: string | null, uid: string): string {
  if (!model) return uid.slice(0, 12);
  if (model.length <= 40) return model;
  const os = /Android/.test(model) ? "Android" : /iPhone|iPad/.test(model) ? "iPhone/iPad" : /Windows/.test(model) ? "Windows" : /Mac OS/.test(model) ? "Mac" : /Linux/.test(model) ? "Linux" : "";
  const br = /Edg\//.test(model) ? "Edge" : /Firefox\//.test(model) ? "Firefox" : /Chrome\//.test(model) ? "Chrome" : /Safari\//.test(model) ? "Safari" : "Navegador";
  return os ? `${br} en ${os}` : br;
}
const dt = (v: string | null) => (v ? new Date(v).toLocaleString("es-PE", { dateStyle: "short", timeStyle: "short" }) : "—");

export function OffDevices({ readOnly }: { readOnly: boolean }) {
  const [rows, setRows] = useState<Device[] | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = () => api<{ data: Device[] }>("/sync/devices", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows([]));
  useEffect(() => { void load(); }, []);
  const [pkg, setPkg] = useState<OfflinePackageInfo | null>(null);
  const [preparing, setPreparing] = useState(false);
  useEffect(() => { packageInfo().then(setPkg).catch(() => setPkg(null)); }, []);
  const prepare = async () => {
    setPreparing(true);
    try { setPkg(await preparePackage()); setMsg({ ok: true, text: "Paquete guardado en este equipo." }); }
    catch (e) { setMsg({ ok: false, text: e instanceof ApiError ? e.detail ?? e.title : "No se pudo preparar el paquete (¿estás con conexión?)." }); }
    finally { setPreparing(false); }
  };
  if (rows === null) return null;
  const kb = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

  const revoke = async (d: Device, wipe: boolean) => {
    const reason = window.prompt(wipe ? "Motivo del borrado remoto (equipo perdido o robado)" : "Motivo para revocar el dispositivo");
    if (!reason) return;
    try {
      await api(`/sync/devices/${d.id}/revoke`, { method: "POST", withCompany: true, body: { reason, wipe } });
      setMsg({ ok: true, text: wipe ? "Se pidió el borrado remoto: el equipo borrará sus datos al conectarse." : "Dispositivo revocado." });
      void load();
    } catch (e) {
      setMsg({ ok: false, text: e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo revocar." });
    }
  };

  return (
    <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">Dispositivos</p>
        <ReportButton path="/sync/report.xlsx" file="sincronizacion.xlsx" label="Reporte de sincronización" />
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
      {offlineState().enabled && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-bg px-3 py-2">
          <span>
            <b>Paquete sin conexión de este equipo</b>
            <span className="block text-xs text-muted">
              {pkg ? `Preparado ${dt(pkg.prepared_at)} · ${kb(pkg.size_bytes)} · ${pkg.counts.workers ?? 0} trabajadores, ${pkg.counts.inspection_templates ?? 0} plantillas, ${pkg.counts.iperc_rows ?? 0} filas IPERC, ${pkg.counts.ppe_items ?? 0} EPP, ${pkg.counts.credentials ?? 0} fotochecks${pkg.scoped ? " (de tu sede o área)" : ""}. Sin datos médicos.`
                : "Descarga trabajadores, plantillas, IPERC, EPP y estado de fotochecks para trabajar sin señal. Sin datos médicos."}
            </span>
          </span>
          <button type="button" disabled={preparing} className="rounded-lg border border-line bg-surface px-3 py-1.5 text-sm font-semibold hover:border-brand disabled:opacity-50" onClick={() => void prepare()}>
            {preparing ? "Preparando…" : pkg ? "Actualizar paquete" : "Preparar paquete"}
          </button>
        </div>
      )}
      {rows.length === 0 ? <p className="text-muted">Aún no hay dispositivos registrados.</p> : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left">
            <thead className="border-b border-line text-xs text-muted">
              <tr><th className="py-2">Usuario</th><th>Equipo</th><th>Estado</th><th>Última sincronización</th><th>Por revisar</th><th /></tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((d) => (
                <tr key={d.id}>
                  <td className="py-2">{d.user_name ?? "—"}</td>
                  <td title={d.model ?? ""}>{deviceName(d.model, d.device_uid)}<span className="block text-xs text-muted">{d.platform}{d.app_version ? ` · ${d.app_version}` : ""}</span></td>
                  <td>{STATUS[d.status] ?? d.status}{d.revoke_reason && <span className="block text-xs text-muted">{d.revoke_reason}</span>}</td>
                  <td>{dt(d.last_sync_at)}</td>
                  <td>{d.open_conflicts > 0 || d.rejected > 0 ? `${d.open_conflicts} conflicto(s) · ${d.rejected} rechazada(s)` : "—"}</td>
                  <td className="whitespace-nowrap text-right">
                    {!readOnly && d.status === "active" && (
                      <>
                        <button type="button" className="mr-2 text-xs font-semibold text-brand underline" onClick={() => void revoke(d, false)}>Revocar</button>
                        <button type="button" className="text-xs font-semibold text-bad underline" onClick={() => void revoke(d, true)}>Borrado remoto</button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
