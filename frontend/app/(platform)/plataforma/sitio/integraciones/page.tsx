"use client";

// Sitio web › Integraciones (SCR-WEB-74): lo que muestra la página pública /integraciones. El estado debe ser veraz:
// «Disponible» solo si ya funciona en EscudoSST; si se está probando, «Beta»; si aún no existe, «Planificada».

import { FormEvent, useState } from "react";
import { act, Guide, MediaField, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader } from "@/components/ui/Display";
import { ChoiceCards, FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, API_URL } from "@/lib/api";

type Integration = {
  id: string;
  name: string;
  category: string;
  description: string | null;
  availability: "available" | "beta" | "planned";
  docs_url: string | null;
  logo_media_id: string | null;
  logo_file_id: string | null;
  sort_order: number;
  status: "draft" | "published" | "hidden";
};
type Res = { data: Integration[]; categories: Record<string, string>; availability: Record<string, string> };

const AV_TONE: Record<Integration["availability"], ChipTone> = { available: "ok", beta: "amber", planned: "na" };
const AV_HELP: Record<Integration["availability"], string> = {
  available: "Ya funciona en EscudoSST y los clientes pueden usarla.",
  beta: "Funciona, pero se está probando con algunos clientes.",
  planned: "Está en el plan, todavía no existe.",
};

