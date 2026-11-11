// Mapa de riesgos (M-MAP): leyenda con el vínculo de cada pictograma al IPERC o al equipo de emergencia.

import { api, API_URL } from "./api";
import { session } from "./session";

export type LegendMarker = {
  id: string;
  marker_type: string;
  pictogram_code: string | null;
  x_pct: number;
  y_pct: number;
  label: string | null;
  notes: string | null;
  location: string;
  iperc: {
    row_id: string;
    row_number: number;
    hazard: string;
    risk: string;
    level: string | null;
    color: string | null;
    matrix_id: string;
    matrix_code: string;
    matrix_title: string;
    version_number: number;
    version_status: string;
    stale: boolean;
  } | null;
  equipment: { id: string; code: string; type: string; location: string | null } | null;
};

export type Legend = {
  map: {
    id: string;
    title: string;
    floor_label: string | null;
    version_number: number;
    status: string;
    approved_at: string | null;
    effective_on: string | null;
    plan_file_id: string;
    site_name: string;
    area_name: string | null;
    approved_by_name: string | null;
    place: string;
  };
  markers: LegendMarker[];
};

export const MAP_STATUS: Record<string, string> = { draft: "Borrador", in_review: "En revisión", approved: "Aprobado", current: "Vigente", obsolete: "Obsoleto" };

export const mapApi = {
  legend: (id: string) => api<{ data: Legend }>(`/map/maps/${id}/legend`, { withCompany: true }),
};

/** Descarga el plano (imagen) con la sesión activa y devuelve un objectURL (revócalo al terminar). */
export async function planUrl(fileId: string): Promise<string> {
  const r = await fetch(`${API_URL}/api/v1/files/${fileId}`, { headers: { Authorization: `Bearer ${session.getToken()}`, "X-Company-Id": session.getCompanyId() ?? "" } });
  if (!r.ok) throw new Error("No pudimos abrir el plano.");
  return URL.createObjectURL(await r.blob());
}
