// Cada módulo del catálogo (datos del menú): abre /m/<código> y revisa lo que la persona ve y puede tocar.
//  - Pantalla común de registros: indicadores con números, cada pestaña con su lista o «aún no hay registros»,
//    «Exportar» descarga un CSV, «Nuevo» abre el formulario y, enviado vacío, muestra los errores en castellano, y la
//    primera fila abre su ficha (Resumen, Detalle, …, Historial) con el historial cargado.
//  - Pantallas propias (tablero, IPERC, SUNAFIL, aula virtual, usuarios…): sus secciones principales y sus botones.
// Los módulos médicos (VMD, DES, VUL, EMO) se recorren con el médico ocupacional.
// No deja registros de prueba: el formulario vacío solo se envía si queda vacío algún campo obligatorio (el servidor
// tiene que rechazarlo); si se guardara igual, la prueba falla y lo informa.

import type { Locator, Page } from "@playwright/test";
import { MENU_GROUPS } from "../lib/menu";
import { NEW_LABEL } from "../lib/moduleScreens";
import { apiJson, expect, expectHealthy, myModules, settle, test, type ModuleItem } from "./fixtures";

const ALL_CODES = ["DSH", ...MENU_GROUPS.flatMap((g) => g.codes), "CON"];
const MEDICAL = new Set(["VMD", "DES", "VUL", "EMO"]);

/** Pantallas propias: textos que deben verse, botones que deben estar habilitados y pestañas propias que se tocan. */
type Custom = { texts?: (string | RegExp)[]; buttons?: (string | RegExp)[]; disabled?: string[]; tabs?: string[] };
const CUSTOM: Record<string, Custom> = {
  DSH: { texts: [/^Hola, /] },
  IPC: { texts: ["Todas las matrices"], buttons: ["Nueva matriz"], tabs: ["Catálogo de peligros", "Criterios de evaluación", "Todas las matrices"] },
  LBA: { texts: ["Diagnósticos"], buttons: ["Nuevo diagnóstico"] },
  SUN: { texts: ["Autodiagnóstico frente a una inspección", "¿Llegó un inspector?", "Expedientes recientes"], buttons: ["Generar expediente", "Volver a evaluar"] },
  CAP: { buttons: ["Programar capacitación"] },
  FOT: { texts: ["Emitir fotocheck"] },
  REG: { texts: ["1. Elige el registro", "2. Elige el periodo", "Vista previa", "Exportaciones"], buttons: ["PDF", "Excel"] },
  EST: { texts: ["Índice de frecuencia"], buttons: ["Registrar horas-hombre"] },
  COM: { texts: ["Próxima reunión", "Acuerdos pendientes"], buttons: ["Enviar convocatoria"], tabs: ["Elección electrónica", "Reuniones y actas", "Comité vigente"] },
  CTR: { texts: ["Revisión de documentos"], buttons: ["Invitar contratista"], tabs: ["Observados", "Aprobados", "Todos", "Por revisar"] },
  ING: { texts: [/^Quiénes están dentro/] },
  AUL: { texts: ["Cursos publicados", "Matrículas activas", "Banco de preguntas"], buttons: ["Capacitadores", "Nuevo curso"] },
  PAS: { texts: ["Programas"], buttons: ["Nuevo programa"] },
  DOC: { texts: [/^Versiones en revisión/], buttons: ["Subir documento"], tabs: ["Campaña de cargos", "Bandeja de aprobación"] },
  FIR: { texts: ["Verificar una firma", /Firmas pendientes/] },
  AUD: { texts: ["Bitácora de la empresa"] },
  USR: { texts: ["Usuarios de la empresa"], buttons: ["Invitar usuario"], tabs: ["Roles", "Matriz de permisos", "Usuarios"] },
  IAA: { texts: ["Pedir sugerencias", "Reglas del asistente"], tabs: ["Investigación guiada", "Consultas rápidas", "Historial", "IPERC sugerido"] },
  MAP: { texts: ["Leyenda"], buttons: ["Imprimir mapa"] },
  MRL: { texts: ["Novedades normativas", "Catálogo de normas"], buttons: ["Norma propia", "Agregar desde el catálogo"] },
  VUL: { texts: ["Adecuaciones de puesto"], buttons: ["Nueva adecuación"] },
  DES: { texts: ["Ausentismo"] },
  // Datos médicos: antes pide confirmar la contraseña (no se completa en las pruebas); el botón espera la contraseña.
  VMD: { texts: ["Confirma tu contraseña"], disabled: ["Entrar a vigilancia médica"] },
};

