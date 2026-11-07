"use client";

// Sitio web › Páginas (SCR-WEB-20): lista de páginas por bloques (sectores, diferenciadores y otras) con su estado.

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { PAGE_TYPES, type PageType } from "@/lib/pages";

type Row = { id: string; slug: string; page_type: PageType; title: string; summary: string | null; status: "draft" | "published" | "archived"; has_changes: boolean; published_at: string | null };

const STATUS = { draft: ["Borrador", "amber"], published: ["Publicada", "ok"], archived: ["Quitada del sitio", "na"] } as const;

export default function PaginasPage() {
  const [rows, setRows] = useState<Row[] | null>(null);
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    api<{ data: Row[] }>("/internal/site/pages")
      .then((r) => setRows(r.data))
      .catch(() => setRows([]));
  }, []);

  return (
    <div className="w-full space-y-6">
      <PageHeader crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Páginas"]]} icon="pen" title="Páginas" description="Páginas armadas con bloques: por sector, por diferenciador y otras. Se editan en borrador y se publican cuando estén listas." />

      <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <h2 className="font-display font-semibold">Cómo funciona</h2>
        <ol className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
          {(
            [
              ["pen", "1. Editas el borrador", "Agregas, ordenas y escribes los bloques. Puedes ver cómo queda antes de publicar."],
              ["globe", "2. Publicas", "Recién ahí la página aparece en el sitio. Cambios posteriores esperan a que vuelvas a publicar."],
              ["eye", "3. Revisas en el sitio", "«Ver en el sitio» abre la página publicada. «Quitar del sitio» la oculta sin borrarla."],
            ] as const
          ).map(([icon, t, d]) => (
            <li key={t} className="flex items-start gap-3 rounded-xl bg-bg/60 p-3.5">
              <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong">
                <Icon name={icon} size={17} />
              </span>
              <span>
                <b className="block text-sm">{t}</b>
                <span className="text-xs text-muted">{d}</span>
              </span>
            </li>
          ))}
        </ol>
        <p className="mt-3 text-xs text-muted">Las páginas iniciales vienen con un texto base tomado de la documentación. Revísalo antes de publicar.</p>
      </section>

      {rows && <ComparisonCard rows={rows} />}

      <div className="flex justify-end">
        <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
          Nueva página
        </Button>
      </div>

      {rows === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : (
        (Object.keys(PAGE_TYPES) as PageType[]).map((t) => {
          const list = rows.filter((r) => r.page_type === t);
          if (!list.length) return null;
          return (
            <section key={t} className="space-y-3">
              <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
                <Icon name={PAGE_TYPES[t].icon} size={18} className="text-brand" /> {PAGE_TYPES[t].plural}
                <span className="text-sm font-normal text-muted">· {list.filter((r) => r.status === "published").length} de {list.length} publicadas</span>
              </h2>
              <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
                {list.map((r) => (
                  <li key={r.id}>
                    <Link href={`/plataforma/sitio/paginas/${r.id}`} className="group flex h-full flex-col gap-2 rounded-2xl border border-line bg-surface p-5 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md">
                      <span className="flex flex-wrap items-center gap-2">
                        <Chip tone={STATUS[r.status][1]} dot>
                          {STATUS[r.status][0]}
                        </Chip>
                        {r.has_changes && <Chip tone="amber">Cambios sin publicar</Chip>}
                      </span>
                      <b className="font-display text-[15px] group-hover:text-brand-strong">{r.title}</b>
                      <span className="font-mono text-xs text-muted">/{r.slug}</span>
                      {r.summary && <span className="line-clamp-2 text-sm text-muted">{r.summary}</span>}
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          );
        })
      )}

      {creating && <NewPageModal onClose={() => setCreating(false)} />}
    </div>
  );
}

/** Comparativa «EscudoSST vs Excel y papel» (SCR-WEB-31): qué es, dónde sale y crearla con la estructura sugerida. */
function ComparisonCard({ rows }: { rows: Row[] }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const page = rows.find((r) => r.page_type === "comparison" && r.slug === "comparativa");
  if (page?.status === "published") return null;

  // Tareas que pide la documentación (M-WEB, SCR-WEB-31). Las dos columnas las escribe el equipo.
  const TASKS = ["IPERC", "Capacitaciones", "Entrega de EPP", "Inspecciones", "Expediente para SUNAFIL", "Alertas de vencimiento"];
  async function create() {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { id: string } }>("/internal/site/pages", {
        method: "POST",
        body: {
          page_type: "comparison",
          slug: "comparativa",
          title: "EscudoSST vs Excel y papel",
          summary: "Cómo cambia la gestión de SST al pasar de Excel y papel a EscudoSST, tarea por tarea.",
          blocks: [
            { type: "hero", eyebrow: "Comparativa", title: "EscudoSST vs Excel y papel", text: "" },
            { type: "compare", title: "Tarea por tarea", manual_label: "Con Excel y papel", escudo_label: "Con EscudoSST", items: TASKS.map((task) => ({ task, manual: "", escudo: "" })) },
            { type: "cta", title: "Pruébalo con tu propia empresa", text: "", button: "trial" },
          ],
        },
      });
      router.push(`/plataforma/sitio/paginas/${r.data.id}`);
    } catch (e) {
      setError(e instanceof ApiError ? (e.errors ? Object.values(e.errors)[0]?.[0] : e.message) ?? e.message : "No se pudo crear.");
      setBusy(false);
    }
  }

  return (
    <section className="flex flex-col gap-4 rounded-2xl border border-brand/30 bg-brand-soft/40 p-5 sm:flex-row sm:items-start">
      <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand text-white">
        <Icon name="swap" size={20} />
      </span>
      <div className="min-w-0 flex-1 space-y-1.5 text-sm">
        <b className="block font-display text-[15px]">Comparativa «EscudoSST vs Excel y papel» · {page ? "en borrador" : "aún no creada"}</b>
        <p className="text-muted">
          Una página en <b>/comparativa</b> que muestra, tarea por tarea, cómo se lleva hoy la SST con Excel y papel y cómo se hace con EscudoSST. Sirve para que una empresa entienda rápido
          el cambio. No lleva precios ni nombra a otras empresas.
        </p>
        <p className="text-muted">
          {page
            ? "Ábrela, completa las dos columnas de cada tarea, mira la vista previa y publícala. Al publicarla, el pie del sitio la enlaza solo."
            : "Al crearla viene armada con un encabezado, una tabla con 6 tareas (IPERC, capacitaciones, EPP, inspecciones, expediente SUNAFIL y alertas) y un botón final. Tú escribes el texto de cada columna."}
        </p>
        {error && <p className="font-semibold text-bad">{error}</p>}
      </div>
      {page ? (
        <Link href={`/plataforma/sitio/paginas/${page.id}`} className="inline-flex h-10 shrink-0 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-strong">
          <Icon name="pen" size={16} /> Completar y publicar
        </Link>
      ) : (
        <Button variant="primary" icon="plus" loading={busy} onClick={create}>
          Crear la comparativa
        </Button>
      )}
    </section>
  );
}

