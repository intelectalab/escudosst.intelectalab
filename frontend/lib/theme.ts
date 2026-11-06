// Tema claro / oscuro / automático. Es una preferencia de cada usuario en este dispositivo
// (en el prototipo vivía en la barra de demostración). Pendiente: guardarla en el perfil del usuario.

export type Theme = "auto" | "light" | "dark";

const KEY = "escudo.theme";

export function getTheme(): Theme {
  if (typeof window === "undefined") return "auto";
  const t = window.localStorage.getItem(KEY);
  return t === "light" || t === "dark" ? t : "auto";
}

export function setTheme(theme: Theme) {
  window.localStorage.setItem(KEY, theme);
  applyTheme(theme);
}

export function applyTheme(theme: Theme) {
  const root = document.documentElement;
  if (theme === "auto") root.removeAttribute("data-theme");
  else root.setAttribute("data-theme", theme);
}

/** Se ejecuta antes de pintar la página para que no parpadee el tema equivocado. */
export const themeBootScript = `try{var t=localStorage.getItem("${KEY}");if(t==="light"||t==="dark")document.documentElement.setAttribute("data-theme",t)}catch(e){}`;
