"use client";

// Voto por enlace (M-COM RN-COM-03, docs/07 §5.2): credenciales de un solo uso para los trabajadores sin usuario de la
// app (la junta las emite y las imprime), la cédula pública /votar/{empresa}/{token} y la tarjeta «Elección del comité»
// del inicio del trabajador. El voto sigue siendo secreto: el enlace solo marca en el padrón que la persona votó.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { QrCode } from "@/components/mfa/QrCode";

export type Credential = {
  roll_id: string; worker_id: string; full_name: string; document_last4: string | null; position: string | null; site: string | null;
  has_voted: boolean; has_app_user: boolean; issued_at: string | null; token: string | null; path: string | null;
};
type CredMeta = {
  election: { id: string; title: string; status: string; voting_opens_at: string | null; voting_closes_at: string | null };
  company: { name: string; ruc: string };
  issued: number; without_app_user: number;
};

const errText = (e: unknown) => (e as ApiError).detail ?? (e as ApiError).title;
const fmt = (v: string | null) => (v ? new Date(v).toLocaleString("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }) : "—");

function useCredentials(id: string) {
  const [rows, setRows] = useState<Credential[] | null>(null);
  const [meta, setMeta] = useState<CredMeta | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Credential[]; meta: CredMeta }>(`/com/elections/${id}/credentials`, { withCompany: true })
      .then((r) => { setRows(r.data); setMeta(r.meta); })
      .catch((e) => setError(errText(e)));
  }, [id]);
  useEffect(load, [load]);
  return { rows, meta, error, load };
}

