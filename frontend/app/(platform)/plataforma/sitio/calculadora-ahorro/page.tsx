"use client";

// Sitio web › Calculadora de ahorro (SCR-WEB-83): supuestos de /herramientas/calculadora-ahorro. Empiezan vacíos:
// hasta definirlos y publicarlos, la página pública dice que aún no está disponible.

import { FormEvent, useEffect, useState } from "react";
import { dt, Guide, usePanelList, useSave } from "@/components/platform/SitePanelKit";
import { Button } from "@/components/ui/Button";
import { Chip, PageHeader, StatCard } from "@/components/ui/Display";
import { AffixInput, FormField, SwitchRow } from "@/components/ui/Form";
import { Toast } from "@/components/ui/Toast";
import { api } from "@/lib/api";

type Data = {
  assumptions: { minutes_saved_per_record: number | null; manual_hours_reduction_pct: number | null; hourly_cost_pen: number | null };
  disclaimer: string;
  status: "draft" | "published";
  to_validate: boolean;
  updated_at: string;
  stats: { total: number; last_30: number; with_email: number; avg_hours: string | null };
};

const str = (v: number | null) => (v === null || v === undefined ? "" : String(v));
const num = (v: string) => (v.trim() === "" ? null : Number(v));

export default function CalculadoraAhorroPage() {
  const { res, error, load } = usePanelList<{ data: Data }>("/internal/site/savings-calculator");
  const d = res?.data;
  const [f, setF] = useState({ minutes: "", pct: "", cost: "", disclaimer: "", to_validate: true });
  const [toast, setToast] = useState<string | null>(null);
  const { message, saving, run, err } = useSave();

  useEffect(() => {
    if (d) setF({ minutes: str(d.assumptions.minutes_saved_per_record), pct: str(d.assumptions.manual_hours_reduction_pct), cost: str(d.assumptions.hourly_cost_pen), disclaimer: d.disclaimer, to_validate: d.to_validate });
  }, [d]);

  async function save(e: FormEvent) {
    e.preventDefault();
    const r = await run(() =>
      api("/internal/site/savings-calculator", {
        method: "PUT",
        body: { minutes_saved_per_record: num(f.minutes), manual_hours_reduction_pct: num(f.pct), hourly_cost_pen: num(f.cost), disclaimer: f.disclaimer, to_validate: f.to_validate },
      }),
    );
    if (r !== null) {
      setToast("Supuestos guardados.");
      load();
    }
  }
  async function publish(on: boolean) {
    const r = await run(() => api("/internal/site/savings-calculator/publish", { method: "POST", body: { publish: on } }));
    if (r !== null) {
      setToast(on ? "Calculadora publicada en el sitio." : "Calculadora quitada del sitio.");
      load();
    }
  }

  // Ejemplo con los supuestos escritos: 100 registros al mes y 20 horas manuales.
  const m = num(f.minutes);
  const p = num(f.pct);
  const c = num(f.cost);
  const example = m !== null && p !== null ? Math.min(20, (100 * m) / 60 + (20 * p) / 100) : null;

  return (
    <div className="w-full space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Sitio web", "/plataforma/sitio"], ["Calculadora de ahorro"]]}
        icon="percent"
        title="Calculadora de ahorro"
        description="Estima cuántas horas al mes ahorraría una empresa frente a Excel y papel. El resultado depende de los supuestos que pongas aquí, que se muestran junto al resultado."
        aside={
          d && (
            <Chip tone={d.status === "published" ? "ok" : "na"} dot>
              {d.status === "published" ? "Publicada" : "No publicada"}
            </Chip>
          )
        }
      />
      <Guide
        what="Herramienta gratis donde una empresa calcula cuántas horas al mes ahorraría usando EscudoSST en vez de Excel y papel. Quien deja su correo recibe el resultado y queda en «Interesados»."
        where="En /herramientas/calculadora-ahorro y en el pie del sitio (cuando está publicada)."
        steps={["Escribe los supuestos: minutos que se ahorran por registro y % de horas manuales que se reducen. El costo por hora es opcional.", "Revisa el ejemplo que se calcula abajo.", "«Publicar»."]}
      />
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{error}</p>}
      {d && (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Cálculos hechos" value={d.stats.total} icon="clock" />
            <StatCard label="Últimos 30 días" value={d.stats.last_30} icon="history" />
            <StatCard label="Dejaron su correo" value={d.stats.with_email} icon="mail" tone="ok" />
            <StatCard label="Ahorro promedio" value={d.stats.avg_hours ? `${d.stats.avg_hours} h/mes` : "—"} icon="star" tone="amber" />
          </div>
          <form onSubmit={save} noValidate className="form-section space-y-4">
            {message && <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm font-semibold text-bad">{message}</p>}
            <h2 className="font-display text-base font-bold">Supuestos</h2>
            <p className="text-sm text-muted">
              Fórmula: <b>horas ahorradas = registros al mes × minutos por registro ÷ 60 + horas manuales × % de reducción</b> (nunca más que las horas manuales que la empresa indicó).
            </p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <FormField label="Minutos ahorrados por registro" hint="Por cada registro (capacitación, entrega de EPP, inspección…) hecho en el sistema en vez de a mano." error={err("minutes_saved_per_record")}>
                <AffixInput suffix="min" inputMode="decimal" value={f.minutes} onChange={(e) => setF({ ...f, minutes: e.target.value })} />
              </FormField>
              <FormField label="Reducción de horas manuales" hint="Qué parte de las horas que hoy dedican al SST se ahorra (armar reportes, buscar papeles…)." error={err("manual_hours_reduction_pct")}>
                <AffixInput suffix="%" inputMode="decimal" value={f.pct} onChange={(e) => setF({ ...f, pct: e.target.value })} />
              </FormField>
              <FormField label="Costo por hora (opcional)" hint="Para mostrar el ahorro en soles. Vacío = solo horas." error={err("hourly_cost_pen")}>
                <AffixInput prefix="S/" inputMode="decimal" value={f.cost} onChange={(e) => setF({ ...f, cost: e.target.value })} />
              </FormField>
            </div>
            {example !== null && (
              <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">
                Ejemplo: una empresa con 100 registros al mes y 20 horas manuales ahorraría <b className="text-fg">{example.toFixed(1)} h al mes</b>
                {c !== null ? ` (S/ ${(Math.round(example * 10) / 10 * c).toFixed(2)})` : ""}.
              </p>
            )}
            <FormField label="Aviso que acompaña el resultado" hint="Debe dejar claro que es una estimación referencial, no garantizada." counter={`${f.disclaimer.length}/500`} error={err("disclaimer")}>
              <textarea className="input min-h-[70px]" maxLength={500} value={f.disclaimer} onChange={(e) => setF({ ...f, disclaimer: e.target.value })} />
            </FormField>
            <SwitchRow label="Supuestos «a validar»" hint="Se muestra el rótulo «supuestos a validar» junto al resultado hasta que los confirme un especialista." checked={f.to_validate} onChange={(v) => setF({ ...f, to_validate: v })} />
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" type="submit" icon="check" loading={saving}>
                Guardar supuestos
              </Button>
              {d.status === "published" ? (
                <Button onClick={() => publish(false)} disabled={saving}>
                  Quitar del sitio
                </Button>
              ) : (
                <Button icon="globe" onClick={() => publish(true)} disabled={saving}>
                  Publicar
                </Button>
              )}
            </div>
            <p className="text-xs text-muted">Último cambio: {dt(d.updated_at)}. Guarda antes de publicar.</p>
          </form>
        </>
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}