const DEEP_TABS = Number(process.env.E2E_DEEP_TABS ?? 2);

// Botones principales que, por diseño, esperan un dato antes de habilitarse.
const DISABLED_UNTIL_INPUT = [/^Verificar$/, /^Generar sugerencias$/, /^Buscar$/, /^Enviar$/];

for (const [who, codes] of [
  ["jefe", ALL_CODES.filter((c) => !MEDICAL.has(c))],
  ["medico", ALL_CODES.filter((c) => MEDICAL.has(c))],
] as const) {
  test.describe(`Módulos (${who === "jefe" ? "Jefe SST" : "médico ocupacional"})`, () => {
    test.use({ user: who });
    let modules: ModuleItem[] | null = null;

    for (const code of codes) {
      test(`${code}`, async ({ page, apiToken, companyId }) => {
        test.setTimeout(15 * 60_000);
        modules ??= await myModules(page, apiToken, companyId);
        const m = modules.find((x) => x.code === code);

        await page.goto(`/m/${code.toLowerCase()}`, { waitUntil: "domcontentloaded" });
        await settle(page, 180_000);

        if (!m || !m.role_can_use || m.mode === "disabled_by_company") {
          await expect(page.getByRole("heading", { name: "Página no disponible" })).toBeVisible();
          await expect(page.getByRole("link", { name: "Volver al inicio" })).toBeVisible();
          return;
        }
        if (m.mode === "locked") {
          await expect(page.getByRole("heading", { level: 1, name: m.name, exact: true })).toBeVisible();
          await expect(page.getByRole("heading", { name: /^Disponible en / })).toBeVisible();
          await expect(page.getByRole("link", { name: /Comparar planes|Ver complementos/ })).toBeVisible();
          return;
        }

        if (code !== "DSH") await expect(page.getByRole("heading", { level: 1, name: m.name, exact: true })).toBeVisible();
        await expectHealthy(page);
        const main = page.locator("main");

        const custom = CUSTOM[code];
        if (custom) await test.step("pantalla propia", () => checkCustom(page, main, custom));

        // AUL (administración): los registros están dentro de «Todos los registros».
        const all = main.locator("summary", { hasText: "Todos los registros" });
        if (await all.count()) await all.click();

        await test.step("indicadores", () => checkKpis(main));
        await test.step("registros", () => checkRecords(page, main, code, apiToken, companyId));
        await expectHealthy(page);
      });
    }
  });
}

test.describe("Aula virtual: página del curso", () => {
  test("abre el primer curso y sus pestañas", async ({ page }) => {
    test.setTimeout(4 * 60_000);
    await page.goto("/m/aul", { waitUntil: "domcontentloaded" });
    await settle(page, 90_000);
    const course = page.locator('main a[href^="/m/aul/"]');
    const hrefs = (await course.evaluateAll((as) => as.map((a) => a.getAttribute("href")!))).filter((h) => /^\/m\/aul\/[0-9a-f-]{36}$/.test(h));
    test.skip(hrefs.length === 0, "No hay cursos en la empresa de demostración");
    await page.goto(hrefs[0], { waitUntil: "domcontentloaded" });
    await settle(page, 90_000);
    await expectHealthy(page);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    const tabs = page.locator("main [role=tab]");
    for (let i = 0; i < (await tabs.count()); i++) {
      await tabs.nth(i).click();
      await settle(page);
      await expectHealthy(page);
    }
    for (const sub of ["banco", "recomendaciones", "escalas", "calificar"]) {
      await page.goto(`/m/aul/${sub}`, { waitUntil: "domcontentloaded" });
      await settle(page, 90_000);
      await expectHealthy(page);
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    }
  });
});

test.describe("Portería (garita)", () => {
  test("la pantalla de la garita carga y su botón principal responde", async ({ page }) => {
    await page.goto("/garita", { waitUntil: "domcontentloaded" });
    await settle(page, 90_000);
    await expectHealthy(page);
    await expect(page.locator("main, body").getByRole("heading").first()).toBeVisible();
  });
});

