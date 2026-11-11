"use client";

// Resumen del módulo Ingreso por QR (M-ING): indicadores de hoy, acceso a la portería (pantalla para tablet o
// celular) y «Quiénes están dentro» (útil en emergencias y simulacros). Va sobre los registros del módulo.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { PermissionsResponse } from "@/lib/types";
import { Icon } from "@/components/ui/Icon";
import { InsideList, type InsidePerson } from "./IngGate";
import { Kpi } from "./Summary";

type Kpis = { granted_today: number; denied_today: number; overridden_today: number; inside: number };

export function IngSummary({ readOnly }: { readOnly?: boolean }) {
  const [people, setPeople] = useState<InsidePerson[] | null>(null);
  const [kpis, setKpis] = useState<Kpis | null>(null);
  const [canOperate, setCanOperate] = useState(false);

  const load = useCallback(() => {
    api<{ data: { people: InsidePerson[]; kpis: Kpis } }>("/ing/inside", { withCompany: true })
      .then((r) => {
        setPeople(r.data.people);
        setKpis(r.data.kpis);
      })
      .catch(() => setPeople([]));
  }, []);

  useEffect(() => {
    load();
    api<PermissionsResponse>("/me/permissions", { withCompany: true })
      .then((r) => setCanOperate(r.data.includes("ing.access_events.create")))
      .catch(() => setCanOperate(false));
  }, [load]);

  const operate = canOperate && !readOnly;
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Ingresos hoy" value={String(kpis?.granted_today ?? "—")} color="text-ok" />
        <Kpi label="No habilitados hoy" value={String(kpis?.denied_today ?? "—")} color="text-bad" />
        <Kpi label="Por excepción hoy" value={String(kpis?.overridden_today ?? "—")} color="text-amber-ink" />
        <Kpi label="Dentro ahora" value={String(kpis?.inside ?? "—")} />
      </div>
      {operate && (
        <Link href="/garita" className="flex h-12 items-center justify-center gap-2 rounded-xl bg-brand px-5 text-base font-semibold text-white hover:bg-brand-strong sm:inline-flex">
          <Icon name="idcard" size={20} /> Abrir portería (escanear QR)
        </Link>
      )}
      {people === null ? (
        <div className="h-24 animate-pulse rounded-xl bg-surface" />
      ) : (
        <InsideList people={people} onExit={operate ? async (p) => {
          await api("/ing/gate/exit", { method: "POST", withCompany: true, body: { event_id: p.event_id } }).catch(() => undefined);
          load();
        } : undefined} />
      )}
    </div>
  );
}
