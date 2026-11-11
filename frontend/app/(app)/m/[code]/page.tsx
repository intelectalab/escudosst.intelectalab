"use client";

// Página de cada módulo: sus registros (motor común) o, si no está en el plan, la pantalla
// «Bloqueado por plan» (docs/07-pantallas-y-ux.md §3.7).

import Link from "next/link";
import { useParams } from "next/navigation";
import { useApp } from "@/components/AppContext";
import { availableIn, MENU_GROUPS, PLAN_NAMES } from "@/lib/menu";
import { Icon } from "@/components/ui/Icon";
import { ModuleRecords } from "@/components/records/ModuleRecords";
import { IpercModule } from "@/components/ipc/IpercModule";
import { Dashboard } from "@/components/dsh/Dashboard";
import { VmdModule } from "@/components/vmd/VmdModule";
import { IaaModule } from "@/components/addons/IaaModule";
import { MyCourses } from "@/components/addons/AulLearner";
import { LbaSummary } from "@/components/modules/LbaSummary";
import { SunSummary } from "@/components/modules/SunSummary";
import { CapMatrix } from "@/components/modules/CapMatrix";
import { FotIssue } from "@/components/modules/FotIssue";
import { RegRegisters } from "@/components/modules/RegRegisters";
import { EstSummary } from "@/components/modules/EstSummary";
import { MapModule } from "@/components/modules/MapModule";
import { ModuleWizards, hasModuleWizard } from "@/components/modules/ModuleWizards";
import { ComModule } from "@/components/modules/ComModule";
import { CtrPortal } from "@/components/modules/CtrPortal";
import { CtrClientPanel } from "@/components/modules/CtrClient";
import { IngSummary } from "@/components/modules/IngSummary";
import { ConsultancyPanel } from "@/components/modules/ConPanel";
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

  // Cabecera plana del prototipo (prototipo/src/app.js encMod): migas, título, descripción y plan.
  const group = MENU_GROUPS.find((g) => g.codes.includes(m.code))?.title;
  const plan = m.required_plan === "+" ? "Complemento" : PLAN_NAMES[m.required_plan] ?? m.required_plan;
  const header = (
    <div className="space-y-1">
      <nav aria-label="Migas de pan" className="text-xs text-muted">
        EscudoSST{group ? ` › ${group}` : ""} › <span className="text-fg">{m.name}</span>
      </nav>
      <h1 className="font-display text-2xl font-bold leading-tight">{m.name}</h1>
      <p className="text-sm text-muted">
        {m.solves}{" "}
        <span className="ml-1 inline-block rounded border border-line bg-surface px-1.5 py-0.5 align-middle text-[11px] font-semibold text-muted" title="Disponible desde">
          {m.number} · {plan}
        </span>
      </p>
    </div>
  );

  if (m.mode === "locked") {
    const isAdmin = company.role.code === "account_admin";
    return (
      <div className="space-y-4">
        {header}
        <Card center>
          <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-bg text-muted" aria-hidden="true">
            <Icon name="lock" size={26} />
          </span>
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
    <div className="space-y-4">
      {m.mode === "read_only" && (
        <div role="status" className="rounded-lg bg-amber-soft px-4 py-3 text-amber-ink">
          Este módulo está en solo lectura. Tus datos se conservan.
        </div>
      )}
      {m.code !== "DSH" && header}
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
          <ConsultancyPanel readOnly={m.mode === "read_only"} />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "CTR" && company.role.code === "contractor_admin" ? (
        <CtrPortal />
      ) : m.code === "COM" || m.code === "CTR" || m.code === "ING" ? (
        <div className="space-y-6">
          {m.code === "COM" ? <ComModule readOnly={m.mode === "read_only"} /> : m.code === "CTR" ? <CtrClientPanel readOnly={m.mode === "read_only"} /> : <IngSummary readOnly={m.mode === "read_only"} />}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "SUN" || m.code === "CAP" || m.code === "FOT" || m.code === "EST" ? (
        <div className="space-y-6">
          {m.code === "SUN" ? <SunSummary readOnly={m.mode === "read_only"} /> : m.code === "CAP" ? <CapMatrix /> : m.code === "FOT" ? <FotIssue readOnly={m.mode === "read_only"} /> : <EstSummary />}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "MAP" ? (
        <div className="space-y-6">
          <MapModule readOnly={m.mode === "read_only"} />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "REG" ? (
        <RegRegisters readOnly={m.mode === "read_only"} />
      ) : m.code === "LBA" ? (
        <div className="space-y-6">
          <LbaSummary />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : hasModuleWizard(m.code) ? (
        <ModuleWizards code={m.code} readOnly={m.mode === "read_only"} />
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
