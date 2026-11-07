"use client";

// Sitio web › Webinars (SCR-WEB-79): charlas en vivo con inscripción. A los inscritos les llega el enlace de la reunión
// (con .ics) y recordatorios un día y una hora antes. Después se marca la asistencia y, si se avisó, se publica la grabación.

import { FormEvent, useEffect, useState } from "react";
import { act, dt, fromLocalInput, HowItWorks, MediaField, toLocalInput, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone, EmptyState, PageHeader } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api } from "@/lib/api";

type Webinar = {
  id: string;
  title: string;
  slug: string;
  description: string | null;
  speaker_name: string;
  speaker_title: string | null;
  starts_at: string;
  duration_minutes: number;
  meeting_provider: string;
  meeting_url: string | null;
  capacity: number | null;
  status: "draft" | "published" | "live" | "finished" | "cancelled";
  cover_media_id: string | null;
  cover_file_id: string | null;
  recording_media_id: string | null;
  recording_informed: boolean;
  recording_public: boolean;
  registered: number;
  attended: number;
};
type Res = { data: Webinar[]; providers: Record<string, string> };
type Reg = { id: string; status: "registered" | "cancelled" | "attended" | "no_show"; registered_at: string; full_name: string | null; email: string; company_name: string | null; marketing_consent: boolean };

const STATUS: Record<Webinar["status"], [string, ChipTone]> = {
  draft: ["Borrador", "na"],
  published: ["Inscripciones abiertas", "ok"],
  live: ["En vivo", "brand"],
  finished: ["Terminado", "na"],
  cancelled: ["Cancelado", "bad"],
};
const REG: Record<Reg["status"], [string, ChipTone]> = { registered: ["Inscrito", "brand"], cancelled: ["Canceló", "na"], attended: ["Asistió", "ok"], no_show: ["No asistió", "amber"] };

