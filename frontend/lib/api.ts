// Cliente de la API Laravel (/api/v1). Envía el token Bearer y la empresa activa.
// Los tipos de la API deben generarse desde api/openapi.yaml cuando el equipo lo comparta
// (docs/02-convenciones.md); por ahora están escritos a mano en lib/types.ts.

import { session } from "./session";

export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

/** Error en formato problem+json (docs/08-api.md §7). */
export class ApiError extends Error {
  constructor(
    public status: number,
    public type: string,
    public title: string,
    public detail?: string,
    public errors?: Record<string, string[]>,
  ) {
    super(detail ?? title);
  }
}

type Options = {
  method?: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  body?: unknown;
  /** Enviar X-Company-Id con la empresa activa. */
  withCompany?: boolean;
};

export async function api<T>(path: string, { method = "GET", body, withCompany = false }: Options = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = session.getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (withCompany) {
    const companyId = session.getCompanyId();
    if (companyId) headers["X-Company-Id"] = companyId;
  }

  let res: Response;
  try {
    res = await fetch(`${API_URL}/api/v1${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, "network_error", "Sin conexión", "No pudimos conectar con el servidor. Revisa que la API esté encendida.");
  }

  if (res.status === 204) return undefined as T;

  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    if (res.status === 401 && data.type === "unauthenticated") {
      const had = !!session.getToken();
      session.clear();
      // La sesión se cerró (inactividad o tiempo máximo): se vuelve al ingreso con el aviso, sin mostrar un error.
      // La promesa queda pendiente a propósito: la pantalla se va y nada más debe reaccionar.
      if (had && typeof window !== "undefined") {
        session.markExpired();
        window.location.assign("/login");
        return new Promise<T>(() => undefined);
      }
    }
    throw new ApiError(res.status, data.type ?? "error", data.title ?? "Error", data.detail, data.errors);
  }

  return data as T;
}
