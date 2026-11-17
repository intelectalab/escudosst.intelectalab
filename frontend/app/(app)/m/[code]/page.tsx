"use client";

// Página de cada módulo: sus registros (motor común) o, si no está en el plan, la pantalla
// «Bloqueado por plan» (docs/07-pantallas-y-ux.md §3.7).

import { useState } from "react";
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
import { AulLanding } from "@/components/modules/AulAdmin";
import { LbaSummary } from "@/components/modules/LbaSummary";
import { SunSummary } from "@/components/modules/SunSummary";
import { CapMatrix } from "@/components/modules/CapMatrix";
import { PcaOverview } from "@/components/modules/PcaOverview";
import { FotIssue } from "@/components/modules/FotIssue";
import { RegRegisters } from "@/components/modules/RegRegisters";
import { hasModuleReports, ModuleReports } from "@/components/modules/ModuleReports";
import { SctReconcile } from "@/components/modules/SctReconcile";
import { Ch5Talks } from "@/components/modules/Ch5Talks";
import { EmoProtocols } from "@/components/modules/EmoProtocols";
import { SimRun } from "@/components/modules/SimRun";
import { AtsCopy } from "@/components/modules/AtsCopy";
import { MagFollowUp, MagTools } from "@/components/modules/MagTools";
import { ErgoSummary, PsiInstruments, Rm375Form, RulaForm } from "@/components/modules/PsiTools";
import { ObsModule } from "@/components/modules/ObsModule";
import { RebaForm } from "@/components/modules/RebaForm";
import { PolOverview } from "@/components/modules/PolOverview";
import { PreExecution } from "@/components/modules/PreExecution";
import { EppPanel, InsDashboard } from "@/components/modules/FieldPanels";
import { RisOverview } from "@/components/modules/RisOverview";
import { PlaWizard } from "@/components/modules/PlaWizard";
import { AuiTools } from "@/components/modules/AuiTools";
import { RvdSummary } from "@/components/modules/RvdSummary";
import { EstTools } from "@/components/modules/EstTools";
import { EqeCalendar } from "@/components/modules/EqeCalendar";
import { HomRatings } from "@/components/modules/HomViews";
import { EstSummary } from "@/components/modules/EstSummary";
import { MapModule } from "@/components/modules/MapModule";
import { ModuleWizards, hasModuleWizard } from "@/components/modules/ModuleWizards";
import { ComModule } from "@/components/modules/ComModule";
import { CtrPortal } from "@/components/modules/CtrPortal";
import { CtrClientPanel } from "@/components/modules/CtrClient";
import { IngSummary } from "@/components/modules/IngSummary";
import { ConsultancyPanel } from "@/components/modules/ConPanel";
import { ConsultancyLink } from "@/components/modules/ConMatrix";
import { CompanyAudit, CompanyUsers } from "@/components/usr/CompanyAdmin";
import { DocModule } from "@/components/modules/DocModule";
import { PasSchedule } from "@/components/modules/PasSchedule";
import { PasTools } from "@/components/modules/PasTools";
import { FirTools } from "@/components/modules/FirTools";
import { VulModule } from "@/components/modules/VulModule";
import { DesModule } from "@/components/modules/DesModule";
import { MrlModule } from "@/components/modules/MrlCatalog";

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
      {hasModuleReports(m.code) && <ModuleReports code={m.code} />}
      {m.code === "IAA" ? (
        <IaaModule />
      ) : m.code === "IPC" ? (
        <IpercModule readOnly={m.mode === "read_only"} />
      ) : m.code === "DSH" ? (
        <Dashboard />
      ) : m.code === "USR" ? (
        <div className="space-y-5">
          <ConsultancyLink />
          <CompanyUsers readOnly={m.mode === "read_only"} />
        </div>
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
          {m.code === "COM" ? <ComModule readOnly={m.mode === "read_only"} /> : m.code === "CTR" ? <><CtrClientPanel readOnly={m.mode === "read_only"} /><HomRatings /></> : <IngSummary readOnly={m.mode === "read_only"} />}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "SUN" || m.code === "CAP" || m.code === "FOT" || m.code === "EST" || m.code === "PCA" ? (
        <div className="space-y-6">
          {m.code === "SUN" ? <SunSummary readOnly={m.mode === "read_only"} /> : m.code === "CAP" ? <CapMatrix /> : m.code === "PCA" ? <PcaOverview /> : m.code === "FOT" ? <FotIssue readOnly={m.mode === "read_only"} /> : <><EstSummary /><EstTools readOnly={m.mode === "read_only"} onSaved={() => undefined} /></>}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "MAP" ? (
        <div className="space-y-6">
          <MapModule readOnly={m.mode === "read_only"} />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "SCT" ? (
        <div className="space-y-6">
          <SctReconcile readOnly={m.mode === "read_only"} />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "EMO" ? (
        <div className="space-y-6">
          <details className="rounded-xl border border-line bg-surface">
            <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Protocolos por puesto</summary>
            <div className="border-t border-line p-4"><EmoProtocols readOnly={m.mode === "read_only"} /></div>
          </details>
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "SIM" ? (
        <SimPage readOnly={m.mode === "read_only"} />
      ) : m.code === "ATS" ? (
        <AtsPage readOnly={m.mode === "read_only"} />
      ) : m.code === "MAG" ? (
        <MagPage readOnly={m.mode === "read_only"} />
      ) : m.code === "EQE" ? (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface p-4 text-sm">
            <span className="flex-1">Imprime el QR de cada equipo y pégalo en él: al escanearlo (con la sesión iniciada) se abre su inspección con el checklist.</span>
            <a href="/imprimir/qr-equipos" target="_blank" rel="noreferrer" className="inline-flex h-9 items-center rounded-lg border border-line px-3 font-semibold hover:border-brand hover:text-brand-strong">Imprimir QR de los equipos</a>
          </div>
          <EqeCalendar />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "OBS" ? (
        <ObsPage readOnly={m.mode === "read_only"} />
      ) : m.code === "PSI" ? (
        <PsiPage readOnly={m.mode === "read_only"} />
      ) : m.code === "POL" ? (
        <div className="space-y-6">
          <PolOverview readOnly={m.mode === "read_only"} />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "EPP" || m.code === "INS" ? (
        <div className="space-y-6">
          {m.code === "EPP" ? <EppPanel readOnly={m.mode === "read_only"} /> : <InsDashboard />}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "PRE" || m.code === "RIS" ? (
        <div className="space-y-6">
          {m.code === "PRE" ? <PreExecution readOnly={m.mode === "read_only"} /> : <RisOverview readOnly={m.mode === "read_only"} />}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "PLA" ? (
        <PlaPage readOnly={m.mode === "read_only"} />
      ) : m.code === "AUI" ? (
        <AuiPage readOnly={m.mode === "read_only"} />
      ) : m.code === "RVD" ? (
        <div className="space-y-6">
          <RvdSummary />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "CH5" ? (
        <Ch5Page readOnly={m.mode === "read_only"} />
      ) : m.code === "MRL" ? (
        <MrlModule readOnly={m.mode === "read_only"} />
      ) : m.code === "REG" ? (
        <RegRegisters readOnly={m.mode === "read_only"} />
      ) : m.code === "LBA" ? (
        <div className="space-y-6">
          <LbaSummary />
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "VUL" || m.code === "DES" ? (
        <div className="space-y-6">
          {m.code === "VUL" ? <VulModule readOnly={m.mode === "read_only"} /> : <DesModule />}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : m.code === "DOC" || m.code === "PAS" || m.code === "FIR" ? (
        <div className="space-y-6">
          {m.code === "DOC" ? <DocModule readOnly={m.mode === "read_only"} /> : m.code === "PAS" ? <><PasTools readOnly={m.mode === "read_only"} /><PasSchedule readOnly={m.mode === "read_only"} /></> : <FirTools readOnly={m.mode === "read_only"} />}
          <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
        </div>
      ) : hasModuleWizard(m.code) ? (
        <ModuleWizards code={m.code} readOnly={m.mode === "read_only"} />
      ) : m.code === "AUL" ? (
        ["worker", "external_learner"].includes(company.role.code) ? (
          <div className="space-y-5">
            <MyCourses />
            <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
          </div>
        ) : (
          <div className="space-y-5">
            <AulLanding readOnly={m.mode === "read_only"} />
            <details className="rounded-xl border border-line bg-surface">
              <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Todos los registros</summary>
              <div className="border-t border-line p-4">
                <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
              </div>
            </details>
          </div>
        )
      ) : (
        <ModuleRecords code={m.code} readOnly={m.mode === "read_only"} />
      )}
    </div>
  );
}

function Card({ children, center }: { children: React.ReactNode; center?: boolean }) {
  return <div className={`space-y-3 rounded-xl border border-line bg-surface p-8 ${center ? "text-center" : ""}`}>{children}</div>;
}

/** M-CH5: registro rápido y cobertura por área encima de la lista de charlas (la lista se recarga al registrar). */
function Ch5Page({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      <Ch5Talks readOnly={readOnly} onSaved={() => setV((x) => x + 1)} />
      <ModuleRecords key={v} code="CH5" readOnly={readOnly} />
    </div>
  );
}

/** M-SIM: ejecutar simulacro (cronómetro y asistencia) encima de la lista; la lista se recarga al guardar. */
function SimPage({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      <SimRun readOnly={readOnly} onSaved={() => setV((x) => x + 1)} />
      <ModuleRecords key={v} code="SIM" readOnly={readOnly} />
    </div>
  );
}

/** M-ATS: copiar pasos a un ATS en borrador encima de la lista. */
function AtsPage({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      {!readOnly && <AtsCopy onSaved={() => setV((x) => x + 1)} />}
      <ModuleRecords key={v} code="ATS" readOnly={readOnly} />
    </div>
  );
}

/** M-MAG: carga de mediciones desde Excel y tendencia por punto encima de la lista. */
function MagPage({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      <MagTools readOnly={readOnly} onSaved={() => setV((x) => x + 1)} />
      <MagFollowUp />
      <ModuleRecords key={v} code="MAG" readOnly={readOnly} />
    </div>
  );
}

/** M-OBS: observar, tendencias y lista de comportamientos encima de la lista de observaciones. */
function ObsPage({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      <ObsModule readOnly={readOnly} onSaved={() => setV((x) => x + 1)} />
      <ModuleRecords key={v} code="OBS" readOnly={readOnly} />
    </div>
  );
}

/** M-PSI: formulario REBA con cálculo encima de la lista de evaluaciones y encuestas. */
function PsiPage({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      <details className="rounded-xl border border-line bg-surface">
        <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Nueva evaluación REBA (con cálculo)</summary>
        <div className="border-t border-line p-4"><RebaForm readOnly={readOnly} onSaved={() => setV((x) => x + 1)} /></div>
      </details>
      <details className="rounded-xl border border-line bg-surface">
        <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Nueva evaluación RULA (con cálculo)</summary>
        <div className="border-t border-line p-4"><RulaForm readOnly={readOnly} onSaved={() => setV((x) => x + 1)} /></div>
      </details>
      <details className="rounded-xl border border-line bg-surface">
        <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Lista de verificación R.M. 375-2008-TR</summary>
        <div className="border-t border-line p-4"><Rm375Form readOnly={readOnly} onSaved={() => setV((x) => x + 1)} /></div>
      </details>
      <ErgoSummary key={`e${v}`} />
      <PsiInstruments readOnly={readOnly} />
      <ModuleRecords key={v} code="PSI" readOnly={readOnly} />
    </div>
  );
}

/** M-PLA: planes del año con indicadores y asistente, encima de la lista de planes. */
function PlaPage({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      <PlaWizard readOnly={readOnly} onSaved={() => setV((x) => x + 1)} />
      <ModuleRecords key={v} code="PLA" readOnly={readOnly} />
    </div>
  );
}

/** M-AUI: programa anual, preparación y herramientas por auditoría encima de la lista. */
function AuiPage({ readOnly }: { readOnly: boolean }) {
  const [v, setV] = useState(0);
  return (
    <div className="space-y-6">
      <AuiTools readOnly={readOnly} onSaved={() => setV((x) => x + 1)} />
      <ModuleRecords key={v} code="AUI" readOnly={readOnly} />
    </div>
  );
}
