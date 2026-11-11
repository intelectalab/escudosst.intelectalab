"use client";

// Mis pendientes (docs/07 §5.2 punto 2): firmas pedidas, cargos de documentos y del RISST, EPP por recibir,
// cursos asignados, encuestas abiertas y la elección del comité. Firmas con tu dedo y tu PIN.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { Alert, Empty, Loading, SignSheet, Title } from "@/components/worker/WorkerUi";
import { useWorker } from "@/components/worker/WorkerContext";
import { errorText, workerApi, type PendingItem, type SignKind } from "@/lib/workerApp";

const ICON: Record<PendingItem["kind"], string> = { signature: "pen", document: "file", risst: "book", ppe: "hardhat", talk: "megaphone", ats: "checklist", course: "play", survey: "chat", election: "people" };
const SIGNABLE: PendingItem["kind"][] = ["signature", "document", "risst", "ppe", "talk", "ats"];

export default function PendingPage() {
  const { reload } = useWorker();
  const [items, setItems] = useState<PendingItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<{ kind: SignKind; id: string } | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const load = useCallback(() => workerApi<PendingItem[]>("/pending").then(setItems).catch((e) => setError(errorText(e))), []);
  useEffect(() => {
    void load();
  }, [load]);

  const row = (it: PendingItem) => {
    const inner = (
      <>
        <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-amber-soft text-amber-ink" aria-hidden="true"><Icon name={ICON[it.kind]} size={22} /></span>
        <span className="min-w-0 flex-1 text-left">
          <b className="block leading-tight">{it.title}</b>
          <span className="block text-sm text-muted">{it.detail}</span>
        </span>
        <Icon name="chevron" size={18} className="shrink-0 text-muted" />
      </>
    );
    const cls = "flex min-h-[4.5rem] w-full items-center gap-4 rounded-2xl border border-line bg-surface p-4 hover:border-brand";
    if (SIGNABLE.includes(it.kind)) return <button key={`${it.kind}-${it.id}`} className={cls} onClick={() => setOpen({ kind: it.kind as SignKind, id: it.id })}>{inner}</button>;
    if (it.kind === "survey" && it.url) return <a key={`${it.kind}-${it.id}`} href={it.url} className={cls}>{inner}</a>;
    return <Link key={`${it.kind}-${it.id}`} href={it.kind === "course" ? `/mi/cursos/${it.id}` : "/mi/comite"} className={cls}>{inner}</Link>;
  };

  return (
    <>
      <Title sub="Firma, lee y completa lo que te toca.">Mis pendientes</Title>
      {done && <Alert tone="ok">{done}</Alert>}
      {error && <Alert>{error}</Alert>}
      {!items && !error && <Loading />}
      {items && items.length === 0 && <Empty text="Todo al día. No tienes pendientes. ¡Gracias por cuidarte!" />}
      {items && items.length > 0 && <div className="space-y-3">{items.map(row)}</div>}
      {open && (
        <SignSheet kind={open.kind} id={open.id} onClose={() => setOpen(null)}
          onSigned={() => {
            setOpen(null);
            setDone("Firmado. ¡Gracias!");
            void load();
            void reload();
          }} />
      )}
    </>
  );
}
