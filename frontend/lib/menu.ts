// Menú lateral de la aplicación de empresa: grupos y orden de docs/07-pantallas-y-ux.md §2 (si el prototipo contradice
// la documentación, manda la documentación: docs/07, línea 3). Cada grupo es un módulo del menú y sus elementos, los
// submódulos; un grupo con un solo elemento se muestra como enlace directo (Modo SUNAFIL).

export const PLAN_NAMES: Record<string, string> = {
  E: "Esencial",
  P: "Profesional",
  C: "Corporativo",
  K: "Consultora",
  "+": "complemento",
};

export type MenuGroup = { title: string; icon: string; codes: string[]; highlight?: boolean };

export const MENU_GROUPS: MenuGroup[] = [
  { title: "Planificación", icon: "calendar", codes: ["LBA", "POL", "MRL", "PLA", "PAS", "PCA", "RIS", "COM", "PRE"] },
  { title: "Riesgos", icon: "alert", codes: ["IPC", "MAP", "MAG", "PSI", "PET", "MOC"] },
  { title: "Operación en campo", icon: "hardhat", codes: ["INS", "ACT", "CH5", "ATS", "PTR", "OBS", "EPP", "EQE", "SIM"] },
  { title: "Personas y salud", icon: "people", codes: ["CAP", "AUL", "FOT", "EMO", "VMD", "DES", "VUL", "VTA"] },
  { title: "Eventos y mejora", icon: "activity", codes: ["ACC", "INV", "ACR", "AUI", "EST", "RVD", "REG"] },
  { title: "Contratistas", icon: "truck", codes: ["CTR", "SCT", "ING", "HOM"] },
  { title: "Modo SUNAFIL", icon: "shield", codes: ["SUN"], highlight: true },
  { title: "Herramientas", icon: "sparkle", codes: ["IAA", "PSE", "ISO", "AMB", "OFF"] },
  { title: "Configuración", icon: "cog", codes: ["TEN", "USR", "TRB", "DOC", "NOT", "FIR", "AUD", "API", "SUS"] },
];

/** Texto «Disponible en …» para un módulo bloqueado. */
export function availableIn(requiredPlan: string, moduleName: string): string {
  if (requiredPlan === "+") return `el complemento «${moduleName}»`;
  if (requiredPlan === "K") return "el plan Consultora";
  return `el plan ${PLAN_NAMES[requiredPlan] ?? requiredPlan}`;
}
