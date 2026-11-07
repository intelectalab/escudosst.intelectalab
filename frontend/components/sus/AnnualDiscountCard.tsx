"use client";

// Planes y módulos › «Descuentos»: el descuento por pago anual (parámetro sus.annual_discount_pct, con historial
// de versiones como todo parámetro) y una explicación de los descuentos especiales, que van en cada cotización.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { AffixInput, FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import type { LegalParameter } from "@/lib/catalogs";

const KEY = "sus.annual_discount_pct";
const today = () => new Date().toLocaleDateString("sv-SE", { timeZone: "America/Lima" });
const nextDay = (d: string) => new Date(new Date(`${d}T12:00:00-05:00`).getTime() + 86400000).toLocaleDateString("sv-SE", { timeZone: "America/Lima" });
const fmt = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "long", timeZone: "America/Lima" }).format(new Date(`${d}T12:00:00-05:00`));

export function AnnualDiscountCard({ onSaved }: { onSaved: (msg: string) => void }) {
  const [versions, setVersions] = useState<LegalParameter[] | null>(null);
  const [editing, setEditing] = useState(false);

  const load = useCallback(() => {
    api<{ data: LegalParameter[] }>("/internal/catalogs/legal-parameters")
      .then((r) => setVersions(r.data.filter((p) => p.key === KEY)))
      .catch(() => setVersions([]));
  }, []);
  useEffect(load, [load]);

  const current = versions?.find((p) => p.is_current) ?? null;
  const latest = versions?.slice().sort((a, b) => b.valid_from.localeCompare(a.valid_from))[0] ?? null;
  const upcoming = latest && current && latest.id !== current.id ? latest : null;
  const pct = current ? Number(current.value) || 0 : 0;

  return (
    <section aria-labelledby="descuentos" className="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
      <div className="flex flex-col gap-5 lg:flex-row">
        <div className="flex min-w-0 flex-1 gap-4">
          <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-ok-soft text-ok">
            <Icon name="tag" size={22} />
          </span>
          <div className="min-w-0 space-y-1">
            <h2 id="descuentos" className="font-display text-lg font-semibold">
              Descuento por pago anual
            </h2>
            <p className="text-sm text-muted">
              Se aplica solo cuando el cliente paga el año completo, a planes y complementos por igual: <b>anual = 12 × precio mensual − este descuento</b>. Se ve en
              «Planes» del sitio, en la cotización automática y en las cotizaciones formales.
            </p>
            {upcoming && (
              <p className="text-xs font-semibold text-amber">
                Desde el {fmt(upcoming.valid_from)} pasará a {Number(upcoming.value)} %.
              </p>
            )}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-4 lg:flex-col lg:items-end lg:justify-center">
          <p className="font-display text-3xl font-bold tabular-nums">{versions === null ? "…" : `${pct} %`}</p>
          <Button icon="pen" disabled={!latest} onClick={() => setEditing(true)}>
            Cambiar
          </Button>
        </div>
      </div>
      <div className="mt-5 flex items-start gap-3 rounded-xl bg-bg px-4 py-3 text-sm">
        <Icon name="help" size={17} className="mt-0.5 shrink-0 text-muted" />
        <p className="text-muted">
          <b className="text-fg">¿Un descuento especial para un cliente</b> (por ser grupo, por campaña, por negociación)? No cambies los precios: ponlo en su cotización, en
          el campo «Descuento». Así solo le aplica a él y queda en el PDF.{" "}
          <Link href="/plataforma/sitio/cotizaciones" className="font-semibold text-brand-strong hover:underline">
            Ir a Cotizaciones
          </Link>
        </p>
      </div>
      {editing && latest && (
        <DiscountModal
          latest={latest}
          current={pct}
          onClose={() => setEditing(false)}
          onSaved={(msg) => {
            setEditing(false);
            load();
            onSaved(msg);
          }}
        />
      )}
    </section>
  );
}

function DiscountModal({ latest, current, onClose, onSaved }: { latest: LegalParameter; current: number; onClose: () => void; onSaved: (msg: string) => void }) {
  // Una nueva versión debe empezar después de la última (el historial no se pisa).
  const min = latest.valid_from >= today() ? nextDay(latest.valid_from) : today();
  const [value, setValue] = useState(String(current));
  const [from, setFrom] = useState(min);
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const n = Number(value);
  const example = 500;

  async function save() {
    if (!(n >= 0 && n <= 50)) {
      setErrors({ value: ["Escribe un porcentaje entre 0 y 50."] });
      return;
    }
    setSaving(true);
    try {
      await api(`/internal/catalogs/legal-parameters/${latest.id}/versions`, { method: "POST", body: { value: String(n), valid_from: from, reason: reason.trim() } });
      onSaved(from === today() ? `Descuento anual: ${n} % desde hoy.` : `Descuento anual: ${n} % desde el ${fmt(from)}.`);
    } catch (e) {
      if (e instanceof ApiError) setErrors(e.errors ?? { value: [e.message] });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Cambiar el descuento por pago anual"
      subtitle="Queda en el historial con la fecha y el motivo. Las cotizaciones ya enviadas no cambian."
      icon="tag"
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" icon="check" loading={saving} disabled={reason.trim().length < 5} onClick={save}>
            Guardar
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Descuento" hint="0 = sin descuento. Máximo 50 %." error={errors.value?.[0]}>
            <AffixInput suffix="%" inputMode="decimal" value={value} invalid={!!errors.value} onChange={(e) => setValue(e.target.value.replace(",", ".").replace(/[^\d.]/g, ""))} />
          </FormField>
          <FormField label="Rige desde" hint={min === today() ? "Hoy o una fecha futura." : `Desde el ${fmt(min)} (ya hubo un cambio hoy).`} error={errors.valid_from?.[0]}>
            <input className="input" type="date" min={min} value={from} onChange={(e) => setFrom(e.target.value)} />
          </FormField>
        </div>
        {n >= 0 && n <= 50 && (
          <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">
            Ejemplo: un plan de S/ {example} al mes pagado por año costaría <b className="text-fg">S/ {(example * 12 * (1 - n / 100)).toLocaleString("es-PE", { minimumFractionDigits: 2 })}</b>{" "}
            (en vez de S/ {(example * 12).toLocaleString("es-PE", { minimumFractionDigits: 2 })}).
          </p>
        )}
        <FormField label="Motivo del cambio" hint="Solo lo ve el equipo. Ej.: campaña de inicio de año, nueva política comercial." error={errors.reason?.[0]}>
          <input className="input" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  );
}
