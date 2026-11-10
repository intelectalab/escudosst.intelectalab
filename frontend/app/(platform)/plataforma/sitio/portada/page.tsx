"use client";

// Sitio web › Portada: por cada sección de la portada, «Mostrar en la portada» (encender o apagar la sección entera),
// sus textos y «Volver al texto de fábrica». Avisa si hay cambios sin guardar (al salir de la página o del panel).
// Al guardar se publica: la portada lo muestra en menos de un minuto.

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { HOME_DEFAULTS, HOME_KEYS, type HomeKey, type HomeSection } from "@/lib/home";

type Form = Record<HomeKey, { visible: boolean; eyebrow: string; title: string; text: string }>;

const factory = (k: HomeKey) => ({ eyebrow: HOME_DEFAULTS[k].eyebrow ?? "", title: HOME_DEFAULTS[k].title ?? "", text: HOME_DEFAULTS[k].text ?? "" });

function toForm(saved: HomeSection[]): Form {
  return Object.fromEntries(
    HOME_KEYS.map((k) => {
      const s = saved.find((x) => x.key === k);
      const f = factory(k);
      return [k, { visible: HOME_DEFAULTS[k].fixed ? true : (s?.visible ?? true), eyebrow: s?.eyebrow || f.eyebrow, title: s?.title || f.title, text: s?.text || f.text }];
    }),
  ) as Form;
}

/** Al guardar, un texto igual al de fábrica se manda vacío: así sigue al de fábrica si este cambia. */
function toBody(form: Form) {
  return HOME_KEYS.map((k) => {
    const f = factory(k);
    const v = form[k];
    const keep = (val: string, def: string) => (val.trim() === "" || val.trim() === def ? null : val.trim());
    return { key: k, visible: v.visible, eyebrow: keep(v.eyebrow, f.eyebrow), title: keep(v.title, f.title), text: keep(v.text, f.text) };
  });
}

