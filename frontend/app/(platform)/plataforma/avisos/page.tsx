"use client";

// Avisos del panel: todo lo pendiente, agrupado. Es la versión completa de la campana de la barra superior.

import { useEffect, useState } from "react";
import { isNew, markSeen, NoticeRow, seenAt, useNotices } from "@/components/platform/NotificationsBell";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";

export default function AvisosPage() {
  const { items, reload } = useNotices();
  const [since, setSince] = useState(0);
  useEffect(() => setSince(seenAt()), []);

  const list = items ?? [];
  const groups = [...new Set(list.map((n) => n.group))];

  return (
    <div className="mx-auto w-full max-w-[1400px] space-y-6">
      <PageHeader
        crumbs={[["Avisos"]]}
        icon="bell"
        title="Avisos"
        description="Lo que está pendiente en la plataforma: reclamos por vencer, solicitudes nuevas, contenido por aprobar y pruebas que terminan. Se actualiza solo cada minuto."
        aside={
          <div className="flex gap-2">
            <Button icon="refresh" onClick={reload}>
              Actualizar
            </Button>
            <Button
              variant="primary"
              icon="check"
              onClick={() => {
                markSeen();
                setSince(Date.now());
              }}
            >
              Marcar todo como leído
            </Button>
          </div>
        }
      />

      {items === null ? (
        <p className="text-sm text-muted">Cargando…</p>
      ) : list.length === 0 ? (
        <div className="rounded-2xl border border-line bg-surface p-10 text-center shadow-sm">
          <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-ok-soft text-ok">
            <Icon name="check" size={26} />
          </span>
          <p className="mt-3 font-display text-lg font-semibold">Todo al día</p>
          <p className="text-sm text-muted">No hay nada pendiente por ahora.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2 2xl:grid-cols-3">
          {groups.map((g) => (
            <section key={g} className="rounded-2xl border border-line bg-surface p-3 shadow-sm">
              <h2 className="px-3 pb-1 pt-2 text-xs font-bold uppercase tracking-wider text-muted">{g}</h2>
              {list
                .filter((n) => n.group === g)
                .map((n) => (
                  <NoticeRow key={n.key} n={n} fresh={isNew(n, since)} />
                ))}
            </section>
          ))}
        </div>
      )}
      <p className="text-xs text-muted">«Leído» se guarda en este navegador. Los avisos desaparecen solos cuando el pendiente se atiende.</p>
    </div>
  );
}
