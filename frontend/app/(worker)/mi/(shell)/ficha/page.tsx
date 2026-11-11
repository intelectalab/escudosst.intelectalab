"use client";

// Mi ficha (M-TRB SCR-TRB-06): tus datos y tu puesto; puedes cambiar tu celular y tu contacto de emergencia.

import { useState } from "react";
import { Alert, BigButton, Panel, Title } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { errorText, fmtDate, workerApi } from "@/lib/workerApp";

export default function MyProfilePage() {
  const { home, reload } = useWorker();
  const w = home.worker;
  const [phone, setPhone] = useState(w.phone ?? "");
  const [ecName, setEcName] = useState(w.emergency_contact_name ?? "");
  const [ecPhone, setEcPhone] = useState(w.emergency_contact_phone ?? "");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  async function save() {
    setBusy(true);
    setMsg(null);
    try {
      await workerApi("/profile", { method: "PATCH", body: { phone, emergency_contact_name: ecName, emergency_contact_phone: ecPhone } });
      await reload();
      setMsg({ ok: true, text: "Datos guardados." });
    } catch (e) {
      setMsg({ ok: false, text: errorText(e) });
    } finally {
      setBusy(false);
    }
  }

  const input = "h-12 w-full rounded-xl border border-line bg-surface px-4 text-base focus:border-brand focus:outline-none";
  return (
    <>
      <Title>Mi ficha</Title>
      <Panel>
        <dl className="grid gap-3 text-sm">
          {[["Nombre", w.full_name], ["Documento", w.document_masked], ["Puesto", w.position], ["Área", w.area], ["Sede", w.site], ["Ingreso", w.hire_on ? fmtDate(w.hire_on) : null]]
            .map(([k, v]) => (
              <div key={k as string}>
                <dt className="text-xs text-muted">{k}</dt>
                <dd className="font-medium">{v || "—"}</dd>
              </div>
            ))}
        </dl>
        <p className="text-xs text-muted">¿Algún dato está mal? Pide la corrección al área de Recursos Humanos.</p>
      </Panel>
      <Panel title="Mis datos de contacto">
        <label htmlFor="pf-phone" className="block space-y-1">
          <span className="text-sm font-semibold">Mi celular</span>
          <input id="pf-phone" type="tel" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} className={input} />
        </label>
        <label htmlFor="pf-ecn" className="block space-y-1">
          <span className="text-sm font-semibold">Contacto de emergencia</span>
          <input id="pf-ecn" value={ecName} onChange={(e) => setEcName(e.target.value)} className={input} />
        </label>
        <label htmlFor="pf-ecp" className="block space-y-1">
          <span className="text-sm font-semibold">Celular del contacto</span>
          <input id="pf-ecp" type="tel" inputMode="tel" value={ecPhone} onChange={(e) => setEcPhone(e.target.value)} className={input} />
        </label>
        {msg && <Alert tone={msg.ok ? "ok" : "bad"}>{msg.text}</Alert>}
        <BigButton onClick={save} loading={busy}>Guardar</BigButton>
      </Panel>
    </>
  );
}
