"use client";

// Barra inferior del celular con 4 accesos según el rol (docs/07-pantallas-y-ux.md §2). Solo muestra los módulos que
// la persona puede usar; el resto se abre desde el menú.

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Icon } from "@/components/ui/Icon";
import type { ModuleItem } from "@/lib/types";

const BY_ROLE: Record<string, string[]> = {
  sst_manager: ["DSH", "INS", "ACT", "ACR"],
  account_admin: ["DSH", "TRB", "ACR", "SUN"],
  consultant: ["DSH", "INS", "ACR", "SUN"],
  sst_supervisor: ["INS", "CH5", "ATS", "ACT"],
  area_lead: ["DSH", "ACR", "INS", "ACT"],
  committee_member: ["DSH", "COM", "ACR", "INS"],
  management: ["DSH", "ACR", "EST", "SUN"],
  occupational_doctor: ["EMO", "VMD", "DES", "VUL"],
  health_staff: ["EMO", "VMD", "DES", "VUL"],
  hr: ["TRB", "CAP", "EMO", "SCT"],
  trainer: ["CAP", "AUL", "CH5", "DSH"],
  contractor_admin: ["CTR", "SCT", "ING", "DSH"],
  external_auditor: ["DSH", "AUI", "ACR", "REG"],
};
const ICON: Record<string, string> = {
  DSH: "home", INS: "clipboard", ACT: "megaphone", ACR: "check", TRB: "people", SUN: "shield", CH5: "chat", ATS: "checklist", COM: "people",
  EST: "chart", EMO: "stethoscope", VMD: "heart", DES: "calendar", VUL: "shield", CAP: "graduation", AUL: "video", CTR: "truck", SCT: "umbrella",
  ING: "door", AUI: "checklist", REG: "download",
};
const SHORT: Record<string, string> = { DSH: "Inicio", INS: "Inspecciones", ACT: "Reportes", ACR: "Acciones", TRB: "Personal", SUN: "SUNAFIL", CH5: "Charlas", ATS: "ATS", COM: "Comité", EST: "Índices", EMO: "EMO", VMD: "Vigilancia", DES: "Descansos", VUL: "Vulnerables", CAP: "Capacitación", AUL: "Aula", CTR: "Contratistas", SCT: "SCTR", ING: "Ingreso", AUI: "Auditorías", REG: "Registros" };

export function MobileBar({ role, modules }: { role: string; modules: ModuleItem[] }) {
  const pathname = usePathname();
  const usable = (c: string) => modules.some((m) => m.code === c && m.role_can_use && (m.mode === "enabled" || m.mode === "read_only"));
  const codes = (BY_ROLE[role] ?? ["DSH", "INS", "ACT", "ACR"]).filter(usable).slice(0, 4);
  if (codes.length === 0) return null;
  return (
    <nav aria-label="Accesos rápidos" className="fixed inset-x-0 bottom-0 z-30 grid border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] md:hidden" style={{ gridTemplateColumns: `repeat(${codes.length}, minmax(0, 1fr))` }}>
      {codes.map((c) => {
        const href = c === "DSH" ? "/inicio" : `/m/${c.toLowerCase()}`;
        const active = pathname === href || pathname.startsWith(href + "?");
        return (
          <Link key={c} href={href} aria-current={active ? "page" : undefined}
            className={`flex min-h-[3.25rem] flex-col items-center justify-center gap-0.5 text-[11px] font-semibold ${active ? "text-brand" : "text-muted"}`}>
            <Icon name={ICON[c] ?? "box"} size={20} />
            {SHORT[c] ?? c}
          </Link>
        );
      })}
    </nav>
  );
}