export default function IntegracionesPage() {
  const { res, error, load } = usePanelList<Res>("/internal/site/integrations");
  const [edit, setEdit] = useState<Integration | "new" | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [actError, setActError] = useState<string | null>(null);

  async function run(i: Integration, action: "publish" | "hide", msg: string) {
    setActError(null);
    if (await act(`/internal/site/integrations/${i.id}/${action}`, setActError)) {
      setToast(msg);
      load();
    }
  }
  async function remove(i: Integration) {
    if (!confirm(`¿Eliminar «${i.name}»?`)) return;
    await api(`/internal/site/integrations/${i.id}`, { method: "DELETE" });
    setToast("Integración eliminada.");
    load();
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Integraciones"]]}
        icon="plug"
        title="Integraciones"
        description="Con qué se conecta EscudoSST (correo, calendario, pagos, CRM…). Se muestran en la página pública /integraciones con su estado real."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setEdit("new")}>
            Nueva integración
          </Button>
        }
      />
      <Guide
        what="Lista de herramientas con las que EscudoSST se conecta (por ejemplo, Google Calendar, el CRM o el correo), para que el cliente sepa qué puede enlazar."
        where="En escudosst.intelectalab.com/integraciones."
        steps={["Agrega la integración con su logo y una frase de para qué sirve.", "Marca su estado real: Disponible, Beta (en prueba) o Planificada.", "«Publicar»."]}
      />
      {(error || actError) && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error ?? actError}</p>}
      {!res ? (
        !error && <p className="text-sm text-muted">Cargando…</p>
      ) : res.data.length === 0 ? (
        <EmptyState icon="plug" text="Aún no hay integraciones." />
      ) : (
        <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {res.data.map((i) => (
            <li key={i.id} className="flex flex-col gap-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
              <div className="flex items-start gap-3">
                <span className="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-xl border border-line bg-white">
                  {i.logo_file_id ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={`${API_URL}/api/v1/public/site/assets/${i.logo_file_id}`} alt="" className="h-full w-full object-contain p-1" />
                  ) : (
                    <span className="font-display font-bold text-muted">{i.name.charAt(0)}</span>
                  )}
                </span>
                <div className="min-w-0 flex-1">
                  <b className="block">{i.name}</b>
                  <span className="text-xs text-muted">{res.categories[i.category]}</span>
                </div>
                <Chip tone={i.status === "published" ? "ok" : "na"} dot>
                  {i.status === "published" ? "Publicada" : i.status === "hidden" ? "Oculta" : "Borrador"}
                </Chip>
              </div>
              {i.description && <p className="text-sm text-muted">{i.description}</p>}
              <p className="mt-auto">
                <Chip tone={AV_TONE[i.availability]}>{res.availability[i.availability]}</Chip>
              </p>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" icon="pen" onClick={() => setEdit(i)}>
                  Editar
                </Button>
                {i.status !== "published" ? (
                  <Button size="sm" variant="primary" onClick={() => run(i, "publish", "Integración publicada.")}>
                    Publicar
                  </Button>
                ) : (
                  <Button size="sm" onClick={() => run(i, "hide", "Integración oculta.")}>
                    Ocultar
                  </Button>
                )}
                <Button size="sm" variant="danger-ghost" onClick={() => remove(i)}>
                  Eliminar
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {edit && res && (
        <IntegrationModal
          i={edit === "new" ? null : edit}
          res={res}
          onClose={() => setEdit(null)}
          onSaved={() => {
            setEdit(null);
            setToast("Integración guardada.");
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function IntegrationModal({ i, res, onClose, onSaved }: { i: Integration | null; res: Res; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    name: i?.name ?? "",
    category: i?.category ?? "email",
    description: i?.description ?? "",
    availability: i?.availability ?? ("planned" as Integration["availability"]),
    docs_url: i?.docs_url ?? "",
    sort_order: String(i?.sort_order ?? 0),
  });
  const [logo, setLogo] = useState<{ id: string | null; file: string | null }>({ id: i?.logo_media_id ?? null, file: i?.logo_file_id ?? null });
  const { message, saving, run, err } = useSave();
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));

  async function save(e: FormEvent) {
    e.preventDefault();
    const body = { ...f, description: f.description || null, docs_url: f.docs_url || null, sort_order: Number(f.sort_order) || 0, logo_media_id: logo.id };
    const ok = await run(() => (i ? api(`/internal/site/integrations/${i.id}`, { method: "PUT", body }) : api("/internal/site/integrations", { method: "POST", body })));
    if (ok !== null) onSaved();
  }

  return (
    <Modal
      title={i ? "Editar integración" : "Nueva integración"}
      icon="refresh"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="integration-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="integration-form" onSubmit={save} noValidate className="space-y-4">
        {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Nombre" hint="Ej.: Google Calendar." error={err("name")}>
            <input className="input" maxLength={80} value={f.name} onChange={(e) => set("name", e.target.value)} />
          </FormField>
          <FormField label="Categoría" error={err("category")}>
            <select className="input" value={f.category} onChange={(e) => set("category", e.target.value)}>
              {Object.entries(res.categories).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </FormField>
        </div>
        <FormField label="Descripción (opcional)" hint="Para qué sirve, en una frase. Ej.: Las demos agendadas se guardan en tu calendario." error={err("description")}>
          <textarea className="input min-h-[70px]" maxLength={300} value={f.description} onChange={(e) => set("description", e.target.value)} />
        </FormField>
        <ChoiceCards
          label="Estado"
          columns={3}
          value={f.availability}
          onChange={(v) => set("availability", v)}
          options={(Object.keys(AV_HELP) as Integration["availability"][]).map((k) => ({ value: k, title: res.availability[k], detail: AV_HELP[k] }))}
        />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <MediaField label="Logo (opcional)" hint="Imagen o ícono de la biblioteca de medios." value={logo.id} fileId={logo.file} error={err("logo_media_id")} onChange={(id, file) => setLogo({ id, file })} />
          <div className="space-y-4">
            <FormField label="Enlace de documentación (opcional)" hint="https://… o una página del sitio (/ayuda/…)." error={err("docs_url")}>
              <input className="input" maxLength={300} value={f.docs_url} onChange={(e) => set("docs_url", e.target.value)} />
            </FormField>
            <FormField label="Orden" hint="Menor número sale primero dentro de su categoría." error={err("sort_order")}>
              <input className="input w-28" type="number" min={0} max={999} value={f.sort_order} onChange={(e) => set("sort_order", e.target.value)} />
            </FormField>
          </div>
        </div>
      </form>
    </Modal>
  );
}