export default function PortadaEditorPage() {
  const router = useRouter();
  const [form, setForm] = useState<Form | null>(null);
  const [initial, setInitial] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [leaveTo, setLeaveTo] = useState<string | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: { sections: HomeSection[] } }>("/internal/site/home").then((r) => {
      const f = toForm(r.data.sections);
      setForm(f);
      setInitial(JSON.stringify(toBody(f)));
    });
  }, []);
  useEffect(load, [load]);

  const dirty = useMemo(() => !!form && JSON.stringify(toBody(form)) !== initial, [form, initial]);

  // Cambios sin guardar: aviso del navegador al cerrar o recargar, y aviso propio al ir a otra parte del panel.
  useEffect(() => {
    if (!dirty) return;
    const onUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    const onClick = (e: MouseEvent) => {
      const a = (e.target as HTMLElement | null)?.closest?.("a[href]") as HTMLAnchorElement | null;
      if (!a || a.target === "_blank" || e.metaKey || e.ctrlKey) return;
      const url = new URL(a.href, location.href);
      if (url.origin !== location.origin || url.pathname === location.pathname) return;
      e.preventDefault();
      e.stopPropagation();
      setLeaveTo(url.pathname + url.search);
    };
    window.addEventListener("beforeunload", onUnload);
    document.addEventListener("click", onClick, true);
    return () => {
      window.removeEventListener("beforeunload", onUnload);
      document.removeEventListener("click", onClick, true);
    };
  }, [dirty]);

  const set = (k: HomeKey, patch: Partial<Form[HomeKey]>) => setForm((f) => (f ? { ...f, [k]: { ...f[k], ...patch } } : f));

  async function save() {
    if (!form) return;
    setBusy(true);
    setError(null);
    try {
      await api("/internal/site/home", { method: "PUT", body: { sections: toBody(form) } });
      setInitial(JSON.stringify(toBody(form)));
      setToast({ text: "Guardado. La portada lo mostrará en menos de un minuto.", tone: "ok" });
    } catch (e) {
      const err = e as ApiError;
      setError(err.detail ?? "No se pudo guardar.");
    } finally {
      setBusy(false);
    }
  }

  if (!form) return <p className="p-6 text-sm text-muted">Cargando…</p>;
  const hidden = HOME_KEYS.filter((k) => !form[k].visible).length;

  return (
    <div className="w-full space-y-6 pb-24">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Portada"]]}
        icon="layout"
        title="Portada"
        description="Enciende o apaga cada sección de la portada y cambia sus textos. Lo que no cambies usa el texto de fábrica."
      />

      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Chip tone={hidden ? "amber" : "ok"} dot>
          {hidden ? `${hidden} ${hidden === 1 ? "sección oculta" : "secciones ocultas"}` : "Todas las secciones visibles"}
        </Chip>
        <a href="/" target="_blank" rel="noreferrer" className="font-semibold text-brand hover:underline">
          Ver la portada ↗
        </a>
      </div>

      <div className="space-y-4">
        {HOME_KEYS.map((k, i) => {
          const d = HOME_DEFAULTS[k];
          const v = form[k];
          const f = factory(k);
          const changed = v.eyebrow !== f.eyebrow || v.title !== f.title || v.text !== f.text;
          return (
            <section key={k} className={`rounded-2xl border bg-surface p-5 shadow-sm transition sm:p-6 ${v.visible ? "border-line" : "border-dashed border-line opacity-80"}`}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                  <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-sm font-bold text-brand-strong">{i + 1}</span>
                  <div>
                    <h2 className="flex flex-wrap items-center gap-2 font-display text-lg font-semibold">
                      {d.label}
                      {!v.visible && <Chip tone="na">Oculta</Chip>}
                      {changed && <Chip tone="brand">Texto cambiado</Chip>}
                    </h2>
                    <p className="text-sm text-muted">{d.help}</p>
                  </div>
                </div>
                {d.title && (
                  <Button variant="ghost" size="sm" icon="refresh" disabled={!changed} onClick={() => set(k, f)}>
                    Volver al texto de fábrica
                  </Button>
                )}
              </div>

              <div className="mt-4 space-y-4">
                {d.fixed ? (
                  <p className="flex items-center gap-2 rounded-xl border border-line px-4 py-3 text-sm text-muted">
                    <Icon name="eye" size={15} /> Siempre se muestra: es lo primero que ve la persona.
                  </p>
                ) : (
                  <SwitchRow
                    label="Mostrar en la portada"
                    hint={v.visible ? "La sección se ve en la portada." : "La sección está apagada: no se ve, pero sus textos se guardan."}
                    checked={v.visible}
                    onChange={(on) => set(k, { visible: on })}
                  />
                )}
                {d.title && (
                  <div className="grid grid-cols-1 gap-4 md:grid-cols-[1fr_2fr]">
                    {d.eyebrow !== undefined && (
                      <FormField label="Texto pequeño de arriba" counter={`${v.eyebrow.length}/60`}>
                        <input className="input" maxLength={60} value={v.eyebrow} placeholder={f.eyebrow} onChange={(e) => set(k, { eyebrow: e.target.value })} />
                      </FormField>
                    )}
                    <FormField label="Título" counter={`${v.title.length}/120`}>
                      <input className="input" maxLength={120} value={v.title} placeholder={f.title} onChange={(e) => set(k, { title: e.target.value })} />
                    </FormField>
                    {d.text !== undefined && (
                      <div className="md:col-span-2">
                        <FormField label="Texto" counter={`${v.text.length}/300`}>
                          <textarea className="input min-h-[76px]" maxLength={300} value={v.text} placeholder={f.text} onChange={(e) => set(k, { text: e.target.value })} />
                        </FormField>
                      </div>
                    )}
                  </div>
                )}
              </div>
            </section>
          );
        })}
      </div>

      {/* Barra fija: cambios sin guardar */}
      <div className={`fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface/95 px-4 py-3 shadow-[0_-6px_20px_rgba(0,0,0,0.06)] backdrop-blur transition ${dirty || error ? "translate-y-0" : "translate-y-full"}`}>
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 lg:pl-64">
          <span className={`flex items-center gap-2 text-sm font-semibold ${error ? "text-bad" : "text-amber-700"}`}>
            <Icon name={error ? "bell" : "pen"} size={16} /> {error ?? "Tienes cambios sin guardar"}
          </span>
          <span className="flex gap-2">
            <Button variant="secondary" onClick={() => { load(); setError(null); }} disabled={busy}>
              Descartar
            </Button>
            <Button variant="primary" icon="check" onClick={save} loading={busy} disabled={!dirty}>
              Guardar
            </Button>
          </span>
        </div>
      </div>

      {leaveTo && (
        <Modal
          title="Tienes cambios sin guardar"
          icon="pen"
          onClose={() => setLeaveTo(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setLeaveTo(null)}>
                Seguir editando
              </Button>
              <Button variant="danger" onClick={() => { const to = leaveTo; setLeaveTo(null); setInitial(JSON.stringify(toBody(form))); router.push(to); }}>
                Salir sin guardar
              </Button>
            </>
          }
        >
          <p className="text-sm">Si sales ahora, se pierden los cambios de la portada que no guardaste.</p>
        </Modal>
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}
