"use client";

// Aviso en la empresa cuando soporte de EscudoSST pidió acceso y espera respuesta (docs/05 §4.6).

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { api } from "@/lib/api";
import type { SupportGrant } from "@/lib/support";

export function SupportRequestBanner() {
  const pathname = usePathname();
  const [pending, setPending] = useState<SupportGrant[]>([]);

  useEffect(() => {
    api<{ data: SupportGrant[] }>("/account/support-access", { withCompany: true })
      .then((r) => setPending(r.data.filter((g) => g.status === "requested")))
      .catch(() => undefined);
  }, [pathname]);

  if (pending.length === 0 || pathname === "/soporte") return null;
  const g = pending[0];

  return (
    <div role="status" className="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-brand/30 bg-brand-soft px-4 py-3 text-brand-strong">
      <Icon name="shield" size={20} className="shrink-0" />
      <p className="min-w-0 flex-1 text-sm">
        <b>{g.support_name}, de soporte de EscudoSST, pide acceso de solo lectura.</b> Motivo: {g.reason}
        {pending.length > 1 && ` (y ${pending.length - 1} más)`}
      </p>
      <Link href="/soporte" className="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-strong">
        Revisar solicitud
      </Link>
    </div>
  );
}
