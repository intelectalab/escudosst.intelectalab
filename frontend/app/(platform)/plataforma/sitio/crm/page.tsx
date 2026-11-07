"use client";

// Sitio web › Conexión con el CRM (SCR-WEB-63/64): a dónde se envían interesados, demos, partners y suscriptores,
// con su mapeo de campos, prueba de conexión, pausa y la cola de envíos con reintentos. Solo el Dueño.
// El Libro de Reclamaciones nunca se envía. El secreto vive en el servidor y nunca se muestra.

import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Conn = { id: string; provider: "kallpa" | "generic_webhook" | "fidelia"; name: string; base_url: string | null; status: "active" | "paused" | "error"; secret_ref: string | null; secret_ready: boolean; entities: string[]; mapping: Record<string, Record<string, string>>; last_sync_at: string | null; last_error: string | null };
type Ev = { id: string; entity_type: string; entity_id: string; action: string; status: "pending" | "sent" | "failed" | "skipped"; attempts: number; next_attempt_at: string | null; external_id: string | null; response_code: number | null; last_error: string | null; created_at: string };
type Data = { connection: Conn; events: Ev[]; counts: Record<string, number> };

const ST = { pending: ["Pendiente", "amber"], sent: ["Enviado", "ok"], failed: ["Falló", "bad"], skipped: ["Descartado", "na"] } as const;
const CONN = { active: ["Activa", "ok"], paused: ["Pausada", "na"], error: ["Con error", "bad"] } as const;
const when = (d: string) => new Date(d).toLocaleString("es-PE", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "America/Lima" });
const errText = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.message) : "No se pudo.");

