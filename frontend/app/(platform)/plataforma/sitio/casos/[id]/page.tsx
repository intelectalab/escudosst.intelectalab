"use client";

// Editor de un caso de éxito (SCR-WEB-44). «nuevo» crea uno. Aprobar exige la autorización escrita del cliente o la
// marca «Ejemplo»; retirar la autorización lo oculta al momento (RN-WEB-41). La autorización solo se descarga aquí.

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField, FormSection, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import { assetUrl, type PublicModule, STORY_SECTORS, type StoryResult } from "@/lib/site";
import { STORY_STATUS, type StoryStatus } from "@/lib/stories";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Story = {
  id?: string;
  slug: string;
  title: string;
  client_name: string;
  sector: string;
  project_year: number | null;
  challenge: string;
  solution: string;
  results: StoryResult[];
  quote: string | null;
  modules: string[];
  is_example: boolean;
  status: StoryStatus;
  logo_file_id: string | null;
  has_consent: boolean;
  consent_name: string | null;
  approved_by_name?: string | null;
  approved_at: string | null;
};

type Consent = {
  id: string;
  company_name: string;
  company_ruc: string | null;
  signer_name: string;
  signer_role: string;
  signer_doc_type: string;
  signer_doc_masked: string | null;
  signer_email: string;
  authorization_version: string;
  text_sha256: string;
  accepted_at: string;
  withdrawn_at: string | null;
  withdraw_reason: string | null;
};

const EMPTY: Story = {
  slug: "",
  title: "",
  client_name: "",
  sector: "construction",
  project_year: new Date().getFullYear(),
  challenge: "",
  solution: "",
  results: [{ value: "", label: "" }],
  quote: "",
  modules: [],
  is_example: false,
  status: "draft",
  logo_file_id: null,
  has_consent: false,
  consent_name: null,
  approved_at: null,
};

