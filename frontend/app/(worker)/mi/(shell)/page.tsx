"use client";

// Inicio de la app del trabajador (docs/07 §5.2): tarjetas grandes en este orden: fotocheck, pendientes, reportar,
// capacitaciones y cursos, EPP, resultados médicos y elección del comité (solo con votación abierta).

import { InstallAppCard } from "@/components/worker/InstallApp";
import { BigCard } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { FITNESS, fmtDate } from "@/lib/workerApp";

export default function WorkerHomePage() {
  const { home } = useWorker();
  const { modules: m, summary: s, worker: w } = home;
  const cred = s.credential;
  const enabled = cred?.status === "active" && (!cred.expires_on || cred.expires_on >= new Date().toISOString().slice(0, 10));
  const e = s.election;

  return (
    <>
      <div>
        <p className="text-sm text-muted">Hola,</p>
        <h1 className="font-display text-2xl font-bold">{w.first_name}</h1>
        <p className="text-sm text-muted">{[w.position, w.site].filter(Boolean).join(" · ") || "Tu app de seguridad y salud"}</p>
      </div>
      <InstallAppCard />
      <nav aria-label="Tu app" className="space-y-3">
        {m.FOT && (
          <BigCard href="/mi/fotocheck" icon="idcard" title="Mi fotocheck" tone={enabled ? "ok" : "bad"}
            line={!cred ? "Aún no tienes fotocheck" : enabled ? `Habilitado${cred.expires_on ? ` · vence ${fmtDate(cred.expires_on)}` : ""}` : "No habilitado · toca para ver por qué"} />
        )}
        <BigCard href="/mi/pendientes" icon="checklist" title="Mis pendientes" tone={s.pending ? "amber" : "ok"}
          line={s.pending ? `Tienes ${s.pending} por hacer` : "Todo al día. ¡Gracias por cuidarte!"} badge={s.pending || null} />
        {m.ACT && <BigCard href="/mi/reportar" icon="megaphone" title="Reportar" tone="amber" line="Acto o condición insegura, con foto" />}
        {(m.CAP || m.AUL) && (
          <BigCard href="/mi/capacitaciones" icon="graduation" title={m.AUL ? "Mis capacitaciones y cursos" : "Mis capacitaciones"}
            line={[s.certificates ? `${s.certificates} certificado(s)` : null, m.AUL && s.courses ? `${s.courses} curso(s) por terminar` : null].filter(Boolean).join(" · ") || "Tus constancias y certificados"} />
        )}
        {m.EPP && (
          <BigCard href="/mi/epp" icon="hardhat" title="Mi EPP" tone={s.ppe?.unsigned ? "amber" : "brand"}
            line={!s.ppe?.total ? "Sin entregas registradas" : s.ppe.unsigned ? `${s.ppe.unsigned} entrega(s) por firmar` : s.ppe.expiring ? `${s.ppe.expiring} por vencer pronto` : `${s.ppe.total} entrega(s) vigentes`} />
        )}
        {m.EMO && (
          <BigCard href="/mi/salud" icon="heart" title="Mis resultados médicos"
            line={s.fitness ? `${FITNESS[s.fitness.status] ?? s.fitness.status}${s.fitness.valid_until ? ` · hasta ${fmtDate(s.fitness.valid_until)}` : ""}` : "Te pediremos tu PIN"} />
        )}
        {m.COM && e && e.on_roll && (
          <BigCard href="/mi/comite" icon="people" title="Elección del comité" tone={e.has_voted ? "ok" : "brand"}
            line={e.has_voted ? "Ya votaste. ¡Gracias!" : `Vota hasta el ${fmtDate(e.voting_closes_at, true)}`} />
        )}
        <BigCard href="/mi/ficha" icon="user" title="Mi ficha" line="Tus datos, tu celular y tu contacto de emergencia" />
      </nav>
    </>
  );
}