/** Junta electoral: emitir, reemitir e imprimir las credenciales de voto por enlace. */
export function VoteCredentialsModal({ electionId, onClose }: { electionId: string; onClose: () => void }) {
  const { rows, meta, error, load } = useCredentials(electionId);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ t: string; bad?: boolean } | null>(null);
  const [q, setQ] = useState("");

  const issue = async (rollIds?: string[]) => {
    setBusy(rollIds?.[0] ?? "all");
    setMsg(null);
    try {
      const r = await api<{ data: { issued: number } }>(`/com/elections/${electionId}/credentials`, { method: "POST", withCompany: true, body: rollIds ? { roll_ids: rollIds } : {} });
      setMsg({ t: rollIds ? "Credencial nueva emitida: el enlace anterior ya no sirve." : r.data.issued ? `${r.data.issued} credencial(es) emitida(s).` : "Todos los electores sin usuario ya tienen su credencial." });
      load();
    } catch (e) {
      setMsg({ t: errText(e), bad: true });
    } finally {
      setBusy(null);
    }
  };
  const shown = (rows ?? []).filter((r) => r.full_name.toLowerCase().includes(q.toLowerCase()));
  const pendingNoUser = (rows ?? []).filter((r) => !r.has_app_user && !r.token && !r.has_voted).length;

  return (
    <Modal title="Credenciales de voto" subtitle="Para quienes no tienen usuario de la app: un enlace de un solo uso por elector. El voto sigue siendo secreto." icon="key" size="lg" onClose={onClose}
      footer={
        <div className="flex w-full flex-wrap justify-end gap-2">
          <Button onClick={onClose}>Cerrar</Button>
          <a href={`/imprimir/credenciales-voto/${electionId}`} target="_blank" rel="noreferrer"
            className={`inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand ${meta?.issued ? "" : "pointer-events-none opacity-50"}`}>
            Imprimir lista de credenciales
          </a>
          <Button variant="primary" loading={busy === "all"} disabled={!pendingNoUser} onClick={() => issue()}>
            Emitir a quienes no tienen usuario{pendingNoUser ? ` (${pendingNoUser})` : ""}
          </Button>
        </div>
      }>
      {(error || msg) && <p role="status" className={`mb-3 rounded-lg px-3 py-2 text-sm ${error || msg?.bad ? "bg-bad-soft text-bad" : "bg-ok-soft text-ok"}`}>{error ?? msg?.t}</p>}
      <p className="mb-3 text-sm text-muted">
        Entrega cada credencial en mano (impresa) o por un canal personal. Quien tenga el enlace puede votar una sola vez por esa persona: guárdala como un documento reservado.
      </p>
      <input className="input mb-3" placeholder="Buscar por nombre" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar elector" />
      {!rows ? (
        <div className="h-40 animate-pulse rounded-lg bg-bg" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs text-muted">
              <tr><th className="py-2 pr-3">Elector</th><th className="py-2 pr-3">Acceso</th><th className="py-2 pr-3">Credencial</th><th className="py-2" /></tr>
            </thead>
            <tbody>
              {shown.map((r) => (
                <tr key={r.roll_id} className="border-b border-line last:border-0">
                  <td className="py-2 pr-3">
                    <b className="font-medium">{r.full_name}</b>
                    <span className="block text-xs text-muted">{[r.document_last4 ? `DNI ****${r.document_last4}` : null, r.position, r.site].filter(Boolean).join(" · ")}</span>
                  </td>
                  <td className="py-2 pr-3 text-muted">{r.has_app_user ? "Vota en la app" : "Sin usuario"}</td>
                  <td className="py-2 pr-3">
                    {r.has_voted ? <Chip tone="ok">Ya votó</Chip> : r.token ? <Chip tone="amber">Emitida {fmt(r.issued_at)}</Chip> : <Chip tone="na">Sin credencial</Chip>}
                  </td>
                  <td className="py-2 text-right">
                    {!r.has_voted && (
                      <Button size="sm" variant="ghost" loading={busy === r.roll_id} onClick={() => issue([r.roll_id])}>
                        {r.token ? "Reemitir" : "Emitir"}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  );
}

/** Hoja imprimible: una credencial recortable por elector con su QR, nombre y enlace. */
export function VoteCredentialsSheet({ id }: { id: string }) {
  const { rows, meta, error } = useCredentials(id);
  const [origin, setOrigin] = useState("");
  useEffect(() => setOrigin(window.location.origin), []);
  if (error) return <p className="p-8">{error}</p>;
  if (!rows || !meta) return <p className="p-8">Preparando las credenciales…</p>;
  const list = rows.filter((r) => r.token && r.path);
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 12mm } @media print { .no-print { display: none } .cred { break-inside: avoid } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-4xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">{list.length} credencial(es) sin usar. Documento reservado: entrégalo solo a la junta electoral.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button>
      </div>
      <div className="mx-auto max-w-4xl bg-white p-6 shadow print:max-w-none print:p-0 print:shadow-none">
        <p className="text-center text-base font-bold uppercase">Credenciales de voto · {meta.election.title}</p>
        <p className="mb-4 text-center text-sm">{meta.company.name} · RUC {meta.company.ruc} · Votación hasta el {fmt(meta.election.voting_closes_at)}</p>
        {list.length === 0 ? (
          <p className="text-center text-sm">No hay credenciales sin usar. Emítelas desde «Credenciales de voto».</p>
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 print:grid-cols-2">
            {list.map((r) => (
              <div key={r.roll_id} className="cred flex gap-3 rounded-lg border border-dashed border-gray-400 p-3">
                <QrCode value={`${origin}${r.path}`} size={112} />
                <div className="min-w-0 text-sm">
                  <p className="font-bold">{r.full_name}</p>
                  <p className="text-xs">{[r.document_last4 ? `DNI ****${r.document_last4}` : null, r.position, r.site].filter(Boolean).join(" · ")}</p>
                  <p className="mt-1 text-xs">Escanea el código o entra al enlace. Tu voto es secreto y se emite una sola vez.</p>
                  <p className="mt-1 break-all font-mono text-[10px]">{origin}{r.path}</p>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

type PublicBallot = {
  company: { name: string };
  election: { title: string; status: string; voting_closes_at: string | null; allow_blank: boolean };
  voter: { first_name: string };
  open: boolean; has_voted: boolean;
  candidates: { id: string; name: string; position: string | null; list_name: string | null }[];
};

/** Cédula pública del elector con su enlace (sin sesión), botones grandes y texto en tú. */
export function PublicBallotView({ company, token }: { company: string; token: string }) {
  const [b, setB] = useState<PublicBallot | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sel, setSel] = useState<string | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [receipt, setReceipt] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api<{ data: PublicBallot }>(`/public/com/vote/${company}/${token}`).then((r) => setB(r.data)).catch((e) => setError(errText(e) ?? "El enlace no es válido."));
  }, [company, token]);
  const vote = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { receipt: string } }>(`/public/com/vote/${company}/${token}`, { method: "POST", body: sel === "blank" ? { blank: true } : { candidate_id: sel } });
      setReceipt(r.data.receipt);
      setB((x) => (x ? { ...x, has_voted: true } : x));
    } catch (e) {
      setError(errText(e));
    } finally {
      setBusy(false);
      setConfirm(false);
    }
  };
  const chosen = sel === "blank" ? "voto en blanco" : b?.candidates.find((c) => c.id === sel)?.name;

  return (
    <main className="min-h-screen bg-bg px-4 py-6 text-fg">
      <div className="mx-auto max-w-lg space-y-4">
        <p className="text-xs font-bold uppercase tracking-wider text-brand">Elección del Comité SST</p>
        {!b && !error && <div className="h-40 animate-pulse rounded-2xl border border-line bg-surface" />}
        {error && <p role="alert" className="rounded-2xl border border-line bg-surface p-4 text-sm text-bad">{error}</p>}
        {b && (
          <section className="space-y-4 rounded-2xl border border-line bg-surface p-5">
            <div>
              <h1 className="font-display text-xl font-bold">{b.election.title}</h1>
              <p className="text-sm text-muted">{b.company.name}{b.election.voting_closes_at ? ` · votación hasta el ${fmt(b.election.voting_closes_at)}` : ""}</p>
            </div>
            {receipt || b.has_voted ? (
              <div className="rounded-xl bg-ok-soft px-4 py-3 text-ok">
                <p className="font-semibold">{receipt ? `Listo, ${b.voter.first_name}: tu voto quedó registrado.` : "Este enlace ya se usó: ya se votó con él."}</p>
                {receipt && <p className="mt-1 text-sm">Comprobante: <b className="font-mono">{receipt}</b> (no indica por quién votaste).</p>}
              </div>
            ) : !b.open ? (
              <p className="text-sm text-muted">Hola, {b.voter.first_name}. La votación no está abierta en este momento: vuelve a entrar con este mismo enlace cuando abra.</p>
            ) : (
              <div className="space-y-2" role="radiogroup" aria-label="Candidatos">
                <p className="text-sm">Hola, {b.voter.first_name}. Elige a un candidato. Tu voto es secreto y se emite una sola vez.</p>
                {b.candidates.map((c) => (
                  <button key={c.id} role="radio" aria-checked={sel === c.id} onClick={() => setSel(c.id)}
                    className={`flex min-h-12 w-full items-center gap-3 rounded-xl border px-4 py-3 text-left ${sel === c.id ? "border-brand bg-brand-soft" : "border-line hover:border-brand/50"}`}>
                    <span className="min-w-0">
                      <b className="block">{c.name}</b>
                      <span className="text-xs text-muted">{[c.position, c.list_name].filter(Boolean).join(" · ")}</span>
                    </span>
                  </button>
                ))}
                {b.election.allow_blank && (
                  <button role="radio" aria-checked={sel === "blank"} onClick={() => setSel("blank")}
                    className={`flex min-h-12 w-full items-center rounded-xl border px-4 text-left text-sm font-semibold ${sel === "blank" ? "border-brand bg-brand-soft" : "border-line text-muted hover:border-brand/50"}`}>
                    Voto en blanco
                  </button>
                )}
                <Button variant="primary" size="lg" full disabled={!sel} onClick={() => setConfirm(true)}>Emitir mi voto</Button>
              </div>
            )}
          </section>
        )}
      </div>
      {confirm && (
        <Modal title="Confirmar voto" size="sm" onClose={() => setConfirm(false)}
          footer={<>
            <Button onClick={() => setConfirm(false)}>Volver</Button>
            <Button variant="primary" loading={busy} onClick={vote}>Confirmar voto</Button>
          </>}>
          <p>Vas a votar por <b>{chosen}</b>. Una vez emitido no se puede cambiar.</p>
        </Modal>
      )}
    </main>
  );
}

type MyElection = { id: string; title: string; voting_closes_at: string | null; on_roll: boolean; has_voted: boolean; linked: boolean } | null;

/** Tarjeta «Elección del comité» del inicio (docs/07 §5.2, punto 7): solo con votación abierta y si puedes votar. */
export function ElectionHomeCard() {
  const [e, setE] = useState<MyElection>(null);
  useEffect(() => {
    api<{ data: MyElection }>("/com/my-election", { withCompany: true }).then((r) => setE(r.data)).catch(() => setE(null));
  }, []);
  if (!e || !e.on_roll) return null;
  return (
    <section aria-label="Elección del comité" className="flex flex-wrap items-center gap-4 rounded-2xl border border-line bg-surface p-4">
      <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong"><Icon name="people" size={22} /></span>
      <div className="min-w-0 flex-1">
        <h2 className="font-display text-base font-semibold">Elección del comité</h2>
        <p className="text-sm text-muted">
          {e.title}{e.voting_closes_at ? ` · vota hasta el ${fmt(e.voting_closes_at)}` : ""}
        </p>
      </div>
      {e.has_voted ? (
        <Chip tone="ok">Ya votaste</Chip>
      ) : (
        <Link href="/m/com" className="inline-flex h-12 items-center rounded-lg bg-brand px-5 text-sm font-semibold text-white hover:bg-brand-strong">Votar ahora</Link>
      )}
    </section>
  );
}
