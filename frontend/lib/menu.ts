// Menú lateral de la aplicación de empresa (docs/07-pantallas-y-ux.md §2).
// El orden y los grupos salen del documento, no de group_number del catálogo.

export const PLAN_NAMES: Record<string, string> = {
  E: "Esencial",
  P: "Profesional",
  C: "Corporativo",
  K: "Consultora",
  "+": "complemento",
};

export type MenuGroup = { title: string; codes: string[]; highlight?: boolean };

export const MENU_GROUPS: MenuGroup[] = [
  { title: "Planificación", codes: ["LBA", "POL", "MRL", "PLA", "PAS", "PCA", "RIS", "COM", "PRE"] },
  { title: "Riesgos", codes: ["IPC", "MAP", "MAG", "PSI", "PET", "MOC"] },
  { title: "Operación en campo", codes: ["INS", "ACT", "CH5", "ATS", "PTR", "OBS", "EPP", "EQE", "SIM"] },
  { title: "Personas y salud", codes: ["CAP", "AUL", "FOT", "EMO", "VMD", "DES", "VUL", "VTA"] },
  { title: "Eventos y mejora", codes: ["ACC", "INV", "ACR", "AUI", "EST", "RVD", "REG"] },
  { title: "Contratistas", codes: ["CTR", "SCT", "ING", "HOM"] },
  { title: "Modo SUNAFIL", codes: ["SUN"], highlight: true },
  { title: "Herramientas", codes: ["IAA", "PSE", "ISO", "AMB", "OFF"] },
  { title: "Configuración", codes: ["TEN", "USR", "TRB", "DOC", "NOT", "FIR", "AUD", "API", "SUS"] },
];

/** Texto «Disponible en …» para un módulo bloqueado. */
export function availableIn(requiredPlan: string, moduleName: string): string {
  if (requiredPlan === "+") return `el complemento «${moduleName}»`;
  if (requiredPlan === "K") return "el plan Consultora";
  return `el plan ${PLAN_NAMES[requiredPlan] ?? requiredPlan}`;
}
