"use client";

// Sitio web › Subencargados (SCR-WEB-48): la lista pública de /confianza. Solo el Dueño la cambia y avisa a los
// administradores de cuenta cuando publica un cambio (RN-WEB-52).

import { FormEvent, useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { countryName } from "@/lib/trust";

type Sub = { id: string; name: string; purpose: string; country: string; data_categories: string; transfer_basis: string; website: string | null; to_validate: boolean; sort_order: number; status: "active" | "inactive" };

export default function SubencargadosPage() {
  const me = usePlatformUser();
  const owner = me.platform_role !== "support";
  const [rows, setRows] = useState<Sub[] | null>(null);
  const [editing, setEditing] = useState<Sub | "new" | null>(null);
  const [notify, setNotify] = useState(false);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);

  const load = useCallback(() => {
    api<{ data: { subprocessors: Sub[] } }>("/internal/site/trust-status").then((r) => setRows(r.data.subprocessors));
  }, []);
  useEffect(load, [load]);

  async function remove(s: Sub) {
    if (!window.confirm(`¿Quitar a ${s.name} de la lista?`)) return;
    try {
      await api(`/internal/site/subprocessors/${s.id}`, { method: "DELETE" });
      setToast({ text: "Quitado de la lista.", tone: "ok" });
      load();
    } catch {
      setToast({ text: "No se pudo.", tone: "bad" });
    }
  }

  return (
    <div className="w-full space-y-5">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Subencargados"]]}
        icon="lock"
        title="Subencargados"
        description="Proveedores que tratan datos por encargo de EscudoSST (servidores, correo, archivos). Los activos se publican en /confianza."
        aside={
          owner && (
            <div className="flex flex-wrap gap-2">
              <Button icon="mail" onClick={() => setNotify(true)}>
                Avisar a los clientes
              </Button>
              <Button variant="primary" icon="plus" onClick={() => setEditing("new")}>
                Agregar
              </Button>
            </div>
          )
        }
      />

      <div className="rounded-2xl border border-amber/30 bg-amber-soft/60 p-4 text-sm text-amber-ink">
        <b>La lista debe ser real.</b> Revisa que coincida con los proveedores que de verdad usas hoy (por ejemplo, el servidor y el correo con el que envías). Marca «por validar» lo que falte revisar con el abogado: en el sitio se ve con esa etiqueta.
      </div>

      {rows === null ? (
        <div className="h-48 animate-pulse rounded-2xl bg-line/40" />
      ) : (
        <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          {rows.map((s) => (
            <li key={s.id} className="flex min-w-0 flex-col rounded-2xl border border-line bg-surface shadow-sm">
              <div className="p-4">
                <span className="flex flex-wrap gap-1.5">
                  <Chip tone={s.status === "active" ? "ok" : "na"} dot>
                    {s.status === "active" ? "Publicado" : "Oculto"}
                  </Chip>
                  <Chip tone="brand">{countryName(s.country)}</Chip>
                  {s.to_validate && <Chip tone="amber">Por validar</Chip>}
                </span>
                <b className="mt-1.5 block font-display text-[15px]">{s.name}</b>
                <p className="text-sm text-muted">{s.purpose}</p>
                <p className="mt-2 text-xs text-muted">
                  <b className="text-fg">Datos:</b> {s.data_categories}
                </p>
                <p className="mt-1 text-xs text-muted">
                  <b className="text-fg">Transferencia:</b> {s.transfer_basis}
                </p>
              </div>
              {owner && (
                <div className="mt-auto flex gap-1 border-t border-line bg-bg/50 px-3 py-2">
                  <Button size="sm" variant="ghost" icon="pen" onClick={() => setEditing(s)}>
                    Editar
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => remove(s)}>
                    Quitar
                  </Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {editing && (
        <SubModal
          row={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null);
            setToast({ text: "Guardado. Si es un cambio importante, avisa a los clientes.", tone: "ok" });
            load();
          }}
        />
      )}
      {notify && (
        <NotifyModal
          onClose={() => setNotify(false)}
          onDone={(n) => {
            setNotify(false);
            setToast({ text: `Aviso enviado a ${n} administrador${n === 1 ? "" : "es"} de cuenta.`, tone: "ok" });
          }}
        />
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function SubModal({ row, onClose, onDone }: { row: Sub | null; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({
    name: row?.name ?? "",
    purpose: row?.purpose ?? "",
    country: row?.country?.trim() ?? "",
    data_categories: row?.data_categories ?? "",
    transfer_basis: row?.transfer_basis ?? "",
    website: row?.website ?? "",
    to_validate: row?.to_validate ?? true,
    status: row?.status ?? "active",
    sort_order: row?.sort_order ?? 0,
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      await api(row ? `/internal/site/subprocessors/${row.id}` : "/internal/site/subprocessors", { method: row ? "PATCH" : "POST", body: { ...f, website: f.website || null } });
      onDone();
    } catch (x) {
      if (x instanceof ApiError) setErrors(x.errors ?? { name: [x.detail ?? x.message] });
      setSaving(false);
    }
  }

  const field = (k: "purpose" | "data_categories" | "transfer_basis", label: string, hint: string, max: number) => (
    <FormField label={label} error={errors[k]?.[0]} hint={hint}>
      <textarea className="input min-h-16" maxLength={max} value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
    </FormField>
  );

  return (
    <Modal
      title={row ? "Editar subencargado" : "Agregar subencargado"}
      icon="lock"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="sub-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="sub-form" onSubmit={save} className="space-y-4" noValidate>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_120px]">
          <FormField label="Proveedor" error={errors.name?.[0]}>
            <input className="input" maxLength={120} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus />
          </FormField>
          <FormField label="País" error={errors.country?.[0]} hint="2 letras: US, DE, PE">
            <input className="input font-mono uppercase" maxLength={2} value={f.country} onChange={(e) => setF({ ...f, country: e.target.value.toUpperCase().replace(/[^A-Z]/g, "") })} />
          </FormField>
        </div>
        {field("purpose", "Para qué lo usamos", "Ej.: alojamiento de la aplicación y la base de datos.", 300)}
        {field("data_categories", "Qué datos trata", "Ej.: datos de usuarios y registros SST (sin datos médicos).", 400)}
        {field("transfer_basis", "Base de la transferencia internacional", "Ej.: cláusulas contractuales. Si no está definido, escribe «Por validar con abogado».", 300)}
        <FormField label="Sitio web (opcional)" error={errors.website?.[0]}>
          <input className="input" maxLength={300} placeholder="https://" value={f.website} onChange={(e) => setF({ ...f, website: e.target.value })} />
        </FormField>
        <SwitchRow label="Por validar" hint="Se muestra con la etiqueta «Por validar» en /confianza." checked={f.to_validate} onChange={(v) => setF({ ...f, to_validate: v })} />
        <SwitchRow label="Publicado en /confianza" hint="Si lo apagas, queda guardado pero no se muestra." checked={f.status === "active"} onChange={(v) => setF({ ...f, status: v ? "active" : "inactive" })} />
      </form>
    </Modal>
  );
}

function NotifyModal({ onClose, onDone }: { onClose: () => void; onDone: (n: number) => void }) {
  const [summary, setSummary] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function send(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const r = await api<{ data: { sent: number } }>("/internal/site/subprocessors/notify", { method: "POST", body: { summary } });
      onDone(r.data.sent);
    } catch (x) {
      setError(x instanceof ApiError ? (Object.values(x.errors ?? {})[0]?.[0] ?? x.message) : "No se pudo enviar.");
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Avisar el cambio a los clientes"
      icon="mail"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" type="submit" form="notify-form" icon="mail" loading={saving}>
            Enviar aviso
          </Button>
        </>
      }
    >
      <form id="notify-form" onSubmit={send} className="space-y-3">
        <p className="flex items-start gap-2 rounded-xl bg-bg p-3 text-sm">
          <Icon name="people" size={16} className="mt-0.5 shrink-0 text-brand" />
          Se envía un correo a los administradores activos de todas las empresas clientes, con un enlace a /confianza.
        </p>
        <FormField label="Qué cambió" error={error ?? undefined} hint="Ej.: Agregamos a X para el envío de correos desde el 1 de enero.">
          <textarea className="input min-h-24" maxLength={600} value={summary} onChange={(e) => setSummary(e.target.value)} autoFocus />
        </FormField>
      </form>
    </Modal>
  );
}