export default function EditStoryPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const isNew = id === "nuevo";
  const [s, setS] = useState<Story | null>(isNew ? EMPTY : null);
  const [modules, setModules] = useState<PublicModule[]>([]);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const [consents, setConsents] = useState<Consent[]>([]);

  const load = useCallback(() => {
    if (isNew) return;
    api<{ data: Story }>(`/internal/site/success-stories/${id}`).then((r) => setS({ ...r.data, results: r.data.results.length ? r.data.results : [{ value: "", label: "" }] }));
    api<{ data: Consent[] }>(`/internal/site/success-stories/${id}/consents`)
      .then((r) => setConsents(r.data))
      .catch(() => undefined);
  }, [id, isNew]);
  useEffect(() => {
    load();
    fetch(`${API_URL}/api/v1/public/modules`)
      .then((r) => r.json())
      .then((j) => setModules(j.data ?? []))
      .catch(() => undefined);
  }, [load]);

  if (!s) return <p className="text-sm text-muted">Cargando…</p>;

  const set = <K extends keyof Story>(k: K, v: Story[K]) => {
    setS((x) => (x ? { ...x, [k]: v } : x));
    setDirty(true);
  };
  const err = (k: string) => errors[k]?.[0];
  const backed = s.has_consent || s.is_example;

  async function save(): Promise<boolean> {
    if (!s) return false;
    setBusy("save");
    setErrors({});
    const body = {
      client_name: s.client_name,
      title: s.title,
      slug: s.slug || null,
      sector: s.sector,
      project_year: s.project_year || null,
      challenge: s.challenge,
      solution: s.solution,
      results: s.results.filter((r) => r.value.trim() || r.label.trim()),
      quote: s.quote,
      modules: s.modules,
      is_example: s.is_example,
    };
    try {
      if (isNew) {
        const r = await api<{ data: { id: string } }>("/internal/site/success-stories", { method: "POST", body });
        router.replace(`/plataforma/sitio/casos/${r.data.id}`);
      } else {
        await api(`/internal/site/success-stories/${id}`, { method: "PATCH", body });
        setDirty(false);
        setToast({ text: s.status === "published" ? "Guardado. Ya se ve en el sitio." : "Guardado.", tone: "ok" });
        load();
      }
      return true;
    } catch (e) {
      if (e instanceof ApiError) setErrors(e.errors ?? {});
      setToast({ text: e instanceof ApiError && e.errors ? "Revisa los campos marcados." : "No se pudo guardar.", tone: "bad" });
      return false;
    } finally {
      setBusy(null);
    }
  }

  async function act(action: "approve" | "publish" | "hide" | "withdraw_consent") {
    if (action === "withdraw_consent" && !window.confirm("¿El cliente retiró su autorización? El caso se ocultará del sitio al momento y tendrás que volver a aprobarlo.")) return;
    if (dirty && !(await save())) return;
    setBusy(action);
    try {
      await api(`/internal/site/success-stories/${id}/actions`, { method: "POST", body: { action } });
      const msg = { approve: "Aprobado. Ya puedes publicarlo.", publish: "Publicado: ya se ve en /casos.", hide: "Oculto: ya no se ve en el sitio.", withdraw_consent: "Autorización retirada. El caso quedó oculto." };
      setToast({ text: msg[action], tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? (e.errors?.action?.[0] ?? e.detail ?? e.message) : "No se pudo.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  async function upload(kind: "logo" | "consent", file: File) {
    setBusy(kind);
    const fd = new FormData();
    fd.append("file", file);
    try {
      const res = await fetch(`${API_URL}/api/v1/internal/site/success-stories/${id}/${kind}`, {
        method: "POST",
        headers: { Accept: "application/json", Authorization: `Bearer ${session.getToken()}` },
        body: fd,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json.errors?.file?.[0] ?? json.detail ?? "No se pudo subir el archivo.");
      setToast({ text: kind === "logo" ? "Logo cargado." : "Autorización adjunta. Ya puedes aprobar el caso.", tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof Error ? e.message : "No se pudo subir el archivo.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  async function downloadConsent() {
    setBusy("download");
    try {
      const res = await fetch(`${API_URL}/api/v1/internal/site/success-stories/${id}/consent`, { headers: { Authorization: `Bearer ${session.getToken()}` } });
      if (!res.ok) throw new Error();
      const url = URL.createObjectURL(await res.blob());
      const a = document.createElement("a");
      a.href = url;
      a.download = s?.consent_name || "autorizacion";
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      setToast({ text: "No se pudo descargar.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  async function downloadEvidence(c: Consent) {
    setBusy("ev" + c.id);
    try {
      const res = await fetch(`${API_URL}/api/v1/internal/site/success-stories/${id}/consents/${c.id}/file`, { headers: { Authorization: `Bearer ${session.getToken()}` } });
      if (!res.ok) throw new Error();
      const url = URL.createObjectURL(await res.blob());
      window.open(url, "_blank", "noopener");
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch {
      setToast({ text: "No se pudo abrir la constancia.", tone: "bad" });
    } finally {
      setBusy(null);
    }
  }

  async function remove() {
    if (!window.confirm("¿Eliminar este caso? Dejará de verse en el sitio y saldrá de la lista.")) return;
    await api(`/internal/site/success-stories/${id}`, { method: "DELETE" });
    router.push("/plataforma/sitio/casos");
  }

  const setResult = (i: number, k: keyof StoryResult, v: string) => set("results", s.results.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  const toggleModule = (code: string) => {
    setS((x) => (x ? { ...x, modules: x.modules.includes(code) ? x.modules.filter((c) => c !== code) : [...x.modules, code] } : x));
    setDirty(true);
  };

  return (
    <div className="w-full space-y-5">
      <PageHeader crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Casos de éxito", "/plataforma/sitio/casos"], [isNew ? "Nuevo" : s.client_name]]} icon="star" title={isNew ? "Nuevo caso" : s.client_name} description={isNew ? "Nuevo caso de éxito" : `Caso de éxito · /casos/${s.slug}`} />

      <div className="sticky top-0 z-20 -mx-1 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface/95 p-3 shadow-sm backdrop-blur">
        <div className="flex flex-wrap items-center gap-2">
          <Chip tone={STORY_STATUS[s.status][1]} dot>
            {STORY_STATUS[s.status][0]}
          </Chip>
          {s.is_example && <Chip tone="amber">Ejemplo</Chip>}
          {dirty && <Chip tone="amber">Sin guardar</Chip>}
        </div>
        <div className="flex flex-wrap gap-2">
          {s.status === "published" && (
            <a href={`/casos/${s.slug}`} target="_blank" rel="noopener" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-3 text-sm font-semibold hover:border-brand">
              <Icon name="globe" size={15} /> Ver en el sitio
            </a>
          )}
          {(s.status === "approved" || s.status === "published") && (
            <Button variant="ghost" loading={busy === "hide"} onClick={() => act("hide")}>
              Ocultar del sitio
            </Button>
          )}
          <Button variant="secondary" icon="check" loading={busy === "save"} disabled={!dirty && !isNew} onClick={save}>
            {isNew ? "Crear caso" : "Guardar"}
          </Button>
          {!isNew && (s.status === "draft" || (s.status === "hidden" && !s.approved_at)) && (
            <Button variant="primary" icon="check" loading={busy === "approve"} disabled={!backed} title={backed ? undefined : "Adjunta la autorización o márcalo como «Ejemplo»"} onClick={() => act("approve")}>
              Aprobar
            </Button>
          )}
          {!isNew && (s.status === "approved" || (s.status === "hidden" && !!s.approved_at)) && (
            <Button variant="primary" icon="globe" loading={busy === "publish"} onClick={() => act("publish")}>
              Publicar
            </Button>
          )}
        </div>
      </div>

      {!isNew && !backed && (
        <p className="flex items-start gap-2 rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">
          <Icon name="lock" size={16} className="mt-0.5 shrink-0" />
          Para aprobarlo falta la autorización escrita del cliente (abajo, en «Autorización del cliente»). Si es un caso de muestra, márcalo como «Ejemplo».
        </p>
      )}

      <div className="grid grid-cols-1 gap-5 2xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="space-y-5">
          <FormSection title="Cliente" icon="building" description="Cómo aparece en la tarjeta del listado.">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField label="Nombre del cliente" error={err("client_name")}>
                <input className="input" maxLength={120} value={s.client_name} onChange={(e) => set("client_name", e.target.value)} placeholder="Constructora Andina SAC" />
              </FormField>
              <FormField label="Sector" error={err("sector")}>
                <select className="input" value={s.sector} onChange={(e) => set("sector", e.target.value)}>
                  {Object.entries(STORY_SECTORS).map(([k, l]) => (
                    <option key={k} value={k}>
                      {l}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField label="Título del caso" error={err("title")} counter={`${s.title.length}/140`}>
                <input className="input" maxLength={140} value={s.title} onChange={(e) => set("title", e.target.value)} placeholder="Ordenó su SST en tres obras a la vez" />
              </FormField>
              <FormField label="Año del proyecto" error={err("project_year")}>
                <input className="input" type="number" min={2000} max={new Date().getFullYear()} value={s.project_year ?? ""} onChange={(e) => set("project_year", e.target.value ? Number(e.target.value) : null)} />
              </FormField>
            </div>
            <FormField label="Dirección" error={err("slug")} hint="Si la dejas vacía, se arma con el nombre del cliente.">
              <span className="flex items-center gap-1 font-mono text-sm">
                <span className="text-muted">/casos/</span>
                <input
                  className="input font-mono"
                  maxLength={80}
                  value={s.slug}
                  onChange={(e) =>
                    set(
                      "slug",
                      e.target.value
                        .toLowerCase()
                        .normalize("NFD")
                        .replace(/[̀-ͯ]/g, "")
                        .replace(/[^a-z0-9]+/g, "-"),
                    )
                  }
                />
              </span>
            </FormField>
          </FormSection>

          <FormSection title="La historia" icon="pen" description="Corta y concreta: qué problema tenía y qué cambió.">
            <FormField label="Antes: el problema que tenía (el «reto»)" error={err("challenge")} counter={`${s.challenge.length}/1500`}>
              <textarea className="input min-h-28" maxLength={1500} value={s.challenge} onChange={(e) => set("challenge", e.target.value)} placeholder="Llevaba los registros en Excel y papel en cada obra…" />
            </FormField>
            <FormField label="Después: cómo lo resolvió con EscudoSST" error={err("solution")} counter={`${s.solution.length}/1500`}>
              <textarea className="input min-h-28" maxLength={1500} value={s.solution} onChange={(e) => set("solution", e.target.value)} placeholder="Centralizó el IPERC, los ATS y las charlas en EscudoSST…" />
            </FormField>
            <FormField label="Cita del cliente (opcional)" error={err("quote")} counter={`${(s.quote ?? "").length}/400`}>
              <textarea className="input min-h-20" maxLength={400} value={s.quote ?? ""} onChange={(e) => set("quote", e.target.value)} />
            </FormField>
          </FormSection>

          <FormSection title="Resultados" icon="star" description="Hasta 4 cifras. Deben estar respaldadas por el cliente.">
            <ul className="space-y-2">
              {s.results.map((r, i) => (
                <li key={i} className="flex items-start gap-2">
                  <input className="input w-28 shrink-0 font-semibold" maxLength={20} placeholder="-40%" aria-label={`Cifra ${i + 1}`} value={r.value} onChange={(e) => setResult(i, "value", e.target.value)} />
                  <input className="input" maxLength={80} placeholder="accidentes en el primer año" aria-label={`Qué mide la cifra ${i + 1}`} value={r.label} onChange={(e) => setResult(i, "label", e.target.value)} />
                  <button type="button" aria-label="Quitar resultado" onClick={() => set("results", s.results.filter((_, j) => j !== i))} className="grid h-11 w-11 shrink-0 place-items-center rounded-lg text-muted hover:bg-bad-soft hover:text-bad">
                    <Icon name="close" size={16} />
                  </button>
                </li>
              ))}
            </ul>
            {(err("results") || Object.keys(errors).some((k) => k.startsWith("results."))) && <p className="text-xs font-medium text-bad">● {err("results") ?? "Completa la cifra y qué mide en cada fila."}</p>}
            {s.results.length < 4 && (
              <Button size="sm" variant="soft" icon="plus" onClick={() => set("results", [...s.results, { value: "", label: "" }])}>
                Agregar resultado
              </Button>
            )}
          </FormSection>

          <FormSection title="Módulos que usó" icon="grid" description="Se muestran al final de la ficha.">
            <div className="flex flex-wrap gap-2">
              {modules.map((m) => {
                const on = s.modules.includes(m.code.trim());
                return (
                  <button
                    key={m.code}
                    type="button"
                    aria-pressed={on}
                    onClick={() => toggleModule(m.code.trim())}
                    className={`rounded-full border px-3 py-1.5 text-sm font-semibold transition-colors ${on ? "border-brand bg-brand text-white" : "border-line hover:border-brand/50"}`}
                  >
                    {m.name}
                  </button>
                );
              })}
            </div>
            {err("modules") && <p className="text-xs font-medium text-bad">● {err("modules")}</p>}
          </FormSection>
        </div>

        <aside className="grid grid-cols-1 gap-5 md:grid-cols-2 2xl:sticky 2xl:top-24 2xl:block 2xl:space-y-5 2xl:self-start">
          <section className="form-section space-y-3">
            <h3 className="font-display text-[15px] font-semibold">Autorización del cliente</h3>
            <p className="text-xs text-muted">Lo normal es que el cliente la firme en línea al responder la invitación. Si la tienes en papel, súbela escaneada. Es privada: nunca sale en el sitio.</p>
            {consents.map((c) => (
              <div key={c.id} className={`space-y-2 rounded-xl border p-3 text-xs ${c.withdrawn_at ? "border-bad/30 bg-bad-soft/40" : "border-ok/30 bg-ok-soft/40"}`}>
                <p className="flex items-center gap-1.5 text-sm font-semibold">
                  <Icon name={c.withdrawn_at ? "close" : "shield"} size={15} className={c.withdrawn_at ? "text-bad" : "text-ok"} />
                  {c.withdrawn_at ? "Autorización retirada" : "Firmada en línea"}
                </p>
                <dl className="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1">
                  <dt className="text-muted">Firmó</dt>
                  <dd>
                    {c.signer_name} · {c.signer_role}
                  </dd>
                  <dt className="text-muted">Documento</dt>
                  <dd className="font-mono">
                    {c.signer_doc_type} {c.signer_doc_masked}
                  </dd>
                  <dt className="text-muted">Correo</dt>
                  <dd className="break-all">{c.signer_email}</dd>
                  <dt className="text-muted">Fecha</dt>
                  <dd>{new Date(c.accepted_at).toLocaleString("es-PE")}</dd>
                  {c.withdrawn_at && (
                    <>
                      <dt className="text-muted">Retirada</dt>
                      <dd>
                        {new Date(c.withdrawn_at).toLocaleString("es-PE")}
                        {c.withdraw_reason && ` · «${c.withdraw_reason}»`}
                      </dd>
                    </>
                  )}
                  <dt className="text-muted">Huella</dt>
                  <dd className="truncate font-mono" title={c.text_sha256}>
                    {c.text_sha256.slice(0, 16)}…
                  </dd>
                </dl>
                <Button size="sm" icon="file" loading={busy === "ev" + c.id} onClick={() => downloadEvidence(c)}>
                  Ver constancia
                </Button>
              </div>
            ))}
            {isNew ? (
              <p className="rounded-lg bg-bg px-3 py-2 text-xs text-muted">Primero crea el caso para adjuntarla.</p>
            ) : s.has_consent ? (
              <div className="space-y-2">
                {!consents.some((c) => !c.withdrawn_at) && (
                  <p className="flex items-center gap-2 rounded-lg bg-ok-soft px-3 py-2 text-sm font-semibold text-ok">
                    <Icon name="check" size={15} /> <span className="truncate">{s.consent_name ?? "Autorización adjunta"}</span>
                  </p>
                )}
                <div className="flex flex-wrap gap-2">
                  {!consents.some((c) => !c.withdrawn_at) && (
                    <Button size="sm" icon="file" loading={busy === "download"} onClick={downloadConsent}>
                      Descargar
                    </Button>
                  )}
                  <Button size="sm" variant="danger-ghost" loading={busy === "withdraw_consent"} onClick={() => act("withdraw_consent")}>
                    El cliente la retiró
                  </Button>
                </div>
              </div>
            ) : (
              <FilePick label="Adjuntar autorización (PDF o foto)" accept="application/pdf,image/png,image/jpeg" busy={busy === "consent"} onPick={(f) => upload("consent", f)} />
            )}
            <SwitchRow label="Es un ejemplo" hint="Caso de muestra, no de un cliente real. En el sitio lleva el rótulo «Ejemplo»." checked={s.is_example} onChange={(v) => set("is_example", v)} />
            {err("is_example") && <p className="text-xs font-medium text-bad">● {err("is_example")}</p>}
            {s.approved_at && (
              <p className="text-xs text-muted">
                Aprobado{s.approved_by_name ? ` por ${s.approved_by_name}` : ""} el {new Date(s.approved_at).toLocaleDateString("es-PE")}.
              </p>
            )}
          </section>

          <section className="form-section space-y-3">
            <h3 className="font-display text-[15px] font-semibold">Logo del cliente <span className="font-normal text-muted">(opcional)</span></h3>
            {isNew ? (
              <p className="rounded-lg bg-bg px-3 py-2 text-xs text-muted">Primero crea el caso para subirlo.</p>
            ) : (
              <>
                {s.logo_file_id && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={assetUrl(s.logo_file_id) ?? ""} alt="Logo actual" className="h-14 w-auto rounded-lg border border-line bg-white object-contain p-2" />
                )}
                <FilePick label={s.logo_file_id ? "Cambiar logo" : "Subir logo (PNG, JPG, WEBP)"} accept="image/png,image/jpeg,image/webp,image/avif" busy={busy === "logo"} onPick={(f) => upload("logo", f)} />
                <p className="text-xs text-muted">Opcional. Es el logo de la empresa del cliente: sale en la tarjeta y la ficha del caso en /casos. Si no hay, se muestra su inicial. Sin fotos de personas.</p>
              </>
            )}
          </section>

          {!isNew && (
            <Button variant="danger-ghost" icon="close" onClick={remove}>
              Eliminar caso
            </Button>
          )}
        </aside>
      </div>

      <Link href="/plataforma/sitio/casos" className="inline-block text-sm font-semibold text-brand hover:underline">
        ← Todos los casos
      </Link>
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function FilePick({ label, accept, busy, onPick }: { label: string; accept: string; busy: boolean; onPick: (f: File) => void }) {
  const ref = useRef<HTMLInputElement>(null);
  return (
    <>
      <input
        ref={ref}
        type="file"
        accept={accept}
        className="hidden"
        onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) onPick(f);
          e.target.value = "";
        }}
      />
      <Button size="sm" variant="soft" icon="plus" loading={busy} onClick={() => ref.current?.click()}>
        {label}
      </Button>
    </>
  );
}
