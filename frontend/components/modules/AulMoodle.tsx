"use client";

// Conexión opcional con Moodle (M-AUL SCR-AUL-19, docs/09 §11): la empresa que prefiere seguir con su Moodle pega la
// dirección y el token del servicio web (se guarda cifrado y no se vuelve a mostrar), prueba la conexión, enlaza cada
// curso con su id en Moodle, envía las matrículas y trae las notas. A Moodle solo van nombre y correo del participante.

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { errorText } from "./Wizard";

type Conn = {
  id: string; base_url: string; status: "pending" | "active" | "error" | "disabled"; sync_enrollments: boolean; sync_grades: boolean; token_set: string | null;
  last_tested_at: string | null; last_sync_at: string | null; last_sync_status: "ok" | "partial" | "failed" | null; last_error: string | null; site_name: string | null;
};
type MCourse = { id: string; code: string; title: string; status: string; external_course_id: string | null; enrolled: number; synced: number };
type Data = { connection: Conn | null; courses: MCourse[] };

const STATUS: Record<Conn["status"], [string, ChipTone]> = {
  pending: ["Sin probar", "amber"], active: ["Conectado", "ok"], error: ["Con errores", "bad"], disabled: ["Desactivado", "na"],
};
const when = (v: string | null) => (v ? new Date(v).toLocaleString("es-PE", { timeZone: "America/Lima", dateStyle: "short", timeStyle: "short" }) : "nunca");

