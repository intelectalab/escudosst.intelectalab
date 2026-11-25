// E2E-01 (docs/12 §5): registro autoservicio con RUC → carga de trabajadores por Excel → asistente de línea base → panel
// con brechas.
// Una empresa nueva se registra sola en «Prueba gratis», su administrador crea la contraseña desde la invitación, entra
// por primera vez activando la verificación en dos pasos (código TOTP de la app) e invita al Jefe SST, que carga al
// personal con la plantilla (CSV), hace el diagnóstico de línea base con el asistente, lo aprueba y ve las brechas en el
// panel (el administrador de cuenta gestiona usuarios y plan, no los registros SST).
// El correo de la invitación no se envía de verdad (trial-company.ts): el alta se procesa con el correo simulado.

import { expect, expectHealthy, settle, test, waitForHydration } from "../fixtures";
import { api, freshTotpWindow, openAs, PASSWORD, psql, totp, uniq, whoByEmail } from "./helpers";
import { cleanupTrialCompany, forwardWithoutMail, postWithoutMail, validRuc } from "./trial-company";

test.use({ user: null });

test("E2E-01 · registro con RUC → trabajadores por Excel → línea base → panel con brechas", async ({ page, browser }) => {
  test.setTimeout(40 * 60_000);
  const tag = uniq("E2E01").replace(" ", "-").toLowerCase();
  const ruc = validRuc();
  const prefix = `${tag}.`;
  const email = `${prefix}admin@prueba.test`;
  const legal = `Metalmecánica ${tag.toUpperCase()} S.A.C.`;

  try {
    // ------------------------------------------------ 1. «Prueba gratis» con el RUC
    let invite = "";
    await test.step("la empresa se registra sola con su RUC", async () => {
      await page.route("**/api/v1/public/trial", async (route) => {
        const r = await postWithoutMail("/api/v1/public/trial", route.request().postDataJSON());
        invite = r.urls[0] ?? "";
        await route.fulfill({ status: r.status, contentType: "application/json", body: JSON.stringify(r.body) });
      });
      await page.goto("/prueba-gratis", { waitUntil: "domcontentloaded" });
      await expect(page.getByRole("heading", { level: 1, name: "Prueba EscudoSST con tu propia empresa" })).toBeVisible();
      await waitForHydration(page, 'form button[type="submit"]');
      const form = page.locator("form").filter({ hasText: "Crear mi cuenta de prueba" });
      // RUC con el último dígito equivocado: lo avisa antes de enviar.
      const bad = ruc.slice(0, 10) + ((Number(ruc[10]) + 1) % 10);
      await form.getByRole("textbox", { name: /^RUC/ }).fill(bad);
      await expect(form.getByText("Revisa el RUC: el último dígito no coincide.")).toBeVisible();
      await form.getByRole("textbox", { name: /^RUC/ }).fill(ruc);
      await form.getByRole("textbox", { name: /^Razón social/ }).fill(legal);
      await form.getByRole("textbox", { name: /^¿Cuántos trabajadores tiene\?/ }).fill("12");
      await form.getByRole("textbox", { name: /^Nombre y apellidos/ }).fill("Elena Registro Paz");
      await form.getByRole("textbox", { name: /^Correo de trabajo/ }).fill(email);
      await form.getByRole("checkbox").first().check();
      await form.getByRole("button", { name: "Crear mi cuenta de prueba" }).click();
      await expect(page.getByText("¡Listo! Revisa tu correo")).toBeVisible({ timeout: 240_000 });
      await expect(page.getByText(email)).toBeVisible();
      await page.unroute("**/api/v1/public/trial");
      expect(invite, "enlace de la invitación al administrador").toContain("/invitacion/");
      expect(psql(`select sst_model from companies where ruc = '${ruc}' and deleted_at is null`)).toBe("supervisor");
    });

    // ------------------------------------------------ 2. Contraseña desde la invitación y primer ingreso con doble factor
    await test.step("crea su contraseña y entra activando la verificación en dos pasos", async () => {
      await page.goto(new URL(invite).pathname, { waitUntil: "domcontentloaded" });
      await expect(page.getByRole("heading", { name: /^Hola, Elena/ })).toBeVisible({ timeout: 240_000 });
      await waitForHydration(page, 'button[type="submit"]');
      await page.locator('input[autocomplete="new-password"]').nth(0).fill(PASSWORD);
      await page.locator('input[autocomplete="new-password"]').nth(1).fill(PASSWORD);
      await page.getByRole("button", { name: "Crear contraseña" }).click();
      await expect(page).toHaveURL(/\/login/, { timeout: 240_000 });

      await waitForHydration(page, 'button[type="submit"]');
      const user = page.getByPlaceholder("nombre@empresa.com o 12345678");
      if (!(await user.inputValue())) await user.fill(email);
      await page.locator('input[type="password"]').first().fill(PASSWORD);
      await page.getByRole("button", { name: "Ingresar", exact: true }).click();
      await expect(page.getByRole("heading", { name: "Activa la verificación en dos pasos" })).toBeVisible({ timeout: 240_000 });
      await page.getByText("¿No puedes escanear? Escribe la clave").click();
      const secret = ((await page.locator("details p.font-mono").textContent()) ?? "").replace(/\s+/g, "");
      expect(secret).toMatch(/^[A-Z2-7]{16,}$/);
      await freshTotpWindow(8);
      await page.locator('input[autocomplete="one-time-code"]').fill(totp(secret));
      await page.getByRole("button", { name: "Activar y continuar" }).click();
      await expect(page.getByRole("heading", { name: "¡Listo! Verificación activada" })).toBeVisible({ timeout: 240_000 });
      await page.getByText("Guardé mis códigos de recuperación en un lugar seguro.").click();
      await page.getByRole("button", { name: "Ya los guardé, continuar" }).click();
      await expect(page).toHaveURL(/\/(inicio|select-company)/, { timeout: 240_000 });
      await expect(page.getByRole("heading", { level: 1, name: /^Hola, Elena/ })).toBeVisible({ timeout: 240_000 });
      await settle(page);
      await expectHealthy(page);
    });

    // ------------------------------------------------ 2b. Invita al Jefe SST de la empresa
    const jefeEmail = `${prefix}jefe@prueba.test`;
    await test.step("el administrador invita al Jefe SST", async () => {
      let invited = "";
      await page.route("**/api/v1/company/users", async (route) => {
        if (route.request().method() !== "POST") return route.continue();
        invited = (await forwardWithoutMail(route, "/api/v1/company/users")).urls[0] ?? "";
      });
      await page.goto("/m/usr", { waitUntil: "domcontentloaded" });
      await settle(page);
      await expect(page.getByRole("button", { name: "Invitar usuario", exact: true })).toBeVisible({ timeout: 240_000 });
      const modal = page.getByRole("dialog", { name: "Invitar usuario" });
      // El clic se reintenta hasta que la página termine de hidratarse.
      await expect(async () => {
        if (!(await modal.isVisible())) await page.getByRole("button", { name: "Invitar usuario", exact: true }).click();
        await expect(modal).toBeVisible({ timeout: 5_000 });
      }).toPass({ timeout: 120_000 });
      await modal.getByRole("textbox", { name: /^Correo/ }).fill(jefeEmail);
      await modal.getByRole("textbox", { name: /^Nombre completo/ }).fill("Andrés Jefe Rojas");
      await modal.locator("select").first().selectOption("sst_manager");
      await modal.getByRole("button", { name: "Enviar invitación" }).click();
      await expect(modal).toBeHidden({ timeout: 300_000 });
      await expect(page.getByText(jefeEmail).first()).toBeVisible({ timeout: 240_000 });
      await page.unroute("**/api/v1/company/users");
      expect(invited, "enlace de la invitación al Jefe SST").toContain("/invitacion/");

      // El Jefe SST crea su contraseña desde la invitación.
      const { context, page: inv } = await openAs(browser, null);
      try {
        await inv.goto(new URL(invited).pathname, { waitUntil: "domcontentloaded" });
        await expect(inv.getByRole("heading", { name: /^Hola, Andrés/ })).toBeVisible({ timeout: 240_000 });
        await waitForHydration(inv, 'button[type="submit"]');
        await inv.locator('input[autocomplete="new-password"]').nth(0).fill(PASSWORD);
        await inv.locator('input[autocomplete="new-password"]').nth(1).fill(PASSWORD);
        await inv.getByRole("button", { name: "Crear contraseña" }).click();
        await expect(inv).toHaveURL(/\/login/, { timeout: 240_000 });
      } finally {
        await context.close();
      }
    });

    // El Jefe SST trabaja en su propia sesión (el doble factor real ya se probó con el administrador).
    const admin = whoByEmail(email);
    const jefe = whoByEmail(jefeEmail);
    const { context: jctx, page: jp } = await openAs(browser, jefe);

    // ------------------------------------------------ 3. Personal desde la plantilla (CSV)
    await test.step("carga a sus trabajadores desde la plantilla", async () => {
            const site = psql(`select s.name from sites s join companies c on c.id = s.company_id where c.ruc = '${ruc}' and s.deleted_at is null order by s.created_at limit 1`);
      expect(site, "sede principal creada con el alta").toBeTruthy();
      await api(jp.request, admin, "/records/TEN/positions", { method: "POST", data: { code: "SOLD", name: "Soldador" } });
      const base = 70000000 + Math.floor(Math.random() * 9_000_000);
      const docs = [base, base + 7, base + 14].map(String);
      const head = ["Tipo de documento", "Número de documento", "Nombres", "Apellido paterno", "Apellido materno", "Fecha de nacimiento", "Sexo", "Celular", "Correo", "Fecha de ingreso", "Tipo de vínculo", "Sede", "Área", "Puesto", "Código de empleado"];
      const rows = [
        ["DNI", docs[0], "Rosa", "Quispe", "Mamani", "15/03/1990", "F", "", "", "01/02/2024", "Propio", site, "", "SOLD", "E-1"],
        ["DNI", docs[1], "Jorge", "Huamán", "Ccori", "20/07/1985", "M", "", "", "01/03/2023", "Propio", site, "", "SOLD", "E-2"],
        ["DNI", docs[2], "Lucía", "Torres", "Díaz", "02/11/1995", "F", "", "", "15/06/2025", "Propio", site, "", "SOLD", "E-3"],
        ["DNI", "7030303", "Corto", "Error", "", "", "", "", "", "", "", site, "", "SOLD", ""],
      ];
      // Misma forma que la plantilla vigente (versión TRB-W1): cabecera de versión, instrucciones y la fila de títulos.
      const sheet = [["EscudoSST · Plantilla de trabajadores · versión TRB-W1"], ["Instrucciones"], ["Sedes"], ["Puestos"], head, ...rows];
      const csv = sheet.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(",")).join("\r\n");

      await jp.goto("/m/trb", { waitUntil: "domcontentloaded" });
      await settle(jp);
      await expect(jp.getByRole("button", { name: "Cargar desde Excel" })).toBeVisible({ timeout: 240_000 });
      const wiz = jp.getByRole("dialog", { name: "Cargar desde Excel" });
      await expect(async () => {
        if (!(await wiz.isVisible())) await jp.getByRole("button", { name: "Cargar desde Excel" }).click();
        await expect(wiz).toBeVisible({ timeout: 5_000 });
      }).toPass({ timeout: 120_000 });
      await wiz.getByRole("button", { name: "Siguiente" }).click();
      await wiz.locator('input[type="file"]').setInputFiles({ name: "trabajadores.csv", mimeType: "text/csv", buffer: Buffer.from(csv, "utf8") });
      await wiz.getByRole("button", { name: "Siguiente" }).click();
      await expect(wiz.getByText("3 válida(s)")).toBeVisible({ timeout: 240_000 });
      await expect(wiz.getByText("1 con error")).toBeVisible();
      await wiz.getByRole("button", { name: "Siguiente" }).click();
      await expect(wiz.getByText("Se crearán")).toBeVisible();
      await wiz.getByRole("button", { name: "Importar trabajadores" }).click();
      const created = wiz.locator("div", { has: jp.getByText("Creados", { exact: true }) }).locator("p.font-display").first();
      await expect(created).toHaveText("3", { timeout: 300_000 });
      await wiz.getByRole("button", { name: "Cerrar" }).last().click();
      await expect(jp.getByRole("row", { name: /Rosa Quispe Mamani/ })).toBeVisible({ timeout: 240_000 });
      expect((await api(jp.request, jefe, "/records/TRB/workers")).meta.total).toBe(3);
    });

    // ------------------------------------------------ 4. Asistente de línea base → aprobación → brechas
    let gaps = 0;
    await test.step("hace el diagnóstico de línea base con el asistente y lo aprueba", async () => {
      await jp.goto("/m/lba", { waitUntil: "domcontentloaded" });
      await settle(jp);
      await expect(jp.getByRole("button", { name: "Nuevo diagnóstico" }).first()).toBeVisible({ timeout: 240_000 });
      const wiz = jp.getByRole("dialog", { name: "Nuevo diagnóstico" });
      await expect(async () => {
        if (!(await wiz.isVisible())) await jp.getByRole("button", { name: "Nuevo diagnóstico" }).first().click();
        await expect(wiz).toBeVisible({ timeout: 5_000 });
      }).toPass({ timeout: 120_000 });
      await wiz.getByRole("button", { name: "Siguiente" }).click();   // Datos: crea el borrador y carga la lista base
      let first = true;
      let answered = 0;
      for (let step = 0; step < 40; step++) {
        const confirm = wiz.getByRole("button", { name: "Guardar diagnóstico" });
        const groups = wiz.getByRole("radiogroup");
        // Espera a que termine de guardar el paso anterior (el botón deja de estar ocupado).
        await expect(wiz.getByRole("button", { name: /^(Siguiente|Guardar diagnóstico)$/ })).toBeEnabled({ timeout: 300_000 });
        await expect(groups.first().or(confirm)).toBeVisible({ timeout: 240_000 });
        if (await confirm.isVisible()) break;
        const n = await groups.count();
        for (let i = 0; i < n; i++) {
          // La primera pregunta «No cumple», la segunda «Parcial»; el resto «Cumple».
          const pick = first && i === 0 ? "No cumple" : first && i === 1 ? "Parcial" : "Cumple";
          await groups.nth(i).getByRole("radio", { name: pick, exact: true }).click();
          answered++;
        }
        first = false;
        await wiz.getByRole("button", { name: "Siguiente" }).click();
      }
      expect(answered).toBeGreaterThan(2);
      await expect(wiz.getByText(/Quedan \d+ ítem\(s\) sin responder/)).toHaveCount(0);
      const saved = wiz.getByText(/Diagnóstico guardado · \d+ respuesta\(s\)/);
      const confirm = wiz.getByRole("button", { name: "Guardar diagnóstico" });
      await expect(saved.or(confirm)).toBeVisible({ timeout: 300_000 });
      if (!(await saved.isVisible()) && (await confirm.isEnabled())) await confirm.click();
      await expect(saved).toBeVisible({ timeout: 300_000 });
      await wiz.getByRole("button", { name: "Aprobar y generar brechas" }).click();
      const done = wiz.getByText(/Diagnóstico aprobado\. Se generaron (\d+) brecha\(s\)/);
      await expect(done).toBeVisible({ timeout: 300_000 });
      gaps = Number(((await done.textContent()) ?? "").match(/generaron (\d+)/)?.[1] ?? 0);
      expect(gaps).toBe(2);
      await wiz.getByRole("button", { name: "Cerrar" }).last().click();
    });

    // ------------------------------------------------ 5. Panel con las brechas
    await test.step("el panel muestra el puntaje y las brechas abiertas", async () => {
      await jp.goto("/m/lba", { waitUntil: "domcontentloaded" });
      await settle(jp);
      const card = jp.locator("a, div").filter({ hasText: /^Brechas abiertas/ }).first();
      await expect(card).toContainText(String(gaps), { timeout: 240_000 });
      expect((await api(jp.request, jefe, "/records/LBA/baseline_gaps")).meta.total).toBe(gaps);

      const kpis = (await api(jp.request, jefe, `/dsh/overview?year=${new Date().getFullYear()}`)).data.kpis as { code: string; value: number | null }[];
      const baseline = kpis.find((k) => k.code === "baseline_score");
      expect(baseline?.value ?? null).not.toBeNull();
      await jp.goto("/inicio", { waitUntil: "domcontentloaded" });
      await settle(jp);
      await expectHealthy(jp);
    });
    await jctx.close();
  } finally {
    cleanupTrialCompany(ruc, prefix);
  }
});
