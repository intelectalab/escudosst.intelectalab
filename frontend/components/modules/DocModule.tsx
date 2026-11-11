"use client";

// Gestión documental (M-DOC): sobre la lista de documentos, la «Bandeja de aprobación» (SCR-DOC-04: versiones en
// revisión con su resumen de cambios frente a la vigente, visor, aprobar con contraseña o devolver con comentario) y la
// «Campaña de cargos» (SCR-DOC-05: pedir la lectura de la versión vigente por sede, área y puesto, con avance x/y,
// recordatorio y pendientes). DocFicha (SCR-DOC-02) también se muestra dentro de la ficha de cada documento.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { FileViewer } from "./CtrShared";
import { RefSelect } from "./RefSelect";
import { Bar, Card } from "./Summary";

type InboxRow = {
  id: string; document_id: string; version_number: number; version_label: string | null; change_summary: string | null; file_id: string | null;
  file_name: string | null; file_mime: string | null; content_html: string | null; submitted_by_name: string | null; submitted_at: string | null;
  effective_on: string | null; requires_ack: boolean; document_code: string; document_title: string; category_name: string | null;
  approver_label: string; current_number: number | null; current_label: string | null; current_summary: string | null;
  can_approve: boolean; cannot_reason: string | null;
};

type Version = {
  id: string; version_number: number; version_label: string | null; status: string; change_summary: string | null; submitted_at: string | null;
  approved_at: string | null; effective_on: string | null; obsoleted_at: string | null; review_comments: string | null; created_at: string;
  submitted_by_name: string | null; approved_by_name: string | null; reviewed_by_name: string | null;
};
type Pending = { id: string; full_name: string; site_name: string | null; area_name: string | null; position_name: string | null; notified_at: string | null };
type Acks = {
  version_id: string; version_number: number; version_label: string | null; requires_ack: boolean; campaign: boolean; expected: number; received: number;
  received_total: number; pct: number | null; last_sent_at: string | null; pending: Pending[];
};
type Sheet = { document: { id: string; code: string; title: string; category_name: string | null; owner_name: string | null; next_review_on: string | null }; versions: Version[]; acks: Acks | null };

const V_STATUS: Record<string, [string, "ok" | "amber" | "bad" | "na" | "brand"]> = {
  draft: ["Borrador", "na"], in_review: ["En revisión", "amber"], approved: ["Aprobada", "brand"], current: ["Vigente", "ok"], obsolete: ["Obsoleta", "na"],
};
const fmtDate = (v: string | null) => (v ? new Date(v.length <= 10 ? `${v}T12:00:00` : v).toLocaleDateString("es-PE", { timeZone: "America/Lima" }) : "—");
const fmtDateTime = (v: string | null) => (v ? new Date(v).toLocaleString("es-PE", { timeZone: "America/Lima", dateStyle: "short", timeStyle: "short" }) : "—");
const errText = (e: unknown) => {
  const x = e as ApiError;
  return Object.values(x.errors ?? {})[0]?.[0] ?? x.detail ?? x.title;
};
const vName = (n: number, label: string | null) => label || `v${n}`;

export function DocModule({ readOnly }: { readOnly: boolean }) {
  const [tab, setTab] = useState<"inbox" | "campaign">("inbox");
  const tabs: [typeof tab, string][] = [["inbox", "Bandeja de aprobación"], ["campaign", "Campaña de cargos"]];
  return (
    <div className="space-y-4">
      <div role="tablist" aria-label="Gestión documental" className="flex gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1">
        {tabs.map(([t, l]) => (
          <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
            className={`min-h-10 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold ${tab === t ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"}`}>
            {l}
          </button>
        ))}
      </div>
      {tab === "inbox" ? <ApprovalInbox readOnly={readOnly} /> : <AckCampaign readOnly={readOnly} />}
    </div>
  );
}

