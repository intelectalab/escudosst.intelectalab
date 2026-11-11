"use client";

// Consultora (M-CON):
//  - SCR-CON-04 «Consultores»: matriz consultor × empresa cliente. Marca la casilla para asignar o quitar a un
//    consultor de una empresa y la estrella para dejarlo como responsable principal. Quitar la asignación le quita el
//    acceso a esa empresa de inmediato (RN-CON-01). Solo el administrador de la cuenta la cambia.
//    Endpoints: GET /con/assignments y POST /con/assignments.
//  - Empresa cliente (RN-CON-02): «Quitar acceso a la consultora» con confirmación. Endpoints: GET /con/link y POST /con/link/revoke.

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Card, Chip } from "./Summary";

type Consultant = { id: string; name: string; email: string | null; role: string; companies: number };
type Matrix = {
  consultancy: string;
  can_edit: boolean;
  consultants: Consultant[];
  companies: { id: string; name: string; ruc: string; assigned: number }[];
  assignments: { company_id: string; user_id: string; is_lead: boolean; since: string }[];
};

const msgOf = (e: unknown) => (e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No pudimos guardar el cambio.");

export function ConsultantMatrix({ readOnly = false }: { readOnly?: boolean }) {
  const [data, setData] = useState<Matrix | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Matrix }>("/con/assignments", { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(load, [load]);

  if (error) return null;
  if (!data) return <div className="h-40 animate-pulse rounded-xl bg-surface" />;
  const editable = data.can_edit && !readOnly;
  const cell = (company: string, user: string) => data.assignments.find((a) => a.company_id === company && a.user_id === user);

  const save = async (company: string, user: string, assigned: boolean, isLead = false) => {
    setBusy(`${company}|${user}`);
    setNotice(null);
    try {
      const r = await api<{ data: Matrix }>("/con/assignments", { method: "POST", withCompany: true, body: { company_id: company, user_id: user, assigned, is_lead: isLead } });
      setData(r.data);
    } catch (e) {
      setNotice(msgOf(e));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card title="Consultores por empresa">
      <p className="mb-3 text-sm text-muted">
        {editable
          ? "Marca la casilla para asignar al consultor a la empresa; la estrella lo deja como responsable principal. Si quitas la asignación, pierde el acceso a esa empresa."
          : "Asignaciones vigentes de los consultores. Solo el administrador de la cuenta las cambia."}
      </p>
      {data.consultants.length === 0 || data.companies.length === 0 ? (
        <p className="text-sm text-muted">
          {data.companies.length === 0 ? "Aún no tienes empresas cliente." : "Aún no hay consultores en tu cuenta: invítalos con el rol «Consultor SST» en Usuarios y roles."}
        </p>
      ) : (
        <div className="-mx-4 overflow-x-auto">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead className="border-y border-line bg-bg text-xs font-semibold text-muted">
              <tr>
                <th className="sticky left-0 z-10 bg-bg px-4 py-2.5">Consultor</th>
                {data.companies.map((c) => (
                  <th key={c.id} className="px-3 py-2.5 text-center align-bottom">
                    <span className="block max-w-[140px] truncate" title={c.name}>{c.name}</span>
                    <span className="block font-normal">{c.assigned} asignado(s)</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.consultants.map((u) => (
                <tr key={u.id} className="border-b border-line last:border-0">
                  <td className="sticky left-0 z-10 bg-surface px-4 py-2.5">
                    <p className="font-semibold">{u.name}</p>
                    <p className="text-xs text-muted">{u.role === "account_admin" ? "Administrador" : "Consultor"} · {u.companies} empresa(s)</p>
                  </td>
                  {data.companies.map((c) => {
                    const a = cell(c.id, u.id);
                    const key = `${c.id}|${u.id}`;
                    return (
                      <td key={c.id} className="px-3 py-2.5 text-center">
                        <div className="inline-flex items-center gap-1.5">
                          <input
                            type="checkbox"
                            className="h-4 w-4"
                            checked={!!a}
                            disabled={!editable || busy === key}
                            aria-label={`${a ? "Quitar" : "Asignar"} a ${u.name} en ${c.name}`}
                            onChange={(e) => save(c.id, u.id, e.target.checked)}
                          />
                          {a && (
                            <button
                              type="button"
                              disabled={!editable || busy === key || a.is_lead}
                              onClick={() => save(c.id, u.id, true, true)}
                              title={a.is_lead ? "Responsable principal" : "Marcar como responsable principal"}
                              aria-label={a.is_lead ? `${u.name} es el responsable principal de ${c.name}` : `Marcar a ${u.name} como responsable principal de ${c.name}`}
                              className={`grid h-7 w-7 place-items-center rounded-lg ${a.is_lead ? "text-amber-ink" : "text-muted hover:bg-bg"} disabled:cursor-default`}
                            >
                              <Icon name="star" size={15} />
                            </button>
                          )}
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {notice && <p className="mt-3 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{notice}</p>}
    </Card>
  );
}

type Link = { managed: boolean; consultancy?: string; consultants?: { name: string; is_lead: boolean; since: string }[]; can_revoke?: boolean };

/** Empresa cliente: con qué consultora trabaja y «Quitar acceso a la consultora». No muestra nada si no tiene una. */
export function ConsultancyLink() {
  const [link, setLink] = useState<Link | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const load = useCallback(() => {
    api<{ data: Link }>("/con/link", { withCompany: true }).then((r) => setLink(r.data)).catch(() => setLink(null));
  }, []);
  useEffect(load, [load]);

  if (done) return <p role="status" className="rounded-xl border border-line bg-surface px-4 py-3 text-sm">{done}</p>;
  if (!link?.managed) return null;

  const revoke = async () => {
    setBusy(true);
    setError(null);
    try {
      await api("/con/link/revoke", { method: "POST", withCompany: true });
      setConfirm(false);
      setDone(`Listo: ${link.consultancy} ya no tiene acceso a tu empresa. Tus datos se quedan en la empresa.`);
    } catch (e) {
      setError(msgOf(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card
      title="Consultora que gestiona tu empresa"
      action={link.can_revoke && <Button size="sm" variant="danger-ghost" icon="lock" onClick={() => setConfirm(true)}>Quitar acceso a la consultora</Button>}
    >
      <p className="text-sm">
        <b>{link.consultancy}</b> <span className="text-muted">gestiona el SG-SST contigo. Tu empresa conserva su plan y sus datos.</span>
      </p>
      {link.consultants && link.consultants.length > 0 && (
        <ul className="mt-2 flex flex-wrap gap-2">
          {link.consultants.map((c, i) => (
            <li key={i}><Chip tone={c.is_lead ? "ok" : "na"}>{c.name}{c.is_lead ? " · responsable" : ""}</Chip></li>
          ))}
        </ul>
      )}
      {confirm && (
        <Modal
          title="¿Quitar el acceso a la consultora?"
          subtitle={link.consultancy}
          icon="lock"
          size="sm"
          onClose={() => setConfirm(false)}
          footer={
            <>
              <Button onClick={() => setConfirm(false)}>Cancelar</Button>
              <Button variant="danger" loading={busy} onClick={revoke}>Sí, quitar acceso</Button>
            </>
          }
        >
          <div className="space-y-3 text-sm">
            <p>Los consultores de {link.consultancy} dejarán de entrar a tu empresa de inmediato y la consultora recibirá un aviso.</p>
            <p className="rounded-lg bg-bg px-3 py-2 text-muted">Tus datos no se borran ni se copian: se quedan en tu empresa. Para volver a trabajar con la consultora, ella debe enviarte una nueva invitación.</p>
            {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-bad">{error}</p>}
          </div>
        </Modal>
      )}
    </Card>
  );
}
