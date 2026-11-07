"use client";

// Sitio web › Novedades (SCR-WEB-77): qué cambió en EscudoSST. Se publican en /novedades, pueden mostrarse dentro de
// la aplicación (con contador de no leídas) y, al publicarse por primera vez, avisarse a los suscriptores del boletín.

import { FormEvent, useState } from "react";
import { act, day, Guide, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader } from "@/components/ui/Display";
import { ChoiceCards, FormField, SwitchRow } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api } from "@/lib/api";

type Update = {
  id: string;
  title: string;
  slug: string;
  body: string;
  category: "new" | "improvement" | "fix";
  modules_json: string | string[];
  status: "draft" | "published" | "archived";
  published_at: string | null;
  notify_subscribers: boolean;
  show_in_app: boolean;
};
type Res = { data: Update[]; categories: Record<string, string>; modules: { code: string; name: string }[] };

const CAT_TONE: Record<Update["category"], ChipTone> = { new: "brand", improvement: "ok", fix: "amber" };
const mods = (v: string | string[]) => (Array.isArray(v) ? v : (JSON.parse(v || "[]") as string[]));

export default function NovedadesPage() {
  const { res, error, load } = usePanelList<Res>("/internal/site/product-updates");
  const [edit, setEdit] = useState<Update | "new" | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [actError, setActError] = useState<string | null>(null);

  async function run(u: Update, action: "publish" | "archive", msg: string) {
    setActError(null);
    if (await act(`/internal/site/product-updates/${u.id}/${action}`, setActError)) {
      setToast(msg);
      load();
    }
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Novedades"]]}
        icon="sparkle"
        title="Novedades del producto"
        description="Cuenta qué hay de nuevo en EscudoSST: se publica en /novedades y, si quieres, en la campana de novedades dentro de la aplicación y por correo a los suscriptores del boletín."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setEdit("new")}>
            Nueva novedad
          </Button>
        }
      />
      <Guide
        what="Avisos de lo nuevo, lo mejorado o lo corregido en EscudoSST, para que los clientes se enteren."
        where="En escudosst.intelectalab.com/novedades y en la estrella «Novedades» dentro de la aplicación de los clientes."
        steps={["Escribe la novedad y elige si es Nuevo, Mejora o Corrección.", "Elige si sale en la aplicación y si se avisa por correo al boletín.", "«Publicar»."]}
      />
      {(error || actError) && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error ?? actError}</p>}
      {!res ? (
        !error && <p className="text-sm text-muted">Cargando…</p>
      ) : res.data.length === 0 ? (
        <EmptyState icon="sparkle" text="Aún no hay novedades." />
      ) : (
        <ul className="grid grid-cols-1 gap-3">
          {res.data.map((u) => (
            <li key={u.id} className="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5 shadow-sm sm:flex-row sm:items-center">
              <div className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <Chip tone={CAT_TONE[u.category]}>{res.categories[u.category]}</Chip>
                  <b className="font-display text-[15px]">{u.title}</b>
                </span>
                <p className="mt-1 line-clamp-2 text-sm text-muted">{u.body}</p>
                <p className="mt-1 text-xs text-muted">
                  {u.status === "published" ? `Publicada el ${day(u.published_at)}` : u.status === "archived" ? "Archivada" : "Borrador"}
                  {u.show_in_app ? " · en la aplicación" : ""}
                  {u.notify_subscribers ? " · aviso al boletín" : ""}
                  {mods(u.modules_json).length ? ` · ${mods(u.modules_json).join(", ")}` : ""}
                </p>
              </div>
              <div className="flex shrink-0 flex-wrap gap-2">
                <Button size="sm" icon="pen" onClick={() => setEdit(u)}>
                  Editar
                </Button>
                {u.status !== "published" && (
                  <Button size="sm" variant="primary" onClick={() => run(u, "publish", u.notify_subscribers && !u.published_at ? "Novedad publicada. El aviso al boletín sale en un minuto." : "Novedad publicada.")}>
                    Publicar
                  </Button>
                )}
                {u.status === "published" && (
                  <Button size="sm" onClick={() => run(u, "archive", "Novedad archivada.")}>
                    Archivar
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {edit && res && (
        <UpdateModal
          u={edit === "new" ? null : edit}
          res={res}
          onClose={() => setEdit(null)}
          onSaved={() => {
            setEdit(null);
            setToast("Novedad guardada.");
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function UpdateModal({ u, res, onClose, onSaved }: { u: Update | null; res: Res; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    title: u?.title ?? "",
    slug: u?.slug ?? "",
    body: u?.body ?? "",
    category: u?.category ?? ("new" as Update["category"]),
    modules: u ? mods(u.modules_json) : ([] as string[]),
    notify_subscribers: u?.notify_subscribers ?? false,
    show_in_app: u?.show_in_app ?? true,
  });
  const { message, saving, run, err } = useSave();
  const sent = !!u?.published_at;

  async function save(e: FormEvent) {
    e.preventDefault();
    const body = { ...f, slug: f.slug || null };
    const ok = await run(() => (u ? api(`/internal/site/product-updates/${u.id}`, { method: "PUT", body }) : api("/internal/site/product-updates", { method: "POST", body })));
    if (ok !== null) onSaved();
  }

  return (
    <Modal
      title={u ? "Editar novedad" : "Nueva novedad"}
      icon="star"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="update-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="update-form" onSubmit={save} noValidate className="space-y-4">
        {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
        <FormField label="Título" hint="Ej.: Ahora puedes firmar las charlas de 5 minutos en el celular." error={err("title")}>
          <input className="input" maxLength={120} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} />
        </FormField>
        <ChoiceCards
          label="Tipo"
          columns={3}
          value={f.category}
          onChange={(v) => setF({ ...f, category: v })}
          options={[
            { value: "new", title: "Nuevo", detail: "Algo que antes no existía." },
            { value: "improvement", title: "Mejora", detail: "Algo que ya existía y ahora funciona mejor." },
            { value: "fix", title: "Corrección", detail: "Se arregló un problema." },
          ]}
        />
        <FormField label="Texto" hint="Qué cambió y para qué le sirve al cliente. Separa párrafos con una línea en blanco." counter={`${f.body.length}/5000`} error={err("body")}>
          <textarea className="input min-h-[160px]" maxLength={5000} value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} />
        </FormField>
        <div>
          <span className="mb-1.5 block text-sm font-semibold">Módulos (opcional)</span>
          <div className="flex max-h-36 flex-wrap gap-1.5 overflow-y-auto">
            {res.modules.map((m) => {
              const on = f.modules.includes(m.code);
              return (
                <button
                  key={m.code}
                  type="button"
                  onClick={() => setF({ ...f, modules: on ? f.modules.filter((x) => x !== m.code) : [...f.modules, m.code] })}
                  className={`rounded-full border px-3 py-1 text-xs font-semibold ${on ? "border-brand bg-brand text-white" : "border-line text-muted hover:border-brand/50"}`}
                >
                  {m.name}
                </button>
              );
            })}
          </div>
        </div>
        <SwitchRow label="Mostrar dentro de la aplicación" hint="Los usuarios la ven en «Novedades», con un contador de no leídas." checked={f.show_in_app} onChange={(v) => setF({ ...f, show_in_app: v })} />
        <SwitchRow
          label="Avisar a los suscriptores del boletín"
          hint={sent ? "Ya se publicó: el aviso solo sale la primera vez." : "Al publicarla, les llega por correo con el enlace (solo suscriptores confirmados)."}
          checked={f.notify_subscribers}
          onChange={(v) => setF({ ...f, notify_subscribers: v })}
        />
        <FormField label="Dirección (opcional)" hint="Se arma sola con el título: /novedades/…" error={err("slug")}>
          <input className="input font-mono text-xs" maxLength={80} value={f.slug} onChange={(e) => setF({ ...f, slug: e.target.value.toLowerCase() })} />
        </FormField>
      </form>
    </Modal>
  );
}