export default function WebinarsPage() {
  const { res, error, load } = usePanelList<Res>("/internal/site/webinars");
  const [edit, setEdit] = useState<Webinar | "new" | null>(null);
  const [people, setPeople] = useState<Webinar | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [actError, setActError] = useState<string | null>(null);

  async function run(w: Webinar, action: "publish" | "live" | "finish" | "cancel", msg: string) {
    if (action === "cancel" && !confirm("¿Cancelar el webinar? Ya no se podrá inscribir nadie.")) return;
    setActError(null);
    if (await act(`/internal/site/webinars/${w.id}/${action}`, setActError)) {
      setToast(msg);
      load();
    }
  }

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Webinars"]]}
        icon="play"
        title="Webinars"
        description="Charlas en vivo con inscripción desde /webinars. Quien se inscribe entra a «Interesados» y recibe el enlace de la reunión y los recordatorios por correo."
        aside={
          <Button variant="primary" icon="plus" onClick={() => setEdit("new")}>
            Nuevo webinar
          </Button>
        }
      />
      <HowItWorks
        steps={[
          ["pen", "1. Crea el webinar", "Tema, ponente, fecha (hora de Lima), duración, enlace de la reunión y cupos."],
          ["globe", "2. Publícalo", "Solo el Dueño. Se abren las inscripciones en /webinars."],
          ["mail", "3. Avisos automáticos", "Confirmación con el enlace y recordatorios un día y una hora antes."],
          ["check", "4. Después", "Marca quién asistió. La grabación se publica solo si se avisó a todos."],
        ]}
      />
      {(error || actError) && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error ?? actError}</p>}
      {!res ? (
        !error && <p className="text-sm text-muted">Cargando…</p>
      ) : res.data.length === 0 ? (
        <EmptyState icon="play" text="Aún no hay webinars." />
      ) : (
        <ul className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {res.data.map((w) => (
            <li key={w.id} className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <b className="block font-display text-[15px]">{w.title}</b>
                  <span className="text-xs text-muted">
                    {dt(w.starts_at)} · {w.duration_minutes} min · {w.speaker_name} · {res.providers[w.meeting_provider]}
                  </span>
                </div>
                <Chip tone={STATUS[w.status][1]} dot>
                  {STATUS[w.status][0]}
                </Chip>
              </div>
              <dl className="grid grid-cols-3 divide-x divide-line rounded-xl border border-line text-center text-sm">
                <div className="px-2 py-2">
                  <dt className="text-xs text-muted">Inscritos</dt>
                  <dd className="font-display text-lg font-bold tabular-nums">{w.registered}</dd>
                </div>
                <div className="px-2 py-2">
                  <dt className="text-xs text-muted">Cupos</dt>
                  <dd className="font-display text-lg font-bold tabular-nums">{w.capacity ?? "Sin límite"}</dd>
                </div>
                <div className="px-2 py-2">
                  <dt className="text-xs text-muted">Asistieron</dt>
                  <dd className="font-display text-lg font-bold tabular-nums">{w.attended}</dd>
                </div>
              </dl>
              {!w.meeting_url && ["draft", "published"].includes(w.status) && <p className="text-xs text-amber-ink">Falta el enlace de la reunión: los inscritos lo reciben en su correo.</p>}
              <div className="flex flex-wrap gap-2">
                <Button size="sm" icon="pen" onClick={() => setEdit(w)}>
                  Editar
                </Button>
                <Button size="sm" icon="people" onClick={() => setPeople(w)}>
                  Inscritos
                </Button>
                {w.status === "draft" && (
                  <Button size="sm" variant="primary" onClick={() => run(w, "publish", "Webinar publicado: inscripciones abiertas.")}>
                    Publicar
                  </Button>
                )}
                {w.status === "published" && (
                  <Button size="sm" onClick={() => run(w, "live", "Webinar en vivo.")}>
                    Marcar en vivo
                  </Button>
                )}
                {["published", "live"].includes(w.status) && (
                  <Button size="sm" onClick={() => run(w, "finish", "Webinar terminado.")}>
                    Terminar
                  </Button>
                )}
                {["draft", "published"].includes(w.status) && (
                  <Button size="sm" variant="danger-ghost" onClick={() => run(w, "cancel", "Webinar cancelado.")}>
                    Cancelar
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      {edit && res && (
        <WebinarModal
          w={edit === "new" ? null : edit}
          res={res}
          onClose={() => setEdit(null)}
          onSaved={() => {
            setEdit(null);
            setToast("Webinar guardado.");
            load();
          }}
        />
      )}
      {people && <PeopleModal w={people} onClose={() => (setPeople(null), load())} />}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function WebinarModal({ w, res, onClose, onSaved }: { w: Webinar | null; res: Res; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    title: w?.title ?? "",
    slug: w?.slug ?? "",
    description: w?.description ?? "",
    speaker_name: w?.speaker_name ?? "",
    speaker_title: w?.speaker_title ?? "",
    starts_at: toLocalInput(w?.starts_at ?? null),
    duration_minutes: String(w?.duration_minutes ?? 60),
    meeting_provider: w?.meeting_provider ?? "meet",
    meeting_url: w?.meeting_url ?? "",
    capacity: w?.capacity ? String(w.capacity) : "",
    recording_informed: w?.recording_informed ?? false,
    recording_public: w?.recording_public ?? false,
  });
  const [cover, setCover] = useState<{ id: string | null; file: string | null }>({ id: w?.cover_media_id ?? null, file: w?.cover_file_id ?? null });
  const [recording, setRecording] = useState<{ id: string | null; file: string | null }>({ id: w?.recording_media_id ?? null, file: null });
  const { message, saving, run, err } = useSave();
  const set = (k: keyof typeof f, v: string | boolean) => setF((x) => ({ ...x, [k]: v }));

  async function save(e: FormEvent) {
    e.preventDefault();
    const body = {
      ...f,
      slug: f.slug || null,
      description: f.description || null,
      speaker_title: f.speaker_title || null,
      starts_at: fromLocalInput(f.starts_at),
      duration_minutes: Number(f.duration_minutes),
      meeting_url: f.meeting_url || null,
      capacity: f.capacity ? Number(f.capacity) : null,
      cover_media_id: cover.id,
      recording_media_id: recording.id,
    };
    const ok = await run(() => (w ? api(`/internal/site/webinars/${w.id}`, { method: "PUT", body }) : api("/internal/site/webinars", { method: "POST", body })));
    if (ok !== null) onSaved();
  }

  return (
    <Modal
      title={w ? "Editar webinar" : "Nuevo webinar"}
      icon="play"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="webinar-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="webinar-form" onSubmit={save} noValidate className="space-y-4">
        {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
        <FormField label="Tema" hint="Ej.: Cómo armar tu IPERC paso a paso." error={err("title")}>
          <input className="input" maxLength={140} value={f.title} onChange={(e) => set("title", e.target.value)} />
        </FormField>
        <FormField label="Descripción (opcional)" hint="Qué aprenderán y para quién es." error={err("description")}>
          <textarea className="input min-h-[100px]" maxLength={3000} value={f.description} onChange={(e) => set("description", e.target.value)} />
        </FormField>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Ponente" error={err("speaker_name")}>
            <input className="input" maxLength={120} value={f.speaker_name} onChange={(e) => set("speaker_name", e.target.value)} />
          </FormField>
          <FormField label="Cargo del ponente (opcional)" hint="Ej.: Especialista SST." error={err("speaker_title")}>
            <input className="input" maxLength={160} value={f.speaker_title} onChange={(e) => set("speaker_title", e.target.value)} />
          </FormField>
          <FormField label="Fecha y hora (Lima)" error={err("starts_at")}>
            <input className="input" type="datetime-local" value={f.starts_at} onChange={(e) => set("starts_at", e.target.value)} />
          </FormField>
          <FormField label="Duración (minutos)" error={err("duration_minutes")}>
            <input className="input" type="number" min={10} max={600} value={f.duration_minutes} onChange={(e) => set("duration_minutes", e.target.value)} />
          </FormField>
          <FormField label="Plataforma" error={err("meeting_provider")}>
            <select className="input" value={f.meeting_provider} onChange={(e) => set("meeting_provider", e.target.value)}>
              {Object.entries(res.providers).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Enlace de la reunión (opcional por ahora)" hint="Solo lo reciben los inscritos, por correo. Empieza con https://" error={err("meeting_url")}>
            <input className="input" maxLength={500} value={f.meeting_url} onChange={(e) => set("meeting_url", e.target.value)} />
          </FormField>
          <FormField label="Cupos (opcional)" hint="Vacío = sin límite." error={err("capacity")}>
            <input className="input" type="number" min={1} value={f.capacity} onChange={(e) => set("capacity", e.target.value)} />
          </FormField>
          <MediaField label="Portada (opcional)" value={cover.id} fileId={cover.file} error={err("cover_media_id")} onChange={(id, file) => setCover({ id, file })} />
        </div>
        <fieldset className="space-y-3 rounded-xl border border-line p-4">
          <legend className="px-1 text-sm font-semibold">Grabación (después del webinar)</legend>
          <MediaField label="Video de la grabación" kind="video" hint="Súbelo a la biblioteca de medios." value={recording.id} fileId={recording.file} error={err("recording_media_id")} onChange={(id, file) => setRecording({ id, file })} />
          <SwitchRow label="Se avisó a ponente y asistentes que se graba" hint="Obligatorio para publicar la grabación (protección de datos)." checked={f.recording_informed} onChange={(v) => set("recording_informed", v)} />
          <SwitchRow label="Publicar la grabación en /webinars" hint="Solo cuando el webinar terminó." checked={f.recording_public} onChange={(v) => set("recording_public", v)} />
          {err("recording_public") && <p className="text-xs font-medium text-bad">● {err("recording_public")}</p>}
        </fieldset>
        <FormField label="Dirección (opcional)" hint="Se arma sola con el tema: /webinars/…" error={err("slug")}>
          <input className="input font-mono text-xs" maxLength={80} value={f.slug} onChange={(e) => set("slug", e.target.value.toLowerCase())} />
        </FormField>
      </form>
    </Modal>
  );
}

function PeopleModal({ w, onClose }: { w: Webinar; onClose: () => void }) {
  const [rows, setRows] = useState<Reg[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = () => api<{ data: Reg[] }>(`/internal/site/webinars/${w.id}/registrations`).then((r) => setRows(r.data));
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  async function mark(r: Reg, status: "attended" | "no_show") {
    if (await act(`/internal/site/webinars/registrations/${r.id}/${status}`, setError)) load();
  }
  const done = ["live", "finished"].includes(w.status);
  return (
    <Modal title={`Inscritos · ${w.title}`} subtitle={done ? "Marca quién asistió." : "La asistencia se marca cuando empieza el webinar."} icon="people" size="lg" onClose={onClose} footer={<Button onClick={onClose}>Cerrar</Button>}>
      {error && <p className="mb-3 rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
      {!rows ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : rows.length === 0 ? (
        <p className="text-sm text-muted">Aún no hay inscritos.</p>
      ) : (
        <ul className="divide-y divide-line">
          {rows.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 py-2.5 text-sm">
              <span className="min-w-0 flex-1">
                <b className="block">{r.full_name ?? r.email}</b>
                <span className="text-xs text-muted">
                  {r.email}
                  {r.company_name ? ` · ${r.company_name}` : ""} · {dt(r.registered_at)}
                </span>
              </span>
              <Chip tone={REG[r.status][1]}>{REG[r.status][0]}</Chip>
              {done && r.status !== "cancelled" && (
                <span className="flex gap-1">
                  <Button size="sm" variant={r.status === "attended" ? "primary" : "secondary"} onClick={() => mark(r, "attended")}>
                    Asistió
                  </Button>
                  <Button size="sm" variant={r.status === "no_show" ? "primary" : "secondary"} onClick={() => mark(r, "no_show")}>
                    No asistió
                  </Button>
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </Modal>
  );
}