export default function CrmPage() {
  const me = usePlatformUser();
  const [data, setData] = useState<Data | null>(null);
  const [meta, setMeta] = useState<{ entities: Record<string, string>; fields: Record<string, string[]>; max: number } | null>(null);
  const [filter, setFilter] = useState<string>("");
  const [sel, setSel] = useState<string[]>([]);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const [denied, setDenied] = useState(false);

  const load = useCallback(() => {
    api<{ data: Data; entities: Record<string, string>; fields: Record<string, string[]>; max_attempts: number }>(`/internal/site/crm${filter ? `?status=${filter}` : ""}`)
      .then((r) => {
        setData(r.data);
        setMeta({ entities: r.entities, fields: r.fields, max: r.max_attempts });
        setSel([]);
      })
      .catch((e) => e instanceof ApiError && e.status === 403 && setDenied(true));
  }, [filter]);
  useEffect(load, [load]);

  async function run(path: string, body: object, ok: (r: { data: Record<string, unknown> }) => string) {
    try {
      const r = await api<{ data: Record<string, unknown> }>(`/internal/site/crm/${path}`, { method: "POST", body });
      setToast({ text: ok(r), tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: errText(e), tone: "bad" });
    }
  }

  if (me.platform_role === "support" || denied) {
    return (
      <div className="w-full space-y-5">
        <PageHeader crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Conexión con el CRM"]]} icon="refresh" title="Conexión con el CRM" description="Solo el Dueño de la plataforma gestiona la conexión con el CRM." />
      </div>
    );
  }

  const c = data?.connection;
  const counts = data?.counts ?? {};
  const failed = (data?.events ?? []).filter((e) => e.status === "failed");

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Conexión con el CRM"]]}
        icon="refresh"
        title="Conexión con el CRM"
        description="Envía automáticamente a tu CRM los interesados, solicitudes y reuniones de demo, partners y suscriptores, para seguir las ventas allá. El Libro de Reclamaciones nunca se envía."
        aside={c && <Chip tone={CONN[c.status][1]} dot>{CONN[c.status][0]}</Chip>}
      />

      <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Enviados" value={data ? (counts.sent ?? 0) : "–"} icon="check" tone="ok" hint={c?.last_sync_at ? `Último: ${when(c.last_sync_at)}` : "Aún ninguno"} active={filter === "sent"} onClick={() => setFilter(filter === "sent" ? "" : "sent")} />
        <StatCard label="Pendientes" value={data ? (counts.pending ?? 0) : "–"} icon="clock" tone="amber" hint="Salen solos cada minuto" active={filter === "pending"} onClick={() => setFilter(filter === "pending" ? "" : "pending")} />
        <StatCard label="Fallidos" value={data ? (counts.failed ?? 0) : "–"} icon="bell" tone="bad" hint={meta ? `Se reintentan hasta ${meta.max} veces` : ""} active={filter === "failed"} onClick={() => setFilter(filter === "failed" ? "" : "failed")} />
        <StatCard label="Descartados" value={data ? (counts.skipped ?? 0) : "–"} icon="close" tone="na" hint="No se enviarán" active={filter === "skipped"} onClick={() => setFilter(filter === "skipped" ? "" : "skipped")} />
      </div>

      {!data || !meta || !c ? (
        <div className="h-64 animate-pulse rounded-2xl bg-line/40" />
      ) : (
        <div className="grid grid-cols-1 gap-5 2xl:grid-cols-[480px_minmax(0,1fr)]">
          <ConnForm c={c} meta={meta} onSaved={(t) => (setToast({ text: t, tone: "ok" }), load())} onError={(t) => setToast({ text: t, tone: "bad" })} run={run} />

          <section className="min-w-0 rounded-2xl border border-line bg-surface shadow-sm 2xl:self-start">
            <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
              <p className="flex-1 text-sm font-semibold">Cola de envíos {filter && `· ${ST[filter as keyof typeof ST][0]}`}</p>
              {failed.length > 0 && (
                <Button size="sm" variant="soft" icon="refresh" onClick={() => run("events/retry", { ids: sel.length ? sel : failed.map((e) => e.id) }, (r) => `${r.data.count} reintentado(s).`)}>
                  Reintentar {sel.length ? `${sel.length} elegidos` : "fallidos"}
                </Button>
              )}
              {sel.length > 0 && (
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    const reason = window.prompt("¿Por qué los descartas?");
                    if (reason) run("events/skip", { ids: sel, reason }, (r) => `${r.data.count} descartado(s).`);
                  }}
                >
                  Descartar
                </Button>
              )}
            </div>
            {data.events.length === 0 ? (
              <p className="p-6 text-sm text-muted">{c.status === "active" ? "Aún no hay envíos. Se crean solos cuando llega un interesado, una demo o un suscriptor." : "La conexión está pausada: no se encola nada hasta activarla."}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-sm">
                  <thead className="text-left text-xs text-muted">
                    <tr>
                      <th className="w-8 px-3 py-2" />
                      <th className="px-2 py-2 font-semibold">Qué</th>
                      <th className="px-2 py-2 font-semibold">Estado</th>
                      <th className="px-2 py-2 font-semibold">Intentos</th>
                      <th className="px-2 py-2 font-semibold">Detalle</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {data.events.map((e) => (
                      <tr key={e.id} className="align-top">
                        <td className="px-3 py-2">
                          {(e.status === "failed" || e.status === "pending") && (
                            <input type="checkbox" checked={sel.includes(e.id)} onChange={(x) => setSel(x.target.checked ? [...sel, e.id] : sel.filter((i) => i !== e.id))} aria-label="Elegir" />
                          )}
                        </td>
                        <td className="px-2 py-2">
                          <b className="block">{meta.entities[e.entity_type]}</b>
                          <span className="text-xs text-muted">
                            {e.action === "create" ? "Nuevo" : "Cambio"} · {when(e.created_at)}
                          </span>
                        </td>
                        <td className="px-2 py-2">
                          <Chip tone={ST[e.status][1]} dot>
                            {ST[e.status][0]}
                          </Chip>
                        </td>
                        <td className="px-2 py-2 tabular-nums">{e.attempts}</td>
                        <td className="max-w-[280px] px-2 py-2 text-xs text-muted">
                          {e.external_id && <span className="block">Id en el CRM: {e.external_id}</span>}
                          {e.response_code && <span className="block">Respuesta: {e.response_code}</span>}
                          {e.status === "failed" && e.next_attempt_at && e.attempts < meta.max && <span className="block">Próximo intento: {when(e.next_attempt_at)}</span>}
                          {e.last_error && <span className="block break-words text-bad">{e.last_error}</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function ConnForm({
  c,
  meta,
  onSaved,
  onError,
  run,
}: {
  c: Conn;
  meta: { entities: Record<string, string>; fields: Record<string, string[]> };
  onSaved: (t: string) => void;
  onError: (t: string) => void;
  run: (path: string, body: object, ok: (r: { data: Record<string, unknown> }) => string) => Promise<void>;
}) {
  const [f, setF] = useState({ provider: c.provider, name: c.name, base_url: c.base_url ?? "", secret_ref: "", entities: c.entities });
  const [mapping, setMapping] = useState<Record<string, Record<string, string>>>(c.mapping ?? {});
  const [openMap, setOpenMap] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api("/internal/site/crm", { method: "PATCH", body: { ...f, base_url: f.base_url || null, secret_ref: f.secret_ref || null, mapping } });
      onSaved("Conexión guardada.");
    } catch (x) {
      if (x instanceof ApiError && x.errors) setErrors(x.errors);
      else onError(errText(x));
    }
    setSaving(false);
  }

  const toggle = (k: string) => setF({ ...f, entities: f.entities.includes(k) ? f.entities.filter((x) => x !== k) : [...f.entities, k] });
  return (
    <form onSubmit={save} className="min-w-0 space-y-4 rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5 2xl:self-start">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label="Tipo" error={errors.provider?.[0]}>
          <select className="input" value={f.provider} onChange={(e) => setF({ ...f, provider: e.target.value as Conn["provider"] })}>
            <option value="fidelia">Fidelia (CRM de Intelectalab)</option>
            <option value="kallpa">Kallpa CRM</option>
            <option value="generic_webhook">Webhook genérico (cualquier CRM)</option>
          </select>
        </FormField>
        <FormField label="Nombre" error={errors.name?.[0]}>
          <input className="input" maxLength={80} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
        </FormField>
      </div>
      {f.provider === "fidelia" && (
        <div className="rounded-xl border border-brand/20 bg-brand-soft/50 p-3 text-xs">
          <p className="font-semibold text-brand-strong">Cómo conectar Fidelia</p>
          <ol className="mt-1 list-decimal space-y-0.5 pl-4 text-muted">
            <li>En Fidelia: Configuración › API e integraciones › crear una llave con permiso de escritura (empieza con fcrm_). Tu plan de Fidelia debe incluir la API.</li>
            <li>Copia la llave en el archivo .env del servidor de EscudoSST, por ejemplo FIDELIA_API_KEY=fcrm_…</li>
            <li>Aquí: dirección https://crm.intelectalab.com/api/v1/ext/leads y secreto env://FIDELIA_API_KEY.</li>
          </ol>
          <p className="mt-1 text-muted">Fidelia recibe cada interesado nuevo como lead, con su origen y datos en una nota. No recibe cambios posteriores: esos se marcan como descartados.</p>
        </div>
      )}
      <FormField label="Dirección (URL) a la que se envía" error={errors.base_url?.[0]} hint={f.provider === "fidelia" ? "https://crm.intelectalab.com/api/v1/ext/leads" : "Debe empezar con https://. Te la da tu CRM."}>
        <input className="input font-mono text-sm" maxLength={300} placeholder="https://" value={f.base_url} onChange={(e) => setF({ ...f, base_url: e.target.value })} />
      </FormField>
      <FormField
        label="Secreto"
        error={errors.secret_ref?.[0]}
        hint={`El secreto real se guarda en el servidor como variable de entorno, nunca aquí. Escribe solo su referencia, por ejemplo env://CRM_WEBHOOK_SECRET. ${c.secret_ref ? `Actual: ${c.secret_ref} (${c.secret_ready ? "cargado" : "vacío en el servidor"}).` : "Aún no hay."}`}
      >
        <input className="input font-mono text-sm" placeholder={c.secret_ref ? "Déjalo vacío para no cambiarlo" : "env://CRM_WEBHOOK_SECRET"} value={f.secret_ref} onChange={(e) => setF({ ...f, secret_ref: e.target.value.trim() })} />
      </FormField>
      <div>
        <p className="text-sm font-semibold">Qué se envía</p>
        <ul className="mt-2 space-y-1.5">
          {Object.entries(meta.entities).map(([k, l]) => (
            <li key={k} className="rounded-xl border border-line">
              <div className="flex items-center gap-2 px-3 py-2">
                <input type="checkbox" checked={f.entities.includes(k)} onChange={() => toggle(k)} aria-label={l} />
                <span className="flex-1 text-sm">{l}</span>
                <button type="button" onClick={() => setOpenMap(openMap === k ? null : k)} className="text-xs font-semibold text-brand hover:underline">
                  Campos {Object.keys(mapping[k] ?? {}).length ? `(${Object.keys(mapping[k]).length} renombrados)` : ""}
                </button>
              </div>
              {openMap === k && (
                <div className="space-y-1.5 border-t border-line p-3">
                  <p className="text-xs text-muted">Si tu CRM usa otros nombres, escríbelos. Vacío = se envía con el mismo nombre.</p>
                  {meta.fields[k].map((fld) => (
                    <label key={fld} className="flex items-center gap-2 text-xs">
                      <code className="w-36 shrink-0 truncate rounded bg-bg px-1.5 py-1">{fld}</code>
                      <Icon name="chevron" size={12} className="text-muted" />
                      <input
                        className="input h-8 py-1 text-xs"
                        placeholder={fld}
                        maxLength={64}
                        value={mapping[k]?.[fld] ?? ""}
                        onChange={(e) => {
                          const v = e.target.value.replace(/[^A-Za-z0-9_.]/g, "");
                          const m = { ...(mapping[k] ?? {}) };
                          if (v) m[fld] = v;
                          else delete m[fld];
                          setMapping({ ...mapping, [k]: m });
                        }}
                      />
                    </label>
                  ))}
                </div>
              )}
            </li>
          ))}
        </ul>
        <p className="mt-2 flex items-center gap-1.5 text-xs text-muted">
          <Icon name="lock" size={12} /> El Libro de Reclamaciones y los datos médicos nunca se envían.
        </p>
      </div>
      {c.last_error && <p className="rounded-xl bg-bad-soft p-3 text-xs text-bad">Último error: {c.last_error}</p>}
      <div className="flex flex-wrap gap-2">
        <Button variant="primary" type="submit" icon="check" loading={saving}>
          Guardar
        </Button>
        <Button icon="refresh" onClick={() => run("test", {}, (r) => (r.data.ok ? "Conexión correcta: el CRM respondió." : `No respondió bien: ${r.data.error ?? ""}`))}>
          Probar conexión
        </Button>
        {c.status === "active" ? (
          <Button variant="ghost" onClick={() => run("pause", {}, () => "Conexión pausada: no se encolan nuevos envíos.")}>
            Pausar
          </Button>
        ) : (
          <Button variant="ghost" icon="play" onClick={() => run("activate", {}, () => "Conexión activa: los nuevos registros se enviarán solos.")}>
            Activar
          </Button>
        )}
      </div>
      <details className="rounded-xl bg-bg p-3 text-xs text-muted">
        <summary className="cursor-pointer font-semibold text-fg">¿Cómo recibe los datos mi CRM? (para tu programador)</summary>
        <p className="mt-2">
          Cada envío es un POST con JSON: <code>event</code> (por ejemplo <code>marketing_lead.create</code>), <code>id</code>, <code>idempotency_key</code> y <code>data</code>. En el webhook genérico va la cabecera <code>X-Escudo-Signature: t=…,v1=…</code> (HMAC-SHA256 del texto «t.cuerpo» con el secreto). Con Kallpa va <code>Authorization: Bearer</code>. Si el CRM no responde, se reintenta con espera creciente.
        </p>
      </details>
    </form>
  );
}
