"use client";

// Página de cada módulo. Mientras el módulo no se construye muestra «En preparación»;
// si no está en el plan, la pantalla «Bloqueado por plan» (docs/07-pantallas-y-ux.md §3.7).

import Link from "next/link";
import { useParams } from "next/navigation";
import { useApp } from "@/components/AppContext";
import { availableIn, PLAN_NAMES } from "@/lib/menu";

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
    <div className="space-y-1">
      <p className="text-sm text-muted">
        {m.number} · {m.code}
      </p>
      <h1 className="text-2xl font-semibold">{m.name}</h1>
      {m.solves && <p className="text-muted">{m.solves}</p>}
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
          {m.required_plan in PLAN_NAMES && m.required_plan !== "+" && (
            <p className="text-sm text-muted">Precio: S/ —</p>
          )}
          <p className="rounded-lg bg-bg px-4 py-2 text-sm text-muted">Si un día bajas de plan, tus datos no se borran: quedan en solo lectura.</p>
        </Card>
      </div>
    );
  }

  return (
    <div className="max-w-3xl space-y-4">
      {m.mode === "read_only" && (
        <div role="status" className="rounded-lg bg-amber-soft px-4 py-3 text-amber-ink">
          Este módulo está en solo lectura. Tus datos se conservan.
        </div>
      )}
      {header}
      <Card center>
        <div className="text-4xl" aria-hidden="true">
          🛠️
        </div>
        <h2 className="text-lg font-semibold">Pantalla en preparación</h2>
        <p className="text-muted">Este módulo está incluido en tu plan y se construirá en las próximas etapas.</p>
      </Card>
    </div>
  );
}

function Card({ children, center }: { children: React.ReactNode; center?: boolean }) {
  return <div className={`space-y-3 rounded-xl border border-line bg-surface p-8 ${center ? "text-center" : ""}`}>{children}</div>;
}
