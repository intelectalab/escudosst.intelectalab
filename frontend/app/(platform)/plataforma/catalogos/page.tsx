"use client";

// SCR-SUS-08 · Catálogos globales (docs/03 §11): datos de la plataforma que usan todas las empresas.
// Lo que valida un especialista SST/legal (parámetros, normas y sus requisitos, catálogo SCAT) lo cambia solo el Dueño;
// Soporte lo ve.

import { useCallback, useEffect, useState } from "react";
import { HazardsTab, HolidaysTab, NormsTab, PacksTab } from "@/components/catalogs/OtherTabs";
import { ParametersTab } from "@/components/catalogs/ParametersTab";
import { ScatTab } from "@/components/catalogs/ScatTab";
import { usePlatformUser } from "@/components/PlatformContext";
import { PageHeader, StatCard } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import type { Hazard, Holiday, LegalNorm, LegalParameter, ScatItem, SectorPack } from "@/lib/catalogs";

type Tab = "parameters" | "norms" | "hazards" | "scat" | "packs" | "holidays";
type Data = { parameters: LegalParameter[]; norms: LegalNorm[]; hazards: Hazard[]; scat: ScatItem[]; packs: SectorPack[]; holidays: Holiday[] };

const TABS: [Tab, string, string][] = [
  ["parameters", "Parámetros legales", "shield"],
  ["norms", "Normas", "file"],
  ["hazards", "Peligros base", "lock"],
  ["scat", "Catálogo SCAT", "checklist"],
  ["packs", "Paquetes sectoriales", "box"],
  ["holidays", "Feriados", "clock"],
];

export default function CatalogosPage() {
  const owner = usePlatformUser().platform_role === "owner";
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>("parameters");
  const [paramFilter, setParamFilter] = useState("");
  const [toast, setToast] = useState<string | null>(null);
  const closeToast = useCallback(() => setToast(null), []);

  const load = useCallback(() => {
    const get = <T,>(path: string) => api<{ data: T }>(`/internal/catalogs/${path}`).then((r) => r.data);
    Promise.all([
      get<LegalParameter[]>("legal-parameters"),
      get<LegalNorm[]>("legal-norms"),
      get<Hazard[]>("hazards"),
      get<ScatItem[]>("scat"),
      get<SectorPack[]>("sector-packs"),
      get<Holiday[]>("holidays"),
    ])
      .then(([parameters, norms, hazards, scat, packs, holidays]) => setData({ parameters, norms, hazards, scat, packs, holidays }))
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar los catálogos."));
  }, []);
  useEffect(load, [load]);

  const changed = (msg: string) => {
    setToast(msg);
    load();
  };

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!data) return <div className="mx-auto h-96 max-w-7xl animate-pulse rounded-2xl bg-line/40" aria-label="Cargando catálogos" />;

  const current = data.parameters.filter((p) => p.is_current);
  const toConfirm = current.filter((p) => p.to_confirm && p.kind === "legal").length;
  const legalCount = current.filter((p) => p.kind === "legal").length;
  const pending = current.filter((p) => p.kind === "pending").length;
  const year = String(new Date().getFullYear());
  const holidaysYear = data.holidays.filter((h) => h.holiday_on.startsWith(year)).length;
  const go = (t: Tab, filter = "") => {
    setParamFilter(filter);
    setTab(t);
  };

  return (
    <div className="mx-auto w-full max-w-[1600px] space-y-6">
      <PageHeader crumbs={[["Panel de la plataforma"], ["Catálogos globales"]]} icon="box" title="Catálogos globales" description="Datos legales y técnicos que usan todas las empresas." />

      <section aria-label="Resumen" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Por confirmar" value={toConfirm} hint={`de ${legalCount} valores legales · ${pending} por definir`} icon="shield" tone="amber" active={tab === "parameters" && paramFilter === "to_confirm"} onClick={() => go("parameters", paramFilter === "to_confirm" ? "" : "to_confirm")} />
        <StatCard label="Normas legales" value={data.norms.length} hint={`${data.norms.filter((n) => n.status === "current").length} vigentes`} icon="file" active={tab === "norms"} onClick={() => go("norms")} />
        <StatCard label="Peligros base" value={data.hazards.filter((h) => h.is_active).length} hint="Para la matriz IPERC" icon="lock" tone="bad" active={tab === "hazards"} onClick={() => go("hazards")} />
        <StatCard label={`Feriados ${year}`} value={holidaysYear} hint={`${data.holidays.filter((h) => h.to_confirm).length} por confirmar`} icon="clock" tone="ok" active={tab === "holidays"} onClick={() => go("holidays")} />
      </section>

      <div role="tablist" aria-label="Catálogos" className="flex w-full overflow-x-auto rounded-xl border border-line bg-surface p-1 shadow-sm">
        {TABS.map(([k, label, icon]) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => go(k)}
            className={`inline-flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${tab === k ? "bg-brand text-white shadow-sm" : "text-muted hover:text-fg"}`}
          >
            <Icon name={icon} size={15} />
            {label}
          </button>
        ))}
      </div>

      {tab === "parameters" && (
        <ParametersTab key={paramFilter} params={data.parameters} hazards={data.hazards} canEdit={owner} onChanged={changed} initialState={paramFilter} onGoHolidays={() => go("holidays")} />
      )}
      {tab === "norms" && <NormsTab norms={data.norms} canEdit={owner} onChanged={changed} />}
      {tab === "hazards" && <HazardsTab hazards={data.hazards} onChanged={changed} />}
      {tab === "scat" && <ScatTab items={data.scat} canEdit={owner} onChanged={changed} />}
      {tab === "packs" && <PacksTab packs={data.packs} onChanged={changed} />}
      {tab === "holidays" && <HolidaysTab holidays={data.holidays} onChanged={changed} />}

      {toast && <Toast message={toast} onClose={closeToast} />}
    </div>
  );
}
