// E2E-19 (docs/12 §5) · Recomendación: certificado por vencer → regla de recapacitación → «Recomendados para ti» → envío
// solo con consentimiento y dentro del límite → matrícula → conversión registrada (M-AUL RN-AUL-24..33, M-VTA SCR-VTA-13).
// Tres participantes externos con el certificado del curso por vencer: Ana acepta publicidad, Beto no y Carla sí, pero ya
// recibió un correo en el periodo (límite de 1 por persona). Solo a Ana se le envía por correo; a los otros dos, solo en el
// portal. Ana entra a su portal, abre la recomendación, compra de nuevo y la venta queda atribuida a la recomendación.

import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { api, day, openAs, whoByEmail } from "./helpers";
import { cleanupSales, HUAYLAS, lit, one, orderByNumber, payOrder, psqlRows, seedCertificate, seedOffer, sellAndPay, slugSuffix, type Seeded } from "./_vta";

test.use({ user: "jefe" });

type Person = { key: "ana" | "beto" | "carla"; email: string; learner: string; document: string };

test.describe.serial("E2E-19 · Recomendación de recapacitación con consentimiento y límite", () => {
  const sfx = slugSuffix();
  const mail = (k: string) => `e2e19.${k}.${sfx}@example.test`;
  let seeded: Seeded | null = null;
  let ruleId = "";
  const people: Record<string, Person> = {};
  let orderNumber = "";

  test.beforeAll(() => {
    seeded = seedOffer(sfx, { title: `Manejo defensivo E2E ${sfx}` });
  });
  test.afterAll(() => {
    if (ruleId) one(`update recommendation_rules set is_active = false, deleted_at = now() where id = ${lit(ruleId)} returning id`);
    cleanupSales(seeded, `e2e19.%.${sfx}@%`);
  });

  test("participantes con el certificado por vencer y la regla de recapacitación", async ({ page, apiToken, companyId }) => {
    test.setTimeout(6 * 60_000);
    const jefe = { token: apiToken!, company: companyId };
    for (const [key, marketing] of [["ana", true], ["beto", false], ["carla", true]] as const) {
      const s = await sellAndPay(page.request, jefe, seeded!.offer, { email: mail(key), first: key[0].toUpperCase() + key.slice(1), last: `Recap ${sfx}`, marketing });
      seedCertificate(seeded!, s.enrollment, s.learner, `${key} Recap ${sfx}`, day(10));
      people[key] = { key, email: mail(key), learner: s.learner, document: s.document };
    }
    // Carla ya recibió un correo de recomendación ayer (otro motivo): llegó al límite del periodo.
    one(`insert into course_recommendations (company_id, course_id, external_learner_id, source, recommended_by, target_scope, reason_code, message, channel, status, sent_at, dedupe_key)
         values (${lit(HUAYLAS)}, ${lit(seeded!.course)}, ${lit(people.carla.learner)}, 'manual', (select id from users where email = 'corporativo@escudo.test'), 'person', 'manual', 'Correo anterior', 'email', 'expired', now() - interval '1 day', ${lit(`e2e19-prev-${sfx}`)}) returning id`);

    const created = await api<{ data: { id: string } }>(page.request, jefe, "/aul/admin/rules", {
      method: "POST",
      data: {
        code: `E2E19-${sfx}`.slice(0, 30), name: `Recapacitación E2E ${sfx}`, rule_type: "certificate_expiring", audience: "external_learners", course_ids: [seeded!.course],
        channels: ["email"], max_sends_per_person: 1, send_period_days: 30, requires_consent: true, is_active: true, parameters: { lead_days: 30 },
      },
    });
    ruleId = created.data.id;

    // Vista previa: a quién le llegaría el correo y a quién solo el portal.
    const dry = await api<{ data: { reached: number; created: number; sent_external: number; portal_only: number; preview: { name: string; channel: string }[] } }>(
      page.request, jefe, `/aul/admin/rules/${ruleId}/run`, { method: "POST", data: { dry_run: true } });
    expect(dry.data).toMatchObject({ reached: 3, created: 3, sent_external: 1, portal_only: 2 });
    expect(one(`select count(*) from course_recommendations where rule_id = ${lit(ruleId)}`)).toBe("0");

    const run = await api<{ data: { created: number; sent_external: number; portal_only: number } }>(page.request, jefe, `/aul/admin/rules/${ruleId}/run`, { method: "POST", data: {} });
    expect(run.data).toMatchObject({ created: 3, sent_external: 1, portal_only: 2 });
    const channels = Object.fromEntries(psqlRows(`select external_learner_id, channel, status, reason_code from course_recommendations where rule_id = ${lit(ruleId)}`).map(([l, ch, st, why]) => [l, { ch, st, why }]));
    expect(channels[people.ana.learner]).toEqual({ ch: "email", st: "sent", why: "certificate_expiring" });
    expect(channels[people.beto.learner].ch).toBe("in_app");   // sin consentimiento: solo portal
    expect(channels[people.carla.learner].ch).toBe("in_app");  // límite de envíos alcanzado: solo portal

    // Correr la regla otra vez no repite la recomendación.
    const again = await api<{ data: { created: number } }>(page.request, jefe, `/aul/admin/rules/${ruleId}/run`, { method: "POST", data: {} });
    expect(again.data.created).toBe(0);
  });

  test("Ana ve «Recomendados para ti», abre el curso y lo vuelve a comprar", async ({ browser }) => {
    test.setTimeout(8 * 60_000);
    const { context, page } = await openAs(browser, whoByEmail(people.ana.email, HUAYLAS));
    try {
      await page.goto("/m/vta", { waitUntil: "domcontentloaded" });
      await settle(page, 180_000);
      await expect(page.getByRole("heading", { name: "Recomendados para ti" })).toBeVisible({ timeout: 120_000 });
      const card = page.locator("article", { hasText: seeded!.title });
      await expect(card).toContainText(/Tu certificado de «.*» vence/);
      await expectHealthy(page);
      await card.getByRole("link", { name: "Ver curso y comprar" }).click();
      await expect(page).toHaveURL(new RegExp(`/cursos/${HUAYLAS}/${seeded!.slug}$`), { timeout: 120_000 });
      await expect(page.getByRole("heading", { level: 1, name: seeded!.title })).toBeVisible({ timeout: 120_000 });
      await waitForHydration(page, "form button[type=submit]");
      const form = page.locator("form").filter({ has: page.getByRole("button", { name: "Solicitar compra" }) });
      await form.getByLabel("Nombres").fill("Ana");
      await form.getByLabel("Apellidos").fill(`Recap ${sfx}`);
      await form.getByLabel("Número").fill(people.ana.document);
      await form.getByLabel("Correo").fill(people.ana.email);
      await form.getByText(/Acepto que .* use mis datos/).click();
      await form.getByRole("button", { name: "Solicitar compra" }).click();
      await expect(page.getByRole("heading", { name: "Recibimos tu pedido" })).toBeVisible({ timeout: 60_000 });
      orderNumber = (await page.locator("b.font-mono").first().textContent())?.trim() ?? "";
    } finally {
      await context.close();
    }
    expect(one(`select status from course_recommendations where rule_id = ${lit(ruleId)} and external_learner_id = ${lit(people.ana.learner)}`)).toBe("clicked");
  });

  test("al pagar se matricula y la conversión queda registrada en el reporte", async ({ page, apiToken, companyId }) => {
    test.setTimeout(6 * 60_000);
    const o = orderByNumber(orderNumber);
    await payOrder(page.request, { token: apiToken!, company: companyId }, o.id);
    const enrollment = one(`select e.id from course_enrollments e join order_items i on i.id = e.order_item_id where i.order_id = ${lit(o.id)}`);
    expect(enrollment).not.toBe("");
    const [st, conv] = psqlRows(`select status, converted_enrollment_id from course_recommendations where rule_id = ${lit(ruleId)} and external_learner_id = ${lit(people.ana.learner)}`)[0];
    expect(st).toBe("converted");
    expect(conv).toBe(enrollment);
    // Las de Beto y Carla no se convierten.
    expect(one(`select count(*) from course_recommendations where rule_id = ${lit(ruleId)} and status = 'converted'`)).toBe("1");

    await page.goto("/m/vta", { waitUntil: "domcontentloaded" });
    await settle(page, 180_000);
    const conversion = page.locator("div.rounded-xl", { hasText: "Conversión de recomendaciones y campañas" }).last();
    await expect(conversion).toBeVisible({ timeout: 120_000 });
    await conversion.getByRole("button", { name: "Por pedido" }).click();
    await expect(conversion.locator("tr", { hasText: orderNumber })).toContainText(seeded!.title, { timeout: 60_000 });
    await expectHealthy(page);
  });
});
