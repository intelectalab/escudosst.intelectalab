// Accesibilidad (docs/12): cada pantalla se revisa con axe (reglas WCAG 2.1 A y AA). Las fallas se juntan en
// test-results/a11y.json para revisarlas; la prueba falla si aparece alguna de impacto «grave» o «crítico».
// Ejecutar solo esta revisión: npx playwright test a11y --workers=1

import AxeBuilder from "@axe-core/playwright";
import { mkdirSync, writeFileSync, existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { test, expect } from "./fixtures";
import { MENU_GROUPS } from "../lib/menu";

type Found = { page: string; id: string; impact: string | null | undefined; help: string; nodes: number; sample: string[] };
const OUT = join(__dirname, "..", "test-results", "a11y.json");

async function scan(page: import("@playwright/test").Page, label: string): Promise<Found[]> {
  await page.waitForLoadState("networkidle", { timeout: 20_000 }).catch(() => undefined);
  await page.waitForTimeout(800);
  const r = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]).analyze();
  const found = r.violations.map((v) => ({ page: label, id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length,
    sample: v.nodes.slice(0, 3).map((n) => String(n.target.join(" ")).slice(0, 160)) }));
  mkdirSync(join(__dirname, "..", "test-results"), { recursive: true });
  const prev: Found[] = existsSync(OUT) ? JSON.parse(readFileSync(OUT, "utf8")) : [];
  writeFileSync(OUT, JSON.stringify([...prev.filter((f) => f.page !== label), ...found], null, 1));
  return found;
}

const serious = (f: Found[]) => f.filter((x) => x.impact === "serious" || x.impact === "critical").map((x) => `${x.page}: ${x.id} (${x.nodes}) ${x.sample[0] ?? ""}`);

test.describe("sin sesión", () => {
  test.use({ user: null });
  for (const path of ["/", "/planes", "/producto", "/demo", "/prueba-gratis", "/login", "/mi/activar", "/mi/ingresar", "/herramientas/calculadora-multas"]) {
    test(`público ${path}`, async ({ page }) => {
      await page.goto(path);
      expect(serious(await scan(page, path))).toEqual([]);
    });
  }
});

test.describe("empresa (Jefe SST)", () => {
  const codes = MENU_GROUPS.flatMap((g) => g.codes).filter((c) => !["VMD", "DES", "SUS"].includes(c));
  for (const path of ["/inicio", "/cuenta", "/soporte", ...codes.map((c) => `/m/${c.toLowerCase()}`)]) {
    test(`empresa ${path}`, async ({ page }) => {
      await page.goto(path);
      expect(serious(await scan(page, path))).toEqual([]);
    });
  }
});

test.describe("médico", () => {
  test.use({ user: "medico" });
  for (const path of ["/m/emo", "/m/vmd", "/m/des", "/m/vul"]) {
    test(`médico ${path}`, async ({ page }) => {
      await page.goto(path);
      expect(serious(await scan(page, path))).toEqual([]);
    });
  }
});

test.describe("trabajador (celular)", () => {
  test.use({ user: "trabajador", viewport: { width: 390, height: 844 } });
  for (const path of ["/mi", "/mi/pendientes", "/mi/fotocheck", "/mi/reportar", "/mi/capacitaciones", "/mi/epp", "/mi/salud", "/mi/ficha"]) {
    test(`trabajador ${path}`, async ({ page }) => {
      await page.goto(path);
      expect(serious(await scan(page, path))).toEqual([]);
    });
  }
});

test.describe("plataforma", () => {
  test.use({ user: "plataforma" });
  for (const path of ["/plataforma", "/plataforma/cuentas", "/plataforma/planes", "/plataforma/proveedores", "/plataforma/salud", "/plataforma/sitio"]) {
    test(`plataforma ${path}`, async ({ page }) => {
      await page.goto(path);
      expect(serious(await scan(page, path))).toEqual([]);
    });
  }
});