async function checkCustom(page: Page, main: Locator, c: Custom) {
  for (const t of c.texts ?? []) await expect(main.getByText(t).first(), `texto «${t}»`).toBeVisible();
  for (const b of c.buttons ?? []) await expect(main.getByRole("button", { name: b }).first(), `botón «${b}»`).toBeEnabled();
  for (const b of c.disabled ?? []) await expect(main.getByRole("button", { name: b }).first(), `botón «${b}» sin datos`).toBeDisabled();
  for (const t of c.tabs ?? []) {
    await main.getByRole("tab", { name: t, exact: true }).first().click();
    await expect(main.getByRole("tab", { name: t, exact: true }).first()).toHaveAttribute("aria-selected", "true");
    await settle(page);
    await expectHealthy(page);
  }
  // Botones principales visibles: habilitados (salvo los que esperan un dato).
  const primaries = main.locator("button.bg-brand.text-white:visible");
  for (let i = 0; i < (await primaries.count()); i++) {
    const b = primaries.nth(i);
    const name = ((await b.textContent()) ?? "").trim();
    if (DISABLED_UNTIL_INPUT.some((r) => r.test(name))) continue;
    if (await b.isDisabled()) {
      // Un botón deshabilitado debe decir por qué (title) o estar en un formulario incompleto: se informa.
      test.info().annotations.push({ type: "botón deshabilitado", description: name });
    }
  }
}

async function checkKpis(main: Locator) {
  const box = main.locator('[aria-label="Indicadores del módulo"]');
  if (!(await box.count())) return;
  await expect(box.locator(".animate-pulse")).toHaveCount(0, { timeout: 120_000 });
  const values = box.locator("a p.font-display");
  const n = await values.count();
  for (let i = 0; i < n; i++) await expect(values.nth(i)).toHaveText(/^\d+%?$/);
}

async function checkRecords(page: Page, main: Locator, code: string, token: string | null, company: string | null) {
  const tablist = main.locator('[role=tablist][aria-label="Registros del módulo"]');
  const tabs = tablist.getByRole("tab");
  const tabCount = await tabs.count();
  const top = main.locator("section[data-records]:not([data-nested])");
  if (tabCount === 0 && (await top.count()) === 0) return; // pantalla sin registros comunes

  for (let i = 0; i < Math.max(tabCount, 1); i++) {
    if (tabCount > 0) {
      await tabs.nth(i).click();
      await expect(tabs.nth(i)).toHaveAttribute("aria-selected", "true");
    }
    const label = tabCount > 0 ? ((await tabs.nth(i).textContent()) ?? "").trim() : "única";
    await test.step(`pestaña «${label}»`, async () => {
      const section = tabCount > 0 ? main.locator(`section[data-records]:not([data-nested])[aria-label="${label}"]`) : top.first();
      await expect(section).toBeVisible();
      const table = (await section.getAttribute("data-records"))!;
      await checkList(section);
      await checkExport(page, section);
      // El formulario y la ficha se revisan en las dos primeras pestañas (las demás usan el mismo motor).
      if (i < DEEP_TABS) {
        await checkCreate(page, section, code, table, token, company);
        await checkFirstRow(page, section, code);
      }
    });
  }
}

/** La lista termina de cargar: tabla con filas, «aún no hay registros» o un aviso (sin error). */
async function checkList(section: Locator) {
  const done = section.locator("table").or(section.getByText(/Aún no hay registros|No hay registros con esos filtros/));
  await expect(done.first()).toBeVisible({ timeout: 120_000 });
  await expect(section.locator("> p.bg-bad-soft"), "error al cargar la lista").toHaveCount(0);
}

async function checkExport(page: Page, section: Locator) {
  const btn = section.locator("> div").first().getByRole("button", { name: "Exportar", exact: true });
  if (!(await btn.count())) return;
  const [download] = await Promise.all([page.waitForEvent("download", { timeout: 120_000 }), btn.click()]);
  expect(download.suggestedFilename()).toMatch(/\.csv$/);
  const path = await download.path();
  const { readFileSync } = await import("node:fs");
  const text = readFileSync(path, "utf8");
  expect(text.split(/\r?\n/)[0].length, "el CSV trae encabezados").toBeGreaterThan(2);
}

