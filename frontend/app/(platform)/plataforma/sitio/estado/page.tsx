"use client";

// Sitio web › Estado del servicio (SCR-WEB-49): componentes con su estado actual e incidentes (crear, publicar,
// agregar actualizaciones hasta resolver). Soporte registra; solo el Dueño publica y cambia componentes.
// Publicar o actualizar un incidente publicado avisa por correo a quienes pidieron avisos.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { type ComponentStatus, STATUS_TONE } from "@/lib/trust";

type Comp = { id: string; name: string; code: string; description: string | null; current_status: ComponentStatus; sort_order: number; is_visible: boolean; updated_at: string };
type Incident = {
  id: string;
  title: string;
  impact: string;
  status: string;
  started_at: string;
  resolved_at: string | null;
  component_ids_json: string[];
  published_at: string | null;
  updates: { status: string; message: string; published_at: string }[];
};
type Labels = { status: Record<string, string>; impact: Record<string, string>; incident: Record<string, string> };

const when = (d: string) => new Date(d).toLocaleString("es-PE", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "America/Lima" });
const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.");

export default function EstadoPanelPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [data, setData] = useState<{ components: Comp[]; incidents: Incident[]; status_subscribers: number } | null>(null);
  const [labels, setLabels] = useState<Labels | null>(null);
  const [editComp, setEditComp] = useState<Comp | null>(null);
  const [newInc, setNewInc] = useState(false);
  const [act, setAct] = useState<{ inc: Incident; kind: "publish" | "update" } | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: { components: Comp[]; incidents: Incident[]; status_subscribers: number }; labels: Labels }>("/internal/site/trust-status").then((r) => {
      setData(r.data);
      setLabels(r.labels);
    });
  }, []);
  useEffect(load, [load]);

  async function remove(i: Incident) {
    if (!window.confirm(`¿Eliminar el borrador «${i.title}»?`)) return;
    try {
      await api(`/internal/site/status-incidents/${i.id}`, { method: "DELETE" });
      setToast({ text: "Borrador eliminado.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: errText(e), tone: "bad" });
    }
  }

  const comps = data?.components ?? [];
  const incs = data?.incidents ?? [];
  const compName = (id: string) => comps.find((c) => c.id === id)?.name ?? "—";
  const worst = comps.reduce<ComponentStatus>((w, c) => {
    const order: ComponentStatus[] = ["operational", "maintenance", "degraded", "partial_outage", "major_outage"];
    return c.is_visible && order.indexOf(c.current_status) > order.indexOf(w) ? c.current_status : w;
  }, "operational");

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Estado del servicio"]]}
        icon="shield"
        title="Estado del servicio"
        description="Lo que tus clientes ven en /estado. Cuando algo falle o vayas a hacer un mantenimiento, regístralo aquí y publícalo: quienes pidieron avisos reciben un correo."
        aside={
          <div className="flex flex-wrap gap-2">
            <a href="/estado" target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-semibold hover:bg-bg">
              <Icon name="globe" size={16} /> Ver /estado
            </a>
            <Button variant="primary" icon="plus" onClick={() => setNewInc(true)} disabled={!comps.length}>
              Registrar incidente
            </Button>
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Estado general" value={labels ? labels.status[worst] : "–"} icon={STATUS_TONE[worst].icon} tone={worst === "operational" ? "ok" : worst === "maintenance" ? "brand" : "bad"} hint="El peor de los componentes" />
        <StatCard label="Abiertos" value={data ? incs.filter((i) => i.published_at && i.status !== "resolved").length : "–"} icon="bell" tone="bad" hint="Incidentes sin resolver" />
        <StatCard label="Por publicar" value={data ? incs.filter((i) => !i.published_at).length : "–"} icon="pen" tone="amber" hint="Aún no se ven" />
        <StatCard label="Avisos por correo" value={data ? data.status_subscribers : "–"} icon="mail" tone="na" hint="Confirmaron su correo" />
      </div>

      {data === null || !labels ? (
        <div className="h-48 animate-pulse rounded-2xl bg-line/40" />
      ) : (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[380px_minmax(0,1fr)]">
          <section className="rounded-2xl border border-line bg-surface shadow-sm xl:self-start">
            <p className="border-b border-line px-4 py-3 text-sm font-semibold">Componentes</p>
            <ul className="divide-y divide-line">
              {comps.map((c) => {
                const t = STATUS_TONE[c.current_status];
                return (
                  <li key={c.id} className="flex items-center gap-2 px-4 py-3">
                    <span className="min-w-0 flex-1">
                      <b className="block truncate text-sm">{c.name}</b>
                      {!c.is_visible && <span className="text-xs text-muted">Oculto en /estado</span>}
                    </span>
                    <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold ${t.soft} ${t.text}`}>
                      <span className={`h-2 w-2 rounded-full ${t.dot}`} />
                      {labels.status[c.current_status]}
                    </span>
                    {owner && (
                      <button onClick={() => setEditComp(c)} className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-muted hover:bg-bg hover:text-brand" aria-label={`Editar ${c.name}`}>
                        <Icon name="pen" size={14} />
                      </button>
                    )}
                  </li>
                );
              })}
            </ul>
            <p className="border-t border-line px-4 py-3 text-xs text-muted">Al publicar un incidente, sus componentes cambian solos; al resolverlo vuelven a «Operativo».</p>
          </section>

          <section className="min-w-0 space-y-3">
            {incs.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-line bg-surface p-10 text-center">
                <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-ok-soft text-ok">
                  <Icon name="check" size={22} />
                </span>
                <p className="mt-3 font-semibold">Sin incidentes registrados</p>
                <p className="mt-1 text-sm text-muted">Cuando algo falle o programes un mantenimiento, usa «Registrar incidente».</p>
              </div>
            ) : (
              incs.map((i) => (
                <article key={i.id} className="rounded-2xl border border-line bg-surface p-4 shadow-sm">
                  <span className="flex flex-wrap gap-1.5">
                    {!i.published_at ? (
                      <Chip tone="amber" dot>
                        Borrador
                      </Chip>
                    ) : (
                      <Chip tone={i.status === "resolved" ? "ok" : "bad"} dot>
                        {labels.incident[i.status]}
                      </Chip>
                    )}
                    <Chip tone={i.impact === "maintenance" ? "brand" : "na"}>{labels.impact[i.impact]}</Chip>
                  </span>
                  <b className="mt-1.5 block font-display text-[15px]">{i.title}</b>
                  <p className="text-xs text-muted">
                    {when(i.started_at)}
                    {i.resolved_at && ` → ${when(i.resolved_at)}`} · {i.component_ids_json.map(compName).join(", ")}
                  </p>
                  {i.updates.length > 0 && (
                    <ol className="mt-3 space-y-2 border-l-2 border-line pl-3">
                      {i.updates.map((u, k) => (
                        <li key={k} className="text-sm">
                          <b>{labels.incident[u.status]}</b> <span className="text-xs text-muted">· {when(u.published_at)}</span>
                          <p className="text-muted">{u.message}</p>
                        </li>
                      ))}
                    </ol>
                  )}
                  {i.status !== "resolved" && (
                    <div className="mt-3 flex flex-wrap gap-1 border-t border-line pt-2">
                      {!i.published_at && owner && (
                        <Button size="sm" variant="ghost" icon="globe" onClick={() => setAct({ inc: i, kind: "publish" })}>
                          Publicar
                        </Button>
                      )}
                      {(owner || !i.published_at) && (
                        <Button size="sm" variant="ghost" icon="plus" onClick={() => setAct({ inc: i, kind: "update" })}>
                          {i.published_at ? "Agregar actualización" : "Agregar nota"}
                        </Button>
                      )}
                      {!i.published_at && (
                        <Button size="sm" variant="ghost" onClick={() => remove(i)}>
                          Eliminar
                        </Button>
                      )}
                    </div>
                  )}
                </article>
              ))
            )}
          </section>
        </div>
      )}

      {editComp && labels && (
        <CompModal
          c={editComp}
          labels={labels}
          onClose={() => setEditComp(null)}
          onDone={() => {
            setEditComp(null);
            setToast({ text: "Componente guardado.", tone: "ok" });
            load();
          }}
        />
      )}
      {newInc && labels && (
        <IncidentModal
          comps={comps}
          labels={labels}
          onClose={() => setNewInc(false)}
          onDone={() => {
            setNewInc(false);
            setToast({ text: "Incidente registrado como borrador. Publícalo para que se vea en /estado.", tone: "ok" });
            load();
          }}
        />
      )}
      {act && labels && (
        <ActModal
          a={act}
          labels={labels}
          onClose={() => setAct(null)}
          onDone={(t) => {
            setAct(null);
            setToast({ text: t, tone: "ok" });
            load();
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function CompModal({ c, labels, onClose, onDone }: { c: Comp; labels: Labels; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ name: c.name, description: c.description ?? "", current_status: c.current_status, is_visible: c.is_visible, sort_order: c.sort_order });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      await api(`/internal/site/status-components/${c.id}`, { method: "PATCH", body: { ...f, description: f.description || null } });
      onDone();
    } catch (x) {
      setError(errText(x));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Editar componente"
      icon="shield"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="comp-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="comp-form" onSubmit={save} className="space-y-4">
        <FormField label="Nombre">
          <input className="input" maxLength={80} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
        </FormField>
        <FormField label="Descripción (opcional)">
          <input className="input" maxLength={200} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} />
        </FormField>
        <FormField label="Estado actual" hint="Lo normal es cambiarlo con un incidente; úsalo aquí solo para corregir.">
          <select className="input" value={f.current_status} onChange={(e) => setF({ ...f, current_status: e.target.value as ComponentStatus })}>
            {Object.entries(labels.status).map(([k, l]) => (
              <option key={k} value={k}>
                {l}
              </option>
            ))}
          </select>
        </FormField>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="h-4 w-4 accent-[var(--color-brand)]" checked={f.is_visible} onChange={(e) => setF({ ...f, is_visible: e.target.checked })} />
          Mostrar en /estado (apágalo si aún no usas este servicio, por ejemplo la pasarela de pagos)
        </label>
        {error && <p className="text-sm font-medium text-bad">● {error}</p>}
      </form>
    </Modal>
  );
}

function IncidentModal({ comps, labels, onClose, onDone }: { comps: Comp[]; labels: Labels; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ title: "", impact: "minor", components: [] as string[], started_at: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const toggle = (id: string) => setF({ ...f, components: f.components.includes(id) ? f.components.filter((x) => x !== id) : [...f.components, id] });

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api("/internal/site/status-incidents", { method: "POST", body: { ...f, started_at: f.started_at ? new Date(f.started_at).toISOString() : null } });
      onDone();
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { title: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Registrar incidente"
      icon="bell"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="inc-form" icon="check" loading={saving}>
            Guardar borrador
          </Button>
        </>
      }
    >
      <form id="inc-form" onSubmit={save} className="space-y-4" noValidate>
        <FormField label="Título" error={errors.title?.[0]} hint="Corto y claro. Ej.: «Lentitud al guardar registros».">
          <input className="input" maxLength={160} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} autoFocus />
        </FormField>
        <FormField label="Impacto" error={errors.impact?.[0]}>
          <select className="input" value={f.impact} onChange={(e) => setF({ ...f, impact: e.target.value })}>
            {Object.entries(labels.impact).map(([k, l]) => (
              <option key={k} value={k}>
                {l}
              </option>
            ))}
          </select>
        </FormField>
        <div>
          <p className="text-sm font-semibold">¿Qué se ve afectado?</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {comps.map((c) => (
              <button
                key={c.id}
                type="button"
                onClick={() => toggle(c.id)}
                aria-pressed={f.components.includes(c.id)}
                className={`rounded-full border px-3 py-1.5 text-sm font-semibold ${f.components.includes(c.id) ? "border-brand bg-brand-soft text-brand-strong" : "border-line text-muted"}`}
              >
                {f.components.includes(c.id) && "✓ "}
                {c.name}
              </button>
            ))}
          </div>
          {errors.components && <p className="mt-1 text-xs font-medium text-bad">● {errors.components[0]}</p>}
        </div>
        <FormField label="Inicio (opcional)" error={errors.started_at?.[0]} hint={f.impact === "maintenance" ? "Para un mantenimiento futuro, pon la fecha y hora en que empieza." : "Si lo dejas vacío, es ahora."}>
          <input className="input" type="datetime-local" value={f.started_at} onChange={(e) => setF({ ...f, started_at: e.target.value })} />
        </FormField>
      </form>
    </Modal>
  );
}

function ActModal({ a, labels, onClose, onDone }: { a: { inc: Incident; kind: "publish" | "update" }; labels: Labels; onClose: () => void; onDone: (t: string) => void }) {
  const publish = a.kind === "publish";
  const [status, setStatus] = useState(a.inc.status === "investigating" && !publish ? "identified" : a.inc.status);
  const [message, setMessage] = useState("");
  const [compStatus, setCompStatus] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const r = await api<{ data: { notified: number } }>(`/internal/site/status-incidents/${a.inc.id}/${publish ? "publish" : "updates"}`, {
        method: "POST",
        body: { message, status: publish ? undefined : status, component_status: compStatus || null },
      });
      const n = r.data.notified;
      const avisos = n ? ` y avisado a ${n} persona${n === 1 ? "" : "s"}` : "";
      onDone(publish ? `Publicado en /estado${avisos}.` : a.inc.published_at ? `Actualización publicada${avisos}.` : "Nota agregada.");
    } catch (x) {
      setError(errText(x));
      setSaving(false);
    }
  }

  return (
    <Modal
      title={publish ? "Publicar incidente" : a.inc.published_at ? "Agregar actualización" : "Agregar nota"}
      subtitle={a.inc.title}
      icon={publish ? "globe" : "plus"}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="act-form" icon="check" loading={saving}>
            {publish ? "Publicar y avisar" : "Guardar"}
          </Button>
        </>
      }
    >
      <form id="act-form" onSubmit={save} className="space-y-4">
        {!publish && (
          <FormField label="Estado del incidente">
            <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
              {Object.entries(labels.incident).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
        )}
        <FormField label="Mensaje para los clientes" hint="Qué pasa, a quién afecta y cuándo esperas resolverlo. Sin datos de clientes.">
          <textarea className="input min-h-24" maxLength={2000} value={message} onChange={(e) => setMessage(e.target.value)} autoFocus />
        </FormField>
        {status !== "resolved" && (
          <FormField label="Estado de los componentes afectados (opcional)" hint={publish ? "Si lo dejas vacío, se elige según el impacto." : "Déjalo vacío para no cambiarlo."}>
            <select className="input" value={compStatus} onChange={(e) => setCompStatus(e.target.value)}>
              <option value="">{publish ? "Según el impacto" : "Sin cambio"}</option>
              {Object.entries(labels.status).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
        )}
        {(publish || a.inc.published_at) && <p className="text-xs text-muted">Se publica en /estado (no se puede borrar después) y se avisa por correo a quienes pidieron avisos.</p>}
        {error && <p className="text-sm font-medium text-bad">● {error}</p>}
      </form>
    </Modal>
  );
}
