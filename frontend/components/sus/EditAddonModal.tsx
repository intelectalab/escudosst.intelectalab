"use client";

// «Editar complemento» (SCR-SUS-01): PATCH /internal/addons/{code}.

import { FormEvent, useState } from "react";
import { Button } from "@/components/ui/Button";
import { AffixInput, FormField, FormSection, SwitchRow } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import type { Addon } from "@/lib/types";

export function EditAddonModal({ addon, onClose, onSaved }: { addon: Addon; onClose: () => void; onSaved: (m: string) => void }) {
  const [name, setName] = useState(addon.name);
  const [description, setDescription] = useState(addon.description ?? "");
  const [price, setPrice] = useState(addon.price_reference ?? "");
  const [active, setActive] = useState(addon.is_active);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setError(null);
    try {
      await api(`/internal/addons/${addon.code}`, {
        method: "PATCH",
        body: { name: name.trim(), description: description.trim() || null, price_reference: price === "" ? null : Number(price), is_active: active },
      });
      onSaved(`Complemento ${name.trim()} guardado.`);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.errors ?? {});
        if (err.type !== "validation_failed") setError(err.message);
      } else setError("No se pudo guardar.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={`Editar ${addon.name}`}
      subtitle={`Complemento ${addon.code} · ${addon.subscriptions_count === 1 ? "1 suscripción lo usa" : `${addon.subscriptions_count} suscripciones lo usan`}`}
      icon="box"
      onClose={onClose}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="addon-form" variant="primary" icon="check" loading={saving}>
            Guardar cambios
          </Button>
        </>
      }
    >
      <form id="addon-form" onSubmit={save} className="space-y-4" noValidate>
        {error && <p className="rounded-lg border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}

        <FormSection icon="tag" title="Información">
          <FormField label="Nombre" error={errors.name?.[0]}>
            <input className="input" value={name} maxLength={60} aria-invalid={!!errors.name || undefined} onChange={(e) => setName(e.target.value)} />
          </FormField>
          <FormField label="Descripción" counter={`${description.length}/500`} error={errors.description?.[0]}>
            <textarea className="input min-h-20" maxLength={500} value={description} onChange={(e) => setDescription(e.target.value)} />
          </FormField>
        </FormSection>

        <FormSection icon="star" title="Precio y disponibilidad">
          <FormField label="Precio de referencia mensual (por empresa)" hint="Se suma al plan de cada empresa que lo contrate. Se usa en «Planes» y «Complementos» del sitio, en las cotizaciones y su PDF; el pago anual aplica el descuento anual. Vacío = «Por definir» (no se puede incluir en una cotización)." error={errors.price_reference?.[0]}>
            <AffixInput prefix="S/" inputMode="decimal" placeholder="Por definir" value={price} invalid={!!errors.price_reference} onChange={(e) => setPrice(e.target.value.replace(",", "."))} />
          </FormField>
          <SwitchRow label="Complemento activo" hint="Si lo desactivas, ya no se puede contratar. Las suscripciones actuales no cambian." checked={active} onChange={setActive} />
        </FormSection>
      </form>
    </Modal>
  );
}