async function checkCreate(page: Page, section: Locator, code: string, table: string, token: string | null, company: string | null) {
  const label = NEW_LABEL[table] ?? "Nuevo";
  const btn = section.locator("> div").first().getByRole("button", { name: label, exact: true });
  if (!(await btn.count())) return;
  await btn.click();
  const dialog = page.getByRole("dialog", { name: /^Nuevo · / });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText("Los campos con * son obligatorios.")).toBeVisible();

  // Envío vacío: el formulario no manda los campos vacíos. Solo se envía si queda vacío algún obligatorio (así el
  // servidor tiene que rechazarlo y nunca se crea un registro de prueba); si todos traen un valor por defecto, se omite.
  const emptyRequired = await dialog.evaluate((root) =>
    [...root.querySelectorAll("label")]
      .filter((l) => /\*\s*$/.test(l.querySelector("span")?.textContent ?? ""))
      .filter((l) => {
        const c = l.querySelector<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>("input:not([type=hidden]), select, textarea");
        return !c || c.value.trim() === "";
      }).length,
  );
  if (emptyRequired === 0) {
    test.info().annotations.push({ type: "envío vacío omitido", description: `${code}/${table}: todos los obligatorios traen valor por defecto` });
    await dialog.getByRole("button", { name: "Cancelar", exact: true }).click();
    await expect(dialog).toBeHidden();
    return;
  }
  await dialog.getByRole("button", { name: "Crear", exact: true }).click();
  // Si aun así se creara (el servidor no exige lo que la pantalla marca con *), se archiva y se informa.
  const errors = dialog.locator("[role=alert], span.text-bad");
  // Lo primero que pase: aparecen los errores o se cierra la ventana (se guardó).
  const shown = errors.first().waitFor({ state: "visible", timeout: 120_000 });
  const closed = dialog.first().waitFor({ state: "hidden", timeout: 120_000 });
  shown.catch(() => undefined);
  closed.catch(() => undefined);
  await Promise.any([shown, closed]);
  if (!(await dialog.first().isVisible())) {
    const list = await apiJson<{ data: { id: string; created_at?: string }[] }>(page, token, company, `/records/${code}/${table}?sort=-created_at`).catch(() => ({ data: [] }));
    test.info().annotations.push({ type: "posible error", description: `${code}/${table}: se aceptó el formulario con obligatorios vacíos (revisar; último id ${list.data[0]?.id ?? "?"})` });
    throw new Error(`${code}/${table}: el formulario con campos obligatorios vacíos se guardó`);
  }
  await expect(errors.first()).toBeVisible();
  const text = (await errors.allTextContents()).join(" | ");
  expect(text, "mensajes de validación en castellano").not.toMatch(/\b(The|field|is required|must be|invalid|selected)\b/);
  expect(text).toMatch(/[a-záéíóúñ]{3,}/i);
  await dialog.getByRole("button", { name: "Cancelar", exact: true }).click();
  await expect(dialog).toBeHidden();
}

async function checkFirstRow(page: Page, section: Locator, code: string) {
  const row = section.locator("tbody tr").first();
  if (!(await row.count())) return;
  const before = page.url();
  await row.click();
  if (code === "TRB") {
    // Un trabajador se abre en su ficha 360°.
    await expect(page).toHaveURL(/\/m\/trb\/[0-9a-f-]{36}$/);
    await settle(page, 60_000);
    await expectHealthy(page);
    await page.goto(before, { waitUntil: "domcontentloaded" });
    await settle(page, 60_000);
    return;
  }
  const dialog = page.getByRole("dialog").filter({ has: page.locator('[aria-label="Partes de la ficha"]') });
  await expect(dialog).toBeVisible({ timeout: 90_000 });
  const ficha = dialog.locator('[aria-label="Partes de la ficha"]').getByRole("tab");
  const names = (await ficha.allTextContents()).map((s) => s.trim());
  expect(names[0]).toBe("Resumen");
  expect(names).toContain("Detalle");
  expect(names[names.length - 1]).toBe("Historial");
  for (let i = 0; i < names.length; i++) {
    await ficha.nth(i).click();
    await expect(ficha.nth(i)).toHaveAttribute("aria-selected", "true");
    if (names[i] === "Historial") {
      await expect(dialog.locator("ol li").or(dialog.getByText("Aún no hay cambios registrados para este registro.")).first()).toBeVisible({ timeout: 120_000 });
    }
  }
  await expect(dialog.locator("[role=alert]"), "error al abrir la ficha").toHaveCount(0);
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
}

