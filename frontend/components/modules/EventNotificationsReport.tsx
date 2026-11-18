"use client";

// Constancias de notificación del evento (M-ACC §11, SCR-ACC-04): a quién se notificó, plazo, fecha, número de constancia,
// canal y quién lo registró. Se guarda como PDF desde el diálogo de impresión.

import { cell, Facts, fmtDT, ReportLoading, Section, Sheet, useReport, type Company } from "./FieldReports";

type Row = { authority: string; notification_type: string; deadline_at: string | null; notified_at: string | null; notification_number: string | null; channel: string | null;
  status: string; notes: string | null; has_receipt: boolean; notified_by: string | null };
type Report = { company: Company; event: { code: string; event_type: string; occurred_at: string; site: string | null; description: string | null }; notifications: Row[] };

const AUTH: Record<string, string> = { mtpe_sat: "MTPE (SAT)", sunafil: "SUNAFIL", essalud: "EsSalud", eps: "EPS", insurer_sctr: "Aseguradora del SCTR", osinergmin: "Osinergmin",
  medical_center: "Centro asistencial", principal_company: "Empresa principal", other: "Otro" };
const TYPE: Record<string, string> = { initial: "Inicial", update: "Actualización", final: "Final" };
const CHANNEL: Record<string, string> = { sat_online: "SAT en línea", in_person: "Presencial", email: "Correo", other: "Otro" };
const STATUS: Record<string, string> = { pending: "Pendiente", sent: "Enviada", acknowledged: "Con cargo de recepción", not_required: "No requerida", overdue: "Vencida" };
const EVENT: Record<string, string> = { fatal_accident: "Accidente mortal", disabling_accident: "Accidente incapacitante", minor_accident: "Accidente leve",
  dangerous_incident: "Incidente peligroso", incident: "Incidente", occupational_disease: "Enfermedad ocupacional" };

export function EventNotificationsReport({ id }: { id: string }) {
  const { data, error } = useReport<Report>(`/reports/sst_events/${id}/notifications`);
  if (!data) return <ReportLoading error={error} />;
  const e = data.event;
  return (
    <Sheet company={data.company} title="Constancias de notificación" sub={`${e.code} · ${EVENT[e.event_type] ?? e.event_type}`}>
      <Section n={1} title="Evento">
        <Facts rows={[["Código", e.code], ["Tipo", EVENT[e.event_type] ?? e.event_type], ["Fecha y hora", fmtDT(e.occurred_at)], ["Sede", e.site], ["Descripción", e.description]]} />
      </Section>
      <Section n={2} title="Notificaciones">
        {data.notifications.length === 0 ? <p className="text-sm text-gray-600">Este evento no tiene notificaciones registradas.</p> : (
          <table className="w-full border-collapse text-xs">
            <thead><tr className="bg-gray-100">
              {["Destinatario", "Tipo", "Plazo", "Notificada", "N.° de constancia", "Canal", "Estado", "Registró"].map((h) => <th key={h} className={cell}>{h}</th>)}
            </tr></thead>
            <tbody>{data.notifications.map((n, i) => (
              <tr key={i} style={{ breakInside: "avoid" }}>
                <td className={cell}>{AUTH[n.authority] ?? n.authority}{n.notes && <span className="block text-gray-600">{n.notes}</span>}</td>
                <td className={cell}>{TYPE[n.notification_type] ?? n.notification_type}</td>
                <td className={cell}>{n.deadline_at ? fmtDT(n.deadline_at) : "Por confirmar"}</td>
                <td className={cell}>{fmtDT(n.notified_at)}</td>
                <td className={cell}>{n.notification_number ?? "—"}{n.has_receipt && <span className="block text-gray-600">Con evidencia adjunta</span>}</td>
                <td className={cell}>{n.channel ? CHANNEL[n.channel] ?? n.channel : "—"}</td>
                <td className={cell}>{STATUS[n.status] ?? n.status}</td>
                <td className={cell}>{n.notified_by ?? "—"}</td>
              </tr>
            ))}</tbody>
          </table>
        )}
      </Section>
    </Sheet>
  );
}
