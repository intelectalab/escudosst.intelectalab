"use client";

// Panel › Proveedores y claves: servicios externos que se contratan aparte (IA, pagos, facturación electrónica,
// WhatsApp, firma digital). Mientras falte un proveedor o sus claves, los módulos que lo usan trabajan en
// «modo de prueba» y lo dicen en pantalla. Las claves se guardan cifradas y nunca se vuelven a mostrar completas.

import { useCallback, useEffect, useState } from "react";
import { usePlatformUser } from "@/components/PlatformContext";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";

type Prov = {
  code: string; name: string; what: string; test_note: string; options: Record<string, string>; fields: Record<string, string>; secret_fields: Record<string, string>;
  provider: string | null; mode: "test" | "live"; settings: Record<string, string>; live: boolean; secrets_set: Record<string, string | null>;
  last_test: { at: string; ok: boolean; message: string } | null;
};

export default function ProveedoresPage() {
  const me = usePlatformUser();
  const owner = me.platform_role === "owner";
  const [items, setItems] = useState<Prov[] | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const load = useCallback(() => {
    api<{ data: Prov[] }>("/internal/providers").then((r) => setItems(r.data));
  }, []);
  useEffect(load, [load]);

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Proveedores y claves"]]}
        icon="plug"
        title="Proveedores y claves"
        description="Servicios que se contratan aparte. Pega aquí sus claves cuando los tengan; mientras tanto, cada módulo funciona en modo de prueba y lo avisa."
      />
      {!owner && <p className="rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">Solo la dueña o el dueño de la plataforma cambia proveedores y claves. Tú puedes verlos.</p>}
      {items === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {items.map((p) => <ProviderCard key={p.code} p={p} canEdit={owner} onSaved={(t) => { setToast(t); load(); }} />)}
        </div>
      )}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={() => setToast(null)} />}
    </div>
  );
}

function ProviderCard({ p, canEdit, onSaved }: { p: Prov; canEdit: boolean; onSaved: (t: { text: string; tone: "ok" | "bad" }) => void }) {
  const [provider, setProvider] = useState(p.provider ?? "");
  const [mode, setMode] = useState<"test" | "live">(p.mode);
  const [settings, setSettings] = useState<Record<string, string>>(p.settings ?? {});
  const [secrets, setSecrets] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { live: boolean } }>(`/internal/providers/${p.code}`, { method: "PUT", body: { provider: provider || null, mode, settings, secrets } });
      setSecrets({});
      onSaved({ text: r.data.live ? `${p.name}: en vivo.` : `${p.name}: guardado (sigue en modo de prueba).`, tone: "ok" });
    } catch (e) {
      const ae = e as ApiError;
      setError(Object.values(ae.errors ?? {})[0]?.[0] ?? ae.detail ?? "No se pudo guardar.");
    } finally {
      setBusy(false);
    }
  }
  async function test() {
    setBusy(true);
    try {
      const r = await api<{ data: { ok: boolean | null; message: string } }>(`/internal/providers/${p.code}/test`, { method: "POST" });
      onSaved({ text: r.data.message, tone: r.data.ok === false ? "bad" : "ok" });
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="font-display text-lg font-semibold">{p.name}</h2>
          <p className="text-sm text-muted">{p.what}</p>
        </div>
        <Chip tone={p.live ? "ok" : "amber"} dot>{p.live ? "En vivo" : "Modo de prueba"}</Chip>
      </div>
      {!p.live && <p className="rounded-lg bg-bg px-3 py-2 text-sm"><b>Mientras tanto:</b> {p.test_note}</p>}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Proveedor">
          <select className="input" value={provider} disabled={!canEdit} onChange={(e) => setProvider(e.target.value)}>
            <option value="">Sin elegir</option>
            {Object.entries(p.options).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <FormField label="Modo">
          <select className="input" value={mode} disabled={!canEdit} onChange={(e) => setMode(e.target.value as "test" | "live")}>
            <option value="test">Prueba (no cobra ni envía de verdad)</option>
            <option value="live">En vivo</option>
          </select>
        </FormField>
        {Object.entries(p.fields).map(([k, label]) => (
          <FormField key={k} label={label}>
            <input className="input" disabled={!canEdit} value={settings[k] ?? ""} onChange={(e) => setSettings({ ...settings, [k]: e.target.value })} />
          </FormField>
        ))}
        {Object.entries(p.secret_fields).map(([k, label]) => (
          <FormField key={k} label={label} hint={p.secrets_set?.[k] ? `Guardada: ${p.secrets_set[k]}. Déjalo vacío para conservarla.` : "Se guarda cifrada."}>
            <input className="input" type="password" autoComplete="off" disabled={!canEdit} value={secrets[k] ?? ""} placeholder={p.secrets_set?.[k] ?? ""}
              onChange={(e) => setSecrets({ ...secrets, [k]: e.target.value })} />
          </FormField>
        ))}
      </div>
      {p.last_test && (
        <p className={`text-xs ${p.last_test.ok ? "text-ok" : "text-bad"}`}>
          Última prueba: {new Date(p.last_test.at).toLocaleString("es-PE", { timeZone: "America/Lima" })} · {p.last_test.message}
        </p>
      )}
      {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      {canEdit && (
        <div className="flex flex-wrap gap-2">
          <Button variant="primary" icon="check" loading={busy} onClick={save}>Guardar</Button>
          <Button variant="secondary" onClick={test} disabled={busy || !p.provider}>Probar conexión</Button>
        </div>
      )}
    </section>
  );
}
