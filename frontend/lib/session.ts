// Sesión en el navegador: token de Sanctum y empresa activa.
// Pendiente de decidir con seguridad (docs/10): cookie de sesión de Sanctum con CSRF
// en lugar de localStorage para la web de primera parte.

const TOKEN_KEY = "escudo.token";
const COMPANY_KEY = "escudo.companyId";

function read(key: string): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(key);
}

export const session = {
  getToken: () => read(TOKEN_KEY),
  setToken: (token: string) => window.localStorage.setItem(TOKEN_KEY, token),
  getCompanyId: () => read(COMPANY_KEY),
  setCompanyId: (id: string) => window.localStorage.setItem(COMPANY_KEY, id),
  clearCompany: () => window.localStorage.removeItem(COMPANY_KEY),
  clear: () => {
    if (typeof window === "undefined") return;
    window.localStorage.removeItem(TOKEN_KEY);
    window.localStorage.removeItem(COMPANY_KEY);
  },
};