// ─────────────── SCR-DOC-04: bandeja de aprobación ───────────────

function ApprovalInbox({ readOnly }: { readOnly: boolean }) {
  const [rows, setRows] = useState<InboxRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [view, setView] = useState<InboxRow | null>(null);
  const [act, setAct] = useState<{ row: InboxRow; kind: "approve" | "return" } | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: InboxRow[] }>("/doc/inbox", { withCompany: true }).then((r) => setRows(r.data)).catch((e) => setError(errText(e)));
  }, []);
  useEffect(load, [load]);

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (!rows) return <div className="h-32 animate-pulse rounded-xl bg-surface" />;
  return (
    <Card title={`Versiones en revisión · ${rows.length}`}>
      {rows.length === 0 ? (
        <EmptyState icon="check" text="No hay versiones esperando aprobación." />
      ) : (
        <ul className="space-y-3">
          {rows.map((r) => (
            <li key={r.id} className="space-y-3 rounded-xl border border-line p-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="font-semibold">
                    {r.document_code} · {r.document_title} <span className="font-normal text-muted">({vName(r.version_number, r.version_label)})</span>
                  </p>
                  <p className="text-xs text-muted">
                    {r.category_name ?? "Sin categoría"} · Enviada por {r.submitted_by_name ?? "—"} el {fmtDateTime(r.submitted_at)} · Aprueba {r.approver_label}
                    {r.effective_on ? ` · Vigente desde ${fmtDate(r.effective_on)}` : ""}
                  </p>
                </div>
                <Chip tone="amber">En revisión</Chip>
              </div>
              <div className="grid gap-2 sm:grid-cols-2">
                <div className="rounded-lg bg-bg p-3 text-sm">
                  <p className="text-xs font-semibold text-muted">{r.current_number ? `Versión vigente (${vName(r.current_number, r.current_label)})` : "Versión vigente"}</p>
                  <p className="mt-1 whitespace-pre-line">{r.current_number ? r.current_summary || "Sin resumen de cambios." : "Es la primera versión del documento."}</p>
                </div>
                <div className="rounded-lg border border-brand/30 p-3 text-sm">
                  <p className="text-xs font-semibold text-brand-strong">Cambios de esta versión</p>
                  <p className="mt-1 whitespace-pre-line">{r.change_summary || "Sin resumen de cambios."}</p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Button size="sm" icon="eye" onClick={() => setView(r)}>Ver documento</Button>
                {!readOnly && r.can_approve && (
                  <>
                    <Button size="sm" variant="primary" icon="check" onClick={() => setAct({ row: r, kind: "approve" })}>Aprobar con firma</Button>
                    <Button size="sm" variant="danger-ghost" onClick={() => setAct({ row: r, kind: "return" })}>Devolver</Button>
                  </>
                )}
                {!r.can_approve && r.cannot_reason && <span className="text-xs text-muted">{r.cannot_reason}</span>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {view && (
        <Modal title={`${view.document_code} · ${vName(view.version_number, view.version_label)}`} subtitle={view.document_title} icon="file" size="lg" onClose={() => setView(null)}>
          <VersionViewer row={view} />
        </Modal>
      )}
      {act && (
        <DecisionModal
          row={act.row}
          kind={act.kind}
          onClose={() => setAct(null)}
          onDone={(m) => {
            setAct(null);
            setToast(m);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </Card>
  );
}

function VersionViewer({ row }: { row: { file_id: string | null; file_name: string | null; file_mime: string | null; content_html: string | null } }) {
  if (row.file_id) return <FileViewer fileId={row.file_id} name={row.file_name} mime={row.file_mime} />;
  if (row.content_html)
    return (
      // El contenido se muestra aislado (sin scripts) en un marco.
      <iframe title="Contenido del documento" sandbox="" srcDoc={`<meta charset="utf-8"><style>body{font-family:system-ui,sans-serif;font-size:14px;line-height:1.5;color:#1f2937;padding:8px}</style>${row.content_html}`}
        className="h-[60vh] w-full rounded-lg border border-line bg-white" />
    );
  return <p className="text-sm text-muted">La versión no tiene archivo ni contenido.</p>;
}

function DecisionModal({ row, kind, onClose, onDone }: { row: InboxRow; kind: "approve" | "return"; onClose: () => void; onDone: (msg: string) => void }) {
  const [value, setValue] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const approve = kind === "approve";
  const send = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { message?: string; requires_ack?: boolean } }>(`/doc/versions/${row.id}/${approve ? "approve" : "return"}`, {
        method: "POST", withCompany: true, body: approve ? { password: value } : { reason: value },
      });
      onDone(approve ? `${r.data.message ?? "Versión aprobada."}${r.data.requires_ack ? " Lanza la campaña de cargos para pedir su lectura." : ""}` : "Versión devuelta con tu comentario.");
    } catch (e) {
      setMsg(errText(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      title={approve ? "Aprobar versión" : "Devolver versión"}
      subtitle={`${row.document_code} · ${vName(row.version_number, row.version_label)}`}
      icon={approve ? "check" : "pen"}
      size="sm"
      onClose={onClose}
      footer={
        <div className="flex w-full justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>Cancelar</Button>
          <Button variant={approve ? "primary" : "danger"} loading={busy} disabled={!value.trim()} onClick={send}>{approve ? "Aprobar" : "Devolver"}</Button>
        </div>
      }
    >
      {approve ? (
        <div className="space-y-3">
          <p className="text-sm text-muted">
            Tu contraseña equivale a tu firma de aprobación. {row.effective_on && row.effective_on > new Date().toISOString().slice(0, 10)
              ? `Quedará vigente desde el ${fmtDate(row.effective_on)}.`
              : "Queda vigente desde hoy y la versión anterior pasa a obsoleta."}
          </p>
          <FormField label="Tu contraseña">
            <input className="input" type="password" autoComplete="current-password" autoFocus value={value} onChange={(e) => setValue(e.target.value)} />
          </FormField>
        </div>
      ) : (
        <FormField label="Comentario para quien la envió" hint="Qué se debe corregir. La versión vuelve a borrador.">
          <textarea className="input min-h-28" autoFocus maxLength={2000} value={value} onChange={(e) => setValue(e.target.value)} />
        </FormField>
      )}
      {msg && <p role="alert" className="mt-3 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
    </Modal>
  );
}

// ─────────────── SCR-DOC-05: campaña de cargos ───────────────

function AckCampaign({ readOnly }: { readOnly: boolean }) {
  const [doc, setDoc] = useState("");
  return (
    <Card title="Campaña de cargos">
      <div className="max-w-xl">
        <FormField label="Documento" hint="Se pide la lectura de su versión vigente.">
          <RefSelect module="DOC" table="document_versions" field="document_id" label="Documento" value={doc} onChange={(id) => setDoc(id)} placeholder="Elige el documento" />
        </FormField>
      </div>
      {doc ? <div className="mt-4"><DocFicha key={doc} documentId={doc} readOnly={readOnly} showCampaign /></div> : <p className="mt-3 text-sm text-muted">Elige un documento para ver sus cargos recibidos y pedir la lectura a un grupo.</p>}
    </Card>
  );
}

/** Ficha del documento (SCR-DOC-02): línea de tiempo de versiones y cargos recibidos vs. esperados; con campaña opcional. */
export function DocFicha({ documentId, readOnly, showCampaign = true }: { documentId: string; readOnly: boolean; showCampaign?: boolean }) {
  const [sheet, setSheet] = useState<Sheet | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [target, setTarget] = useState({ site_id: "", area_id: "", position_id: "" });
  const [busy, setBusy] = useState<"campaign" | "remind" | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const [showPending, setShowPending] = useState(false);

  const load = useCallback(() => {
    api<{ data: Sheet }>(`/doc/documents/${documentId}/sheet`, { withCompany: true }).then((r) => setSheet(r.data)).catch((e) => setError(errText(e)));
  }, [documentId]);
  useEffect(load, [load]);

  const run = async (kind: "campaign" | "remind") => {
    setBusy(kind);
    setMsg(null);
    try {
      const r = await api<{ data: { requested?: number; new?: number; already_acknowledged?: number; sent?: number } }>(`/doc/documents/${documentId}/${kind}`, {
        method: "POST", withCompany: true, body: kind === "campaign" ? { site_id: target.site_id || null, area_id: target.area_id || null, position_id: target.position_id || null } : {},
      });
      setMsg({
        tone: "ok",
        text: kind === "campaign"
          ? `Se pidió la lectura a ${r.data.requested} trabajador(es) (${r.data.new} aviso(s) nuevo(s)). ${r.data.already_acknowledged ? `${r.data.already_acknowledged} ya habían firmado el cargo.` : ""}`
          : `Recordatorio enviado a ${r.data.sent} trabajador(es) pendiente(s).`,
      });
      load();
    } catch (e) {
      setMsg({ tone: "bad", text: errText(e) });
    } finally {
      setBusy(null);
    }
  };

  if (error) return <p className="rounded-xl border border-line px-4 py-3 text-sm text-muted">{error}</p>;
  if (!sheet) return <div className="h-24 animate-pulse rounded-xl bg-bg" />;
  const a = sheet.acks;
  return (
    <div className="space-y-4">
      <section aria-label="Cargos de la versión vigente" className="space-y-3 rounded-xl border border-line p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="font-semibold">
            Cargos recibidos vs. esperados {a && <span className="font-normal text-muted">· versión vigente {vName(a.version_number, a.version_label)}</span>}
          </p>
          {a && !a.campaign && <Chip tone="na">Sin campaña: esperados según el alcance del documento</Chip>}
        </div>
        {!a ? (
          <p className="text-sm text-muted">El documento aún no tiene una versión vigente. Cuando se apruebe, aquí verás quién firmó el cargo de lectura.</p>
        ) : (
          <>
            <p className="text-sm">
              <b className="font-display text-2xl">{a.received}</b> de <b>{a.expected}</b> cargos recibidos {a.pct !== null && <span className="text-muted">({a.pct} %)</span>}
              {a.received_total > a.received && <span className="text-xs text-muted"> · {a.received_total} en total, incluidos fuera de la campaña</span>}
            </p>
            <Bar pct={a.pct ?? 0} />
            {a.last_sent_at && <p className="text-xs text-muted">Último aviso enviado: {fmtDateTime(a.last_sent_at)}</p>}
            {a.pending.length > 0 && (
              <div>
                <button type="button" className="text-sm font-semibold text-brand hover:underline" aria-expanded={showPending} onClick={() => setShowPending(!showPending)}>
                  {showPending ? "Ocultar" : "Ver"} pendientes ({a.pending.length}{a.pending.length === 200 ? "+" : ""})
                </button>
                {showPending && (
                  <ul className="mt-2 max-h-72 divide-y divide-line overflow-y-auto rounded-lg border border-line text-sm">
                    {a.pending.map((p) => (
                      <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <span>
                          <b>{p.full_name}</b>
                          <span className="block text-xs text-muted">{[p.site_name, p.area_name, p.position_name].filter(Boolean).join(" · ") || "Sin sede ni puesto"}</span>
                        </span>
                        <span className="text-xs text-muted">{p.notified_at ? `Avisado ${fmtDate(p.notified_at)}` : "Sin aviso"}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
            {showCampaign && !readOnly && (
              <div className="space-y-3 rounded-lg border border-dashed border-line p-3">
                <p className="text-sm font-semibold">Pedir la lectura a un grupo</p>
                <div className="grid gap-3 sm:grid-cols-3">
                  <FormField label="Sede">
                    <RefSelect module="DOC" table="documents" field="site_id" label="Sede" placeholder="Todas las sedes" value={target.site_id} onChange={(id) => setTarget({ ...target, site_id: id })} />
                  </FormField>
                  <FormField label="Área">
                    <RefSelect module="DOC" table="documents" field="area_id" label="Área" placeholder="Todas las áreas" value={target.area_id} onChange={(id) => setTarget({ ...target, area_id: id })} />
                  </FormField>
                  <FormField label="Puesto">
                    <RefSelect module="DOC" table="documents" field="position_id" label="Puesto" placeholder="Todos los puestos" value={target.position_id} onChange={(id) => setTarget({ ...target, position_id: id })} />
                  </FormField>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button variant="primary" icon="megaphone" loading={busy === "campaign"} onClick={() => run("campaign")}>Pedir cargo de lectura</Button>
                  {a.campaign && a.pending.length > 0 && <Button icon="bell" loading={busy === "remind"} onClick={() => run("remind")}>Enviar recordatorio a pendientes</Button>}
                </div>
                <p className="text-xs text-muted">Cada trabajador recibe el aviso «Documento pendiente de cargo» en la app (y por WhatsApp si lo aceptó). Quien ya firmó no se vuelve a avisar.</p>
              </div>
            )}
            {msg && <p role={msg.tone === "bad" ? "alert" : "status"} className={`rounded-lg px-3 py-2 text-sm ${msg.tone === "bad" ? "bg-bad-soft text-bad" : "bg-ok-soft text-ok"}`}>{msg.text}</p>}
          </>
        )}
      </section>
      <section aria-label="Versiones" className="space-y-2 rounded-xl border border-line p-4">
        <p className="font-semibold">Línea de tiempo de versiones</p>
        {sheet.versions.length === 0 ? (
          <p className="text-sm text-muted">Aún no hay versiones.</p>
        ) : (
          <ol className="space-y-3 border-l-2 border-line pl-4">
            {sheet.versions.map((v) => {
              const [label, tone] = V_STATUS[v.status] ?? [v.status, "na"];
              return (
                <li key={v.id} className="relative">
                  <span className={`absolute -left-[1.4rem] top-1.5 h-3 w-3 rounded-full border-2 border-surface ${v.status === "current" ? "bg-ok" : v.status === "in_review" ? "bg-[#F4B90F]" : "bg-na"}`} aria-hidden="true" />
                  <div className="flex flex-wrap items-center gap-2">
                    <b>{vName(v.version_number, v.version_label)}</b>
                    <Chip tone={tone}>{label}</Chip>
                    {v.effective_on && <span className="text-xs text-muted">Vigente desde {fmtDate(v.effective_on)}{v.obsoleted_at ? ` hasta ${fmtDate(v.obsoleted_at)}` : ""}</span>}
                  </div>
                  {v.change_summary && <p className="text-sm">{v.change_summary}</p>}
                  <p className="text-xs text-muted">
                    {[
                      v.submitted_at && `Enviada por ${v.submitted_by_name ?? "—"} el ${fmtDate(v.submitted_at)}`,
                      v.approved_at && `Aprobada por ${v.approved_by_name ?? "—"} el ${fmtDate(v.approved_at)}`,
                      !v.submitted_at && !v.approved_at && `Creada el ${fmtDate(v.created_at)}`,
                    ].filter(Boolean).join(" · ")}
                  </p>
                  {v.review_comments && v.status === "draft" && (
                    <p className="mt-1 rounded-lg bg-amber-soft px-3 py-1.5 text-xs text-amber-ink">Devuelta por {v.reviewed_by_name ?? "el aprobador"}: {v.review_comments}</p>
                  )}
                </li>
              );
            })}
          </ol>
        )}
      </section>
    </div>
  );
}
