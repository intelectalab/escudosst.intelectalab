// Ingreso real (sin completarlo): la pantalla de ingreso, sus errores y el orden de los métodos de verificación.
// Usa la contraseña de demostración de los datos de prueba (backend/tests/Pest.php, DEMO_PASSWORD) o E2E_PASSWORD.

import { expect, expectHealthy, test, waitForHydration } from "./fixtures";

test.use({ user: null });

const PASSWORD = process.env.E2E_PASSWORD ?? "EscudoDemo2026";

test("pantalla de ingreso y paso de verificación en dos pasos", async ({ page }) => {
  await page.goto("/login", { waitUntil: "domcontentloaded" });
  await waitForHydration(page, 'button[type="submit"]');
  await expectHealthy(page);
  await expect(page.getByText("Correo o DNI")).toBeVisible();
  await expect(page.getByText("Contraseña", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: /Olvidaste|recuperar/i }).first()).toBeVisible();

  const email = page.getByPlaceholder("nombre@empresa.com o 12345678");
  const password = page.locator('input[autocomplete="current-password"], input[type="password"]').first();
  await email.fill("corporativo@escudo.test");
  await password.fill(PASSWORD);
  await page.getByRole("button", { name: "Ingresar", exact: true }).click();

  // Primer método: el código de la app; luego las alternativas en orden (correo, recuperación).
  await expect(page.getByRole("heading", { name: "Verificación en dos pasos" })).toBeVisible({ timeout: 120_000 });
  await expect(page.getByText("Abre tu app de autenticación y escribe el código de EscudoSST.")).toBeVisible();
  await expect(page.getByText("Código de 6 dígitos")).toBeVisible();
  const options = page.locator("form button b");
  await expect(options).toHaveText(["Código por correo", "Código de recuperación"]);

  // Cambiar a «Código de recuperación» muestra su campo y deja volver al código de la app (sin enviar correos).
  await page.getByRole("button", { name: /Código de recuperación/ }).click();
  await expect(page.getByPlaceholder("XXXX-XXXX")).toBeVisible();
  await expect(options).toHaveText(["Código de la app", "Código por correo"]);
  await page.getByRole("button", { name: /Código de la app/ }).click();
  await expect(page.getByText("Código de 6 dígitos")).toBeVisible();
  // No se completa el ingreso.
  await expect(page).toHaveURL(/\/login/);
});

test("credenciales incorrectas muestran un error en castellano", async ({ page }) => {
  await page.goto("/login", { waitUntil: "domcontentloaded" });
  await waitForHydration(page, 'button[type="submit"]');
  await page.getByPlaceholder("nombre@empresa.com o 12345678").fill("no-existe-e2e@escudo.test");
  await page.locator('input[type="password"]').first().fill("ClaveIncorrecta123");
  await page.getByRole("button", { name: "Ingresar", exact: true }).click();
  const err = page.locator("main div[role=alert], form div[role=alert], div.bg-bad-soft[role=alert]").first();
  await expect(err).toBeVisible({ timeout: 120_000 });
  await expect(err).not.toHaveText(/\b(The|invalid|credentials)\b/);
  await expect(page).toHaveURL(/\/login/);
});

test("sin sesión, la aplicación lleva al ingreso", async ({ page }) => {
  await page.goto("/inicio", { waitUntil: "domcontentloaded" });
  await expect(page).toHaveURL(/\/login/, { timeout: 120_000 });
});
