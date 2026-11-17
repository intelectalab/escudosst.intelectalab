"use client";

// Organigrama SST (M-TEN §11): gerencia, Jefe SST, comité o supervisor con sus miembros, médico, brigadas y jefes de área.

import { Facts, Section, Sheet, ReportLoading, cell, useReport } from "@/components/modules/FieldReports";

type Org = {
  company: { legal_name: string; ruc: string; sst_model: string | null };
  management: { full_name: string }[]; sst_managers: { full_name: string }[]; sst_supervisors: { full_name: string }[]; doctors: { full_name: string }[];
  committee: { name: string; committee_type: string; period_end_on: string | null } | null;
  members: { full_name: string; representation: string | null; role_in_committee: string | null; member_type: string | null }[];
  brigades: { name: string; leader: string | null }[];
  area_leads: { area: string; full_name: string }[];
};
const names = (l: { full_name: string }[]) => l.map((x) => x.full_name).join(", ") || "—";
const REP: Record<string, string> = { employer: "Empleador", workers: "Trabajadores" };
const ROLE: Record<string, string> = { president: "Presidente", secretary: "Secretario", member: "Miembro", supervisor: "Supervisor" };

export default function Imprimir() {
  const { data: d, error } = useReport<Org>("/ten/sst-org");
  if (!d) return <ReportLoading error={error} />;
  return (
    <Sheet company={{ name: d.company.legal_name, legal_name: d.company.legal_name, ruc: d.company.ruc }} title="Organigrama de seguridad y salud en el trabajo"
      sub={`Al ${new Date().toLocaleDateString("es-PE")} · Modelo: ${d.company.sst_model === "committee" ? "Comité de SST" : "Supervisor de SST"}`}>
      <Section n={1} title="Responsables">
        <Facts rows={[["Alta dirección", names(d.management)], ["Jefe / responsable del SG-SST", names(d.sst_managers)], ["Supervisores de SST", names(d.sst_supervisors)], ["Médico ocupacional", names(d.doctors)]]} />
      </Section>
      <Section n={2} title={d.committee ? `${d.committee.name}${d.committee.period_end_on ? ` (mandato hasta ${new Date(d.committee.period_end_on).toLocaleDateString("es-PE")})` : ""}` : "Comité o supervisor de SST"}>
        {d.members.length === 0 ? <p className="text-[11px]">Sin miembros activos registrados.</p> : (
          <table className="w-full border-collapse text-[11px]">
            <thead><tr className="bg-gray-100"><th className={cell}>Nombre</th><th className={cell}>Representa a</th><th className={cell}>Cargo</th><th className={cell}>Tipo</th></tr></thead>
            <tbody>{d.members.map((m, i) => (
              <tr key={i}><td className={cell}>{m.full_name}</td><td className={cell}>{m.representation ? REP[m.representation] ?? m.representation : "—"}</td>
                <td className={cell}>{m.role_in_committee ? ROLE[m.role_in_committee] ?? m.role_in_committee : "—"}</td><td className={cell}>{m.member_type === "alternate" ? "Suplente" : "Titular"}</td></tr>
            ))}</tbody>
          </table>
        )}
      </Section>
      <Section n={3} title="Brigadas de emergencia">
        <Facts rows={d.brigades.length ? d.brigades.map((b) => [b.name, b.leader ? `Líder: ${b.leader}` : "Sin líder"] as [string, string]) : [["Brigadas", "Sin brigadas registradas"]]} />
      </Section>
      <Section n={4} title="Jefes de área">
        <Facts rows={d.area_leads.length ? d.area_leads.map((a) => [a.area, a.full_name] as [string, string]) : [["Áreas", "Sin jefes de área registrados"]]} />
      </Section>
    </Sheet>
  );
}
