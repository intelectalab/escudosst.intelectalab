// Sesión en el navegador: token de Sanctum y empresa activa.
// Se guarda solo en la pestaña (sessionStorage): al cerrar la pestaña o el navegador la sesión se pierde y hay que
// volver a ingresar. Además la API la cierra sola tras escudo.auth.idle_minutes sin uso (inactividad).
// Pendiente de decidir con seguridad (docs/10): cookie de sesión de Sanctum con CSRF.

const TOKEN_KEY = "escudo.token";
const COMPANY_KEY = "escudo.companyId";
const EXPIRED_KEY = "escudo.expired";
// Copia del menú y del usuario para mostrar el panel al instante (app/(app)/layout.tsx); se borra con la sesión.
const SHELL_KEY = "escudo.shell";

function store(): Storage | null {
  if (typeof window === "undefined") return null;
  // Las sesiones antiguas guardadas de forma permanente se descartan: hay que volver a ingresar.
  window.localStorage.removeItem(TOKEN_KEY);
  window.localStorage.removeItem(COMPANY_KEY);
  return window.sessionStorage;
}

export const session = {
  getToken: () => store()?.getItem(TOKEN_KEY) ?? null,
  setToken: (token: string) => {
    store()?.setItem(TOKEN_KEY, token);
    store()?.removeItem(EXPIRED_KEY);
    store()?.removeItem(SHELL_KEY);
  },
  getCompanyId: () => store()?.getItem(COMPANY_KEY) ?? null,
  setCompanyId: (id: string) => {
    store()?.setItem(COMPANY_KEY, id);
    store()?.removeItem(SHELL_KEY);
  },
  clearCompany: () => {
    store()?.removeItem(COMPANY_KEY);
    store()?.removeItem(SHELL_KEY);
  },
  clear: () => {
    store()?.removeItem(TOKEN_KEY);
    store()?.removeItem(COMPANY_KEY);
    store()?.removeItem(SHELL_KEY);
  },
  /** La API cerró la sesión (inactividad o vencimiento): se avisa en la pantalla de ingreso. */
  markExpired: () => store()?.setItem(EXPIRED_KEY, "1"),
  wasExpired: () => store()?.getItem(EXPIRED_KEY) === "1",
};
