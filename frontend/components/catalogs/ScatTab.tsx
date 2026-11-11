"use client";

// Catálogo SCAT global (M-INV SCR-INV-04): lo que ve quien investiga en el asistente SCAT (contacto, causas inmediatas,
// causas básicas y necesidades de acción de control). Lo valida un especialista SST; solo el Dueño lo cambia.
// Los códigos no cambian ni se reutilizan: las investigaciones ya hechas conservan el código y el texto que guardaron.

import { FormEvent, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { SCAT_LEVELS, type ScatItem, type ScatLevel } from "@/lib/catalogs";

type Done = (msg: string) => void;
const LEVELS = Object.keys(SCAT_LEVELS) as ScatLevel[];

/** Siguiente código libre de un nivel (A16 → A17). */
function nextCode(items: ScatItem[], level: ScatLevel): string {
  const prefix = SCAT_LEVELS[level].prefix;
  const nums = items.filter((i) => i.code.startsWith(prefix) && /^\d+$/.test(i.code.slice(prefix.length))).map((i) => Number(i.code.slice(prefix.length)));
  return `${prefix}${String((nums.length ? Math.max(...nums) : 0) + 1).padStart(2, "0")}`;
}

export function ScatTab({ items, canEdit, onChanged }: { items: ScatItem[]; canEdit: boolean; onChanged: Done }) {
  const [level, setLevel] = useState<ScatLevel>("contact");
  const [showInactive, setShowInactive] = useState(false);
  const [edit, setEdit] = useState<ScatItem | "new" | null>(null);
  const [confirm, setConfirm] = useState<ScatItem | null>(null);
  const [busy, setBusy] = useState(false);
  const inLevel = items.filter((i) => i.level === level);
  const list = inLevel.filter((i) => showInactive || i.is_active);
  const toConfirm = items.filter((i) => i.is_active && i.to_confirm).length;

  async function move(item: ScatItem, dir: -1 | 1) {
    const ids = inLevel.map((i) => i.id);
    const at = ids.indexOf(item.id);
    const to = at + dir;
    if (to < 0 || to >= ids.length) return;
    [ids[at], ids[to]] = [ids[to], ids[at]];
    setBusy(true);
    try {
      await api("/internal/catalogs/scat/reorder", { method: "POST", body: { level, ids } });
      onChanged("Orden actualizado.");
    } catch (e) {
      onChanged(e instanceof ApiError ? e.message : "No se pudo cambiar el orden.");
    } finally {
      setBusy(false);
    }
  }

  async function toggle(item: ScatItem) {
    try {
      await api(`/internal/catalogs/scat/${item.id}`, {
        method: "PATCH",
        body: { label: item.label, explanation: item.explanation, category: item.category, is_active: !item.is_active },
      });
      onChanged(item.is_active ? `${item.code} desactivado: ya no se ofrece en investigaciones nuevas.` : `${item.code} activado.`);
    } catch (e) {
      onChanged(e instanceof ApiError ? e.message : "No se pudo guardar.");
    }
  }

  return (
    <div className="space-y-4">
      <p className="flex items-start gap-2 rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">
        <Icon name="shield" size={16} className="mt-0.5 shrink-0" />
        Taxonomía propia de EscudoSST con la estructura del modelo SCAT. La valida un especialista SST: {toConfirm > 0 ? `${toConfirm} elementos activos siguen «por confirmar».` : "todo está confirmado."} Las
        investigaciones ya hechas conservan lo que guardaron.
      </p>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <label className="flex items-center gap-2 text-sm">
          <span className="text-muted">Nivel</span>
          <select className="filter-control" aria-label="Nivel del catálogo SCAT" value={level} onChange={(e) => setLevel(e.target.value as ScatLevel)}>
            {LEVELS.map((l) => (
              <option key={l} value={l}>
                {SCAT_LEVELS[l].label} ({items.filter((i) => i.level === l && i.is_active).length})
              </option>
            ))}
          </select>
        </label>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
            Ver desactivados
          </label>
          {canEdit && (
            <Button icon="plus" onClick={() => setEdit("new")}>
              Nuevo elemento
            </Button>
          )}
        </div>
      </div>
      <p className="text-sm text-muted">{SCAT_LEVELS[level].hint}</p>

      {list.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface">
          <EmptyState icon="checklist" text="Este nivel no tiene elementos." />
        </div>
      ) : (
        <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
          {list.map((i) => {
            const pos = inLevel.indexOf(i);
            return (
              <li key={i.id} className={`flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-5 ${i.is_active ? "" : "opacity-60"}`}>
                {canEdit && (
                  <span className="flex shrink-0 gap-1 sm:flex-col">
                    <button type="button" disabled={busy || pos === 0} onClick={() => move(i, -1)} className="rounded p-1 text-muted hover:bg-bg hover:text-fg disabled:opacity-30" aria-label={`Subir ${i.code}`}>
                      <Icon name="chevron" size={14} className="-rotate-90" />
                    </button>
                    <button
                      type="button"
                      disabled={busy || pos === inLevel.length - 1}
                      onClick={() => move(i, 1)}
                      className="rounded p-1 text-muted hover:bg-bg hover:text-fg disabled:opacity-30"
                      aria-label={`Bajar ${i.code}`}
                    >
                      <Icon name="chevron" size={14} className="rotate-90" />
                    </button>
                  </span>
                )}
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-center gap-2">
                    <Code>{i.code}</Code>
                    <span className="font-medium">{i.label}</span>
                  </p>
                  {(i.explanation || i.category) && (
                    <p className="mt-0.5 text-xs text-muted">
                      {i.category && <span className="font-semibold">{i.category}</span>}
                      {i.category && i.explanation && " · "}
                      {i.explanation}
                    </p>
                  )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {!i.is_active && <Chip tone="na">Desactivado</Chip>}
                  {i.to_confirm ? (
                    <Chip tone="amber" dot>
                      Por confirmar
                    </Chip>
                  ) : (
                    <span title={`Confirmado por ${i.confirmed_by ?? ""}`}>
                      <Chip tone="ok" dot>
                        Confirmado
                      </Chip>
                    </span>
                  )}
                  {canEdit && (
                    <>
                      {i.to_confirm && i.is_active && (
                        <Button variant="soft" size="sm" icon="check" onClick={() => setConfirm(i)}>
                          Confirmar
                        </Button>
                      )}
                      <Button variant="secondary" size="sm" icon="pen" onClick={() => setEdit(i)}>
                        Editar
                      </Button>
                      <Button variant={i.is_active ? "danger-ghost" : "ghost"} size="sm" icon={i.is_active ? "pause" : "play"} onClick={() => toggle(i)}>
                        {i.is_active ? "Desactivar" : "Activar"}
                      </Button>
                    </>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {edit && <ItemModal item={edit === "new" ? null : edit} level={level} suggested={nextCode(items, level)} onClose={() => setEdit(null)} onDone={(m) => (setEdit(null), onChanged(m))} />}
      {confirm && <ConfirmModal item={confirm} onClose={() => setConfirm(null)} onDone={(m) => (setConfirm(null), onChanged(m))} />}
    </div>
  );
}

function ItemModal({ item, level, suggested, onClose, onDone }: { item: ScatItem | null; level: ScatLevel; suggested: string; onClose: () => void; onDone: Done }) {
  const [form, setForm] = useState({
    level: item?.level ?? level,
    code: item?.code ?? suggested,
    label: item?.label ?? "",
    explanation: item?.explanation ?? "",
    category: item?.category ?? "",
    is_active: item?.is_active ?? true,
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    const body = { label: form.label, explanation: form.explanation.trim() || null, category: form.category.trim() || null, is_active: form.is_active };
    try {
      await api(item ? `/internal/catalogs/scat/${item.id}` : "/internal/catalogs/scat", { method: item ? "PATCH" : "POST", body: item ? body : { ...body, level: form.level, code: form.code } });
      onDone(item ? `${item.code} actualizado: queda «por confirmar».` : `${form.code.toUpperCase()} agregado: queda «por confirmar».`);
    } catch (err) {
      setErrors(err instanceof ApiError ? (err.errors ?? { _: [err.message] }) : { _: ["No se pudo guardar."] });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={item ? `Elemento ${item.code}` : "Nuevo elemento SCAT"}
      subtitle={SCAT_LEVELS[form.level].label}
      icon="checklist"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="scat-form" icon="check" loading={saving}>
            Guardar
          </Button>
        </>
      }
    >
      <form id="scat-form" onSubmit={submit} className="space-y-4" noValidate>
        {errors._ && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{errors._[0]}</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Nivel">
            <select
              className="input"
              disabled={!!item}
              value={form.level}
              onChange={(e) => setForm({ ...form, level: e.target.value as ScatLevel, code: SCAT_LEVELS[e.target.value as ScatLevel].prefix + form.code.replace(/^[A-Z]+/, "") })}
            >
              {LEVELS.map((l) => (
                <option key={l} value={l}>
                  {SCAT_LEVELS[l].label}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Código" hint={item ? "El código no cambia: lo guardan las investigaciones." : `Empieza con «${SCAT_LEVELS[form.level].prefix}».`} error={errors.code?.[0]}>
            <input className="input uppercase" maxLength={8} disabled={!!item} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          </FormField>
        </div>
        <FormField label="Texto" hint="Lo que ve quien investiga." error={errors.label?.[0]}>
          <input className="input" maxLength={200} value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} />
        </FormField>
        <FormField label="Explicación" hint="Opcional: cuándo elegirlo, con un ejemplo." counter={`${form.explanation.length}/1000`} error={errors.explanation?.[0]}>
          <textarea className="input min-h-20" maxLength={1000} value={form.explanation} onChange={(e) => setForm({ ...form, explanation: e.target.value })} />
        </FormField>
        <FormField label="Categoría" hint="Opcional: agrupa elementos dentro del nivel." error={errors.category?.[0]}>
          <input className="input" maxLength={80} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
        </FormField>
        <SwitchRow label="Activo" hint="Si lo desactivas, ya no se ofrece en investigaciones nuevas. Las que ya lo usan no cambian." checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
        <p className="text-xs text-muted">Al guardar un texto nuevo o cambiado, el elemento queda «por confirmar» hasta que lo valide un especialista SST.</p>
      </form>
    </Modal>
  );
}

function ConfirmModal({ item, onClose, onDone }: { item: ScatItem; onClose: () => void; onDone: Done }) {
  const [who, setWho] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api(`/internal/catalogs/scat/${item.id}/confirm`, { method: "POST", body: { confirmed_by: who.trim() } });
      onDone(`${item.code} confirmado.`);
    } catch (err) {
      setError(err instanceof ApiError ? (err.errors?.confirmed_by?.[0] ?? err.message) : "No se pudo confirmar.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Confirmar elemento SCAT"
      subtitle={`${item.code} · ${item.label}`}
      icon="check"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="scat-confirm" icon="check" loading={saving}>
            Confirmar
          </Button>
        </>
      }
    >
      <form id="scat-confirm" onSubmit={submit} className="space-y-4" noValidate>
        <p className="text-sm">Confirma el texto solo con la revisión de un especialista SST.</p>
        <FormField label="Especialista que lo validó" hint="Nombre y cargo. Queda en la bitácora." error={error ?? undefined}>
          <input className="input" maxLength={120} placeholder="Ej.: Ing. Rosa Salas, especialista SST" value={who} onChange={(e) => setWho(e.target.value)} />
        </FormField>
      </form>
    </Modal>
  );
}
