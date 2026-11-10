"use client";

// Página de cada módulo: sus registros (motor común) o, si no está en el plan, la pantalla
// «Bloqueado por plan» (docs/07-pantallas-y-ux.md §3.7).

import Link from "next/link";
import { useParams } from "next/navigation";
import { useApp } from "@/components/AppContext";
import { availableIn } from "@/lib/menu";
import { ModuleRecords } from "@/components/records/ModuleRecords";
import { IpercModule } from "@/components/ipc/IpercModule";
import { Dashboard } from "@/components/dsh/Dashboard";
import { VmdModule } from "@/components/vmd/VmdModule";
import { IaaModule } from "@/components/addons/IaaModule";
import { MyCourses } from "@/components/addons/AulLearner";
import { WhiteLabelPanel } from "@/components/addons/WhiteLabel";
import { CompanyAudit, CompanyUsers } from "@/components/usr/CompanyAdmin";

export default function ModulePage() {
  const { code } = useParams<{ code: string }>();
  const { modules, company } = useApp();
  const m = modules.find((x) => x.code === code.toUpperCase());

  if (!m || !m.role_can_use || m.mode === "disabled_by_company") {
    return (
      <Card>
        <h1 className="text-xl font-semibold">Página no disponible</h1>
        <p className="text-muted">Este módulo no existe o tu rol no tiene acceso. Si lo necesitas, pídelo al administrador de tu cuenta.</p>
        <Link href="/inicio" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">
          Volver al inicio
        </Link>
      </Card>
    );
  }

  const header = (
    <div className="relative overflow-hidden rounded-2xl bg-gradient-to-r from-[#0d3b6e] to-[#1f7fd1] px-5 py-5 text-white shadow-md sm:px-7">
      <div className="pointer-events-none absolute -right-10 -top-12 h-40 w-40 rounded-full bg-white/10 blur-2xl" aria-hidden="true" />
      <div className="relative flex items-center gap-4">
        <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white/15 font-display text-sm font-bold ring-1 ring-white/25">{m.code}</span>
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-white/70">Módulo {m.number}</p>
          <h1 className="font-display text-2xl font-bold leading-tight">{m.name}</h1>
          {m.solves && <p className="mt-0.5 text-sm text-white/80">{m.solves}</p>}
        </div>
      </div>
    </div>
  );

  if (m.mode === "locked") {
    const isAdmin = company.role.code === "account_admin";
    return (
      <div className="max-w-2xl space-y-4">
        {header}
        <Card center>
          <div className="text-4xl" aria-hidden="true">
            🔒
          </div>
          <h2 className="text-xl font-semibold">Disponible en {availableIn(m.required_plan, m.name)}</h2>
          <p className="text-muted">
            Tu empresa no tiene este módulo en su plan.{" "}
            {isAdmin ? "Para agregarlo, contacta al equipo de EscudoSST." : "Si lo necesitas, pídelo al administrador de tu cuenta."}
          </p>
          <a href={m.required_plan === "+" ? "/complementos" : "/planes"} target="_blank" rel="noreferrer" className="inline-block font-semibold text-brand hover:underline">
            {m.required_plan === "+" ? "Ver complementos y precios ↗" : "Comparar planes y precios ↗"}
          </a>
          <p className="rounded-lg bg-bg px-4 py-2 text-sm text-muted">Si un día bajas de plan, tus datos no se borran: quedan en solo lectura.</p>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {m.mode === "read_only" && (
        <div role="status" className="rounded-lg bg-amber-soft px-4 py-3 text-amber-ink">
          Este módulo está en solo lectura. Tus datos se conservan.
        </div>
      )}
      {header}
      {m.code === "IAA" ? (
        <IaaModule />
      ) : m.code === "IPC" ? (
        <IpercModule readOnly={m.mode === "read_only"} />
      ) : m.code === "DSH" ? (
        <Dashboard />
      ) : m.code === "USR" ? (
        <CompanyUsers readOnly={m.mode === "read_only"} />
      ) : m.code === "VMD" ? (
        <VmdModule readOnly={m.mode === "read_only"} />
      ) : m.code === "AUD" ? (
        <CompanyAudit />
      ) : m.code === "CON" ? (
        <div className="space-y-5">
          <WhiteLabelPanel />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "AUL" ? (
        <div className="space-y-5">
          <MyCourses />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : (
        <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
      )}
    </div>
  );
}

function Card({ children, center }: { children: React.ReactNode; center?: boolean }) {
  return <div className={`space-y-3 rounded-xl border border-line bg-surface p-8 ${center ? "text-center" : ""}`}>{children}</div>;
}
