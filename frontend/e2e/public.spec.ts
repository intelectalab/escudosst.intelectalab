// Sitio público: portada, planes, ayuda, un curso (si hay) y el asistente del chat. Sin sesión.

import { API_URL, expect, expectHealthy, test, waitForHydration } from "./fixtures";
import { psql } from "./support/auth";

test.use({ user: null });

const PAGES = ["/", "/planes", "/ayuda", "/complementos", "/preguntas-frecuentes", "/como-funciona", "/confianza"];

for (const path of PAGES) {
  test(`página ${path}`, async ({ page }) => {
    const res = await page.goto(path, { waitUntil: "domcontentloaded" });
    expect(res?.status(), "respuesta del servidor").toBeLessThan(400);
    await expect(page.getByRole("heading", { level: 1 }).first()).toBeVisible();
    await expectHealthy(page);
    // Enlaces internos sin destino roto en la cabecera.
    const empty = await page.locator('header a[href=""], header a:not([href])').count();
    expect(empty).toBe(0);
  });
}

test("catálogo público de cursos de una empresa (y un curso, si hay)", async ({ page }) => {
  // Empresas con catálogo publicado: la primera que responda; si ninguna vende cursos, se revisa la página de aviso.
  const ids = psql("select coalesce(catalog_slug, id::text) from companies where deleted_at is null order by created_at limit 10").split(/\s+/).filter(Boolean);
  let catalog = ids[0];
  let offer: string | null = null;
  for (const id of ids) {
    const res = await page.request.get(`${API_URL}/public/courses/${id}`, { headers: { Accept: "application/json" } });
    if (!res.ok()) continue;
    catalog = id;
    const body = (await res.json()) as { data: { offers: { slug?: string; id: string }[] } };
    offer = body.data.offers[0]?.slug ?? body.data.offers[0]?.id ?? null;
    break;
  }
  const res = await page.goto(`/cursos/${catalog}`, { waitUntil: "domcontentloaded" });
  expect(res?.status()).toBeLessThan(500);
  await expect(page.getByRole("heading").first()).toBeVisible();
  await expectHealthy(page);
  if (offer) {
    await page.goto(`/cursos/${catalog}/${offer}`, { waitUntil: "domcontentloaded" });
    await expect(page.getByRole("heading", { level: 1 }).first()).toBeVisible();
    await expectHealthy(page);
  }
});

test("el asistente del chat responde", async ({ page }) => {
  await page.goto("/", { waitUntil: "domcontentloaded" });
  await waitForHydration(page, 'button[aria-label="Abrir el chat"]');
  const chat = page.getByRole("dialog", { name: "Chat de EscudoSST" });
  // Se reintenta el clic hasta que la página termine de hidratarse.
  await expect(async () => {
    if (!(await chat.isVisible())) await page.getByRole("button", { name: "Abrir el chat" }).click();
    await expect(chat).toBeVisible({ timeout: 3_000 });
  }).toPass({ timeout: 60_000 });
  const bubbles = chat.locator("p.whitespace-pre-line");
  const before = await bubbles.count();
  await chat.getByPlaceholder("Escribe tu pregunta").fill("¿Cuánto cuesta el plan Profesional?");
  await chat.getByRole("button", { name: "Enviar" }).click();
  await expect(chat.getByText("¿Cuánto cuesta el plan Profesional?")).toBeVisible();
  // Burbuja propia + respuesta del asistente.
  await expect.poll(() => bubbles.count(), { timeout: 60_000 }).toBeGreaterThanOrEqual(before + 2);
  const reply = (await bubbles.last().textContent()) ?? "";
  expect(reply.trim().length).toBeGreaterThan(3);
  expect(reply).not.toMatch(/No pude responder|No pude conectarme/);
  await chat.getByRole("button", { name: "Cerrar el chat" }).click();
  await expect(chat).toBeHidden();
});