export function MoodleModal({ readOnly, onClose }: { readOnly: boolean; onClose: () => void }) {
  const [data, setData] = useState<Data | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [f, setF] = useState({ base_url: "", token: "", sync_enrollments: true, sync_grades: true, enabled: true });
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [links, setLinks] = useState<Record<string, string>>({});

  const apply = useCallback((d: Data) => {
    setData(d);
    if (d.connection) {
      setF({ base_url: d.connection.base_url, token: "", sync_enrollments: d.connection.sync_enrollments, sync_grades: d.connection.sync_grades, enabled: d.connection.status !== "disabled" });
    }
    setLinks(Object.fromEntries(d.courses.map((c) => [c.id, c.external_course_id ?? ""])));
  }, []);
  const load = useCallback(() => {
    api<{ data: Data }>("/aul/admin/moodle", { withCompany: true }).then((r) => apply(r.data)).catch((e) => setLoadError(errorText(e)));
  }, [apply]);
  useEffect(load, [load]);

  const run = async (key: string, fn: () => Promise<string>) => {
    setBusy(key);
    setMsg(null);
    try {
      setMsg({ text: await fn(), ok: true });
    } catch (e) {
      setMsg({ text: errorText(e), ok: false });
    } finally {
      setBusy(null);
    }
  };
  const save = () => run("save", async () => {
    const r = await api<{ data: Data }>("/aul/admin/moodle", { method: "PUT", withCompany: true, body: f });
    apply(r.data);
    return "Conexión guardada. Pruébala para activarla.";
  });
  const test = () => run("test", async () => {
    const r = await api<{ data: { ok: boolean; message: string } }>("/aul/admin/moodle/test", { method: "POST", withCompany: true });
    load();
    if (!r.data.ok) throw new Error(r.data.message);
    return r.data.message;
  });
  const link = (c: MCourse) => run(`link:${c.id}`, async () => {
    const v = (links[c.id] ?? "").trim();
    await api(`/aul/admin/moodle/courses/${c.id}`, { method: "PUT", withCompany: true, body: { external_course_id: v || null } });
    load();
    return v ? `${c.code} enlazado con el curso ${v} de Moodle.` : `${c.code} ya no está enlazado con Moodle.`;
  });
  const push = (c: MCourse) => run(`push:${c.id}`, async () => {
    const r = await api<{ data: { sent: number; skipped_without_email: number; errors: string[] } }>(`/aul/admin/moodle/courses/${c.id}/push`, { method: "POST", withCompany: true });
    load();
    if (r.data.errors.length && !r.data.sent) throw new Error(r.data.errors[0]);
    return `${r.data.sent} matrícula(s) enviadas a Moodle.${r.data.skipped_without_email ? ` ${r.data.skipped_without_email} sin correo (no se enviaron).` : ""}${r.data.errors.length ? ` Error: ${r.data.errors[0]}` : ""}`;
  });
  const pull = (c: MCourse) => run(`pull:${c.id}`, async () => {
    const r = await api<{ data: { updated: number; without_grade: number; errors: string[] } }>(`/aul/admin/moodle/courses/${c.id}/pull`, { method: "POST", withCompany: true });
    load();
    if (r.data.errors.length && !r.data.updated) throw new Error(r.data.errors[0]);
    return `${r.data.updated} nota(s) actualizadas.${r.data.without_grade ? ` ${r.data.without_grade} aún sin nota en Moodle.` : ""}`;
  });

  const c = data?.connection ?? null;
  const [label, tone] = c ? STATUS[c.status] : ["Sin configurar", "na" as ChipTone];
  const canSync = !!c && c.status !== "disabled" && !readOnly;

  return (
    <Modal title="Moodle (opcional)" subtitle="Solo si tu empresa prefiere seguir usando su Moodle. El aula de EscudoSST funciona sin él." icon="plug" size="lg" onClose={onClose}
      footer={<div className="flex w-full justify-end"><Button onClick={onClose}>Cerrar</Button></div>}>
      {loadError ? (
        <p className="rounded-lg bg-bg px-3 py-2 text-sm text-muted">{loadError}</p>
      ) : !data ? (
        <div className="h-40 animate-pulse rounded-xl bg-bg" />
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <Chip tone={tone} dot>{label}</Chip>
            {c?.site_name && <span className="text-muted">{c.site_name}</span>}
            {c && <span className="text-xs text-muted">Última prueba: {when(c.last_tested_at)} · última sincronización: {when(c.last_sync_at)}</span>}
          </div>
          {c?.last_error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{c.last_error}</p>}

          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="Dirección de tu Moodle *" hint="Debe empezar con https://">
              <input className="input" value={f.base_url} disabled={readOnly} placeholder="https://aula.tuempresa.com" onChange={(e) => setF({ ...f, base_url: e.target.value })} />
            </FormField>
            <FormField label="Token del servicio web *"
              hint={c?.token_set ? `${c.token_set.charAt(0).toUpperCase()}${c.token_set.slice(1)}. Déjalo vacío para conservarlo.` : "Moodle › Administración › Servidor › Servicios web › Gestionar tokens. Se guarda cifrado."}>
              <input className="input" type="password" autoComplete="off" value={f.token} disabled={readOnly} placeholder={c?.token_set ?? ""} onChange={(e) => setF({ ...f, token: e.target.value })} />
            </FormField>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <SwitchRow label="Enviar matrículas" hint="Matricula en Moodle a los participantes del curso (busca o crea a la persona por su correo)."
              checked={f.sync_enrollments} onChange={(v) => setF({ ...f, sync_enrollments: v })} />
            <SwitchRow label="Traer notas" hint="Copia la nota final del curso en Moodle a la matrícula en EscudoSST." checked={f.sync_grades} onChange={(v) => setF({ ...f, sync_grades: v })} />
          </div>
          <SwitchRow label="Conexión activa" hint="Desactívala para dejar de sincronizar sin borrar los datos." checked={f.enabled} onChange={(v) => setF({ ...f, enabled: v })} />
          <p className="text-xs text-muted">
            El servicio web necesita estas funciones: core_webservice_get_site_info, core_user_get_users_by_field, core_user_create_users,
            enrol_manual_enrol_users y gradereport_user_get_grade_items. A Moodle solo se envían nombre y correo; nunca datos médicos.
          </p>
          {!readOnly && (
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" icon="check" loading={busy === "save"} onClick={save}>Guardar</Button>
              <Button variant="secondary" loading={busy === "test"} disabled={!c || !!busy} onClick={test}>Probar conexión</Button>
            </div>
          )}
          {msg && <p className={`rounded-lg px-3 py-2 text-sm ${msg.ok ? "bg-ok-soft text-ok" : "bg-bad-soft text-bad"}`}>{msg.text}</p>}

          {c && (
            <div className="space-y-2">
              <h3 className="font-semibold">Cursos enlazados</h3>
              <p className="text-xs text-muted">Escribe el id del curso en Moodle (aparece en su dirección: course/view.php?id=…). Vacío = sin enlazar.</p>
              <div className="divide-y divide-line rounded-xl border border-line">
                {data.courses.length === 0 && <p className="p-3 text-sm text-muted">Aún no hay cursos en el aula.</p>}
                {data.courses.map((x) => (
                  <div key={x.id} className="flex flex-wrap items-center gap-2 p-3 text-sm">
                    <span className="min-w-0 flex-1">
                      <b className="font-mono text-xs">{x.code}</b> {x.title}
                      <span className="block text-xs text-muted">{x.enrolled} matriculado(s) · {x.synced} enviado(s) a Moodle</span>
                    </span>
                    <input className="input w-24" inputMode="numeric" aria-label={`Id en Moodle de ${x.code}`} value={links[x.id] ?? ""} disabled={readOnly}
                      onChange={(e) => setLinks({ ...links, [x.id]: e.target.value })} />
                    {!readOnly && <Button loading={busy === `link:${x.id}`} disabled={!!busy || (links[x.id] ?? "") === (x.external_course_id ?? "")} onClick={() => link(x)}>Enlazar</Button>}
                    {x.external_course_id && canSync && (
                      <>
                        <Button loading={busy === `push:${x.id}`} disabled={!!busy || !c.sync_enrollments} onClick={() => push(x)}>Enviar matrículas</Button>
                        <Button loading={busy === `pull:${x.id}`} disabled={!!busy || !c.sync_grades} onClick={() => pull(x)}>Traer notas</Button>
                      </>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