function NewPageModal({ onClose }: { onClose: () => void }) {
  const router = useRouter();
  const [type, setType] = useState<PageType>("landing");
  const [title, setTitle] = useState("");
  const [slug, setSlug] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const prefix = PAGE_TYPES[type].prefix;
  const auto = (t: string) =>
    t
      .toLowerCase()
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "");

  async function create(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const r = await api<{ data: { id: string } }>("/internal/site/pages", {
        method: "POST",
        body: { page_type: type, title, slug: prefix + (slug || auto(title)), blocks: [{ type: "hero", eyebrow: "", title, text: "" }] },
      });
      router.push(`/plataforma/sitio/paginas/${r.data.id}`);
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.errors ?? {});
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Nueva página"
      icon="file"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="new-page" icon="check" loading={saving}>
            Crear y editar
          </Button>
        </>
      }
    >
      <form id="new-page" onSubmit={create} className="space-y-4" noValidate>
        <FormField label="Tipo">
          <select className="input" value={type} onChange={(e) => setType(e.target.value as PageType)}>
            {(Object.keys(PAGE_TYPES) as PageType[]).map((t) => (
              <option key={t} value={t}>
                {PAGE_TYPES[t].label}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Título" error={errors.title?.[0]}>
          <input className="input" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} />
        </FormField>
        <FormField label="Dirección" error={errors.slug?.[0]} hint="Solo minúsculas, números y guiones.">
          <span className="flex items-center gap-1 font-mono text-sm">
            <span className="text-muted">/{prefix}</span>
            <input className="input font-mono" maxLength={80} placeholder={auto(title) || "mi-pagina"} value={slug} onChange={(e) => setSlug(auto(e.target.value))} />
          </span>
        </FormField>
      </form>
    </Modal>
  );
}
