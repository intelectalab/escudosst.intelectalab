// E2E-03 (docs/12 §5): elección del comité → convocatoria → candidatos → votación de trabajadores en la PWA → resultados
// → miembros.
// En la empresa del plan Profesional (con Comité SST): el Jefe SST convoca la elección, abre la inscripción, inscribe y
// habilita a dos candidatos y abre la votación; los trabajadores sin usuario votan desde el celular con su enlace de un
// solo uso (sin sesión; el enlace no sirve dos veces); durante la votación no hay resultados; al cerrar se escruta, se
// genera el acta y el elegido aparece en «Comité vigente».
// Al final se borran (lógicamente) la elección, los miembros que agregó y los trabajadores de prueba.

import { expect, expectHealthy, settle, test } from "../fixtures";
import { api, openAs, psql, uniq, whoByEmail } from "./helpers";

const ANDINAS = "01900000-0000-7000-8000-000000000101";

test.use({ user: null });

test("E2E-03 · elección del comité: convocatoria → candidatos → votación por enlace → resultados → miembros", async ({ page, browser }) => {
  test.setTimeout(40 * 60_000);
  test.skip(
    psql(`select count(*) from elections where company_id = '${ANDINAS}' and deleted_at is null and status not in ('results_published', 'cancelled', 'annulled')`) !== "0",
    "La empresa de demostración ya tiene una elección en curso (no se toca).",
  );
  const jefe = whoByEmail("profesional@escudo.test", ANDINAS);
  const tag = uniq("E2E03").split(" ")[1];
  const surname = `Votante${tag}`;
  const base = 71000000 + Math.floor(Math.random() * 8_000_000);
  const names = ["Ana", "Bruno", "Carla"];
  const workers: string[] = [];
  let election = "";

  // La sesión del Jefe SST en la pestaña principal.
  await page.addInitScript(([token, company]) => {
    if (window.sessionStorage.getItem("escudo.e2e")) return;
    window.sessionStorage.setItem("escudo.e2e", "1");
    window.sessionStorage.setItem("escudo.token", token);
    window.sessionStorage.setItem("escudo.companyId", company);
    try {
      window.localStorage.setItem("escudo.updates.seen", String(Date.now()));
    } catch {
      /* sin almacenamiento */
    }
  }, [jefe.token, ANDINAS] as const);

  try {
    // Tres trabajadores de prueba sin usuario de la app (votarán por enlace).
    for (const [i, n] of names.entries()) {
      const w = (await api(page.request, jefe, "/records/TRB/workers", { method: "POST", data: { document_number: String(base + i), first_name: n, paternal_last_name: surname } })).data.id as string;
      workers.push(w);
    }
    psql(`update workers set hire_on = '2015-01-01', birth_on = '1990-01-01' where id in (${workers.map((w) => `'${w}'`).join(",")})`);

    // ------------------------------------------------ 1. Convocatoria
    await test.step("el Jefe SST convoca la elección", async () => {
      await page.goto("/m/com", { waitUntil: "domcontentloaded" });
      await settle(page);
      await page.getByRole("tablist", { name: "Comité SST" }).getByRole("tab", { name: "Elección electrónica" }).click();
      await page.getByRole("button", { name: "Convocar elección" }).click();
      const modal = page.getByRole("dialog", { name: "Convocar elección" });
      await modal.getByLabel("Nombre de la elección").fill(`Elección ${tag}`);
      await modal.getByLabel(/^Titulares a elegir/).fill("1");
      await modal.getByLabel(/^Suplentes a elegir/).fill("0");
      await modal.getByRole("button", { name: "Publicar convocatoria" }).click();
      await expect(modal).toBeHidden({ timeout: 300_000 });
      await expect(page.getByRole("heading", { name: `Elección ${tag}` })).toBeVisible({ timeout: 240_000 });
      election = psql(`select id from elections where company_id = '${ANDINAS}' and title = 'Elección ${tag}' and deleted_at is null`);
      expect(election).toBeTruthy();
    });

    // ------------------------------------------------ 2. Inscripción y candidatos habilitados
    await test.step("abre la inscripción e inscribe y habilita a dos candidatos", async () => {
      await page.getByRole("button", { name: "Abrir inscripción" }).click();
      await expect(page.getByRole("button", { name: "Inscribir candidato" })).toBeVisible({ timeout: 300_000 });
      for (const n of names.slice(0, 2)) {
        await page.getByRole("button", { name: "Inscribir candidato" }).click();
        const modal = page.getByRole("dialog", { name: "Inscribir candidato" });
        await modal.getByLabel("Buscar trabajador").fill(`${n} ${surname}`);
        await modal.getByRole("button", { name: new RegExp(`${n} ${surname}`) }).click();
        await expect(modal).toBeHidden({ timeout: 300_000 });
        await expect(page.getByText(`${n} ${surname}`).first()).toBeVisible({ timeout: 240_000 });
      }
      for (const n of names.slice(0, 2)) {
        const item = page.getByRole("listitem").filter({ hasText: `${n} ${surname}` });
        await item.getByRole("button", { name: "Habilitar" }).click();
        await expect(item.getByText("Habilitado", { exact: true })).toBeVisible({ timeout: 300_000 });
      }
    });

    // ------------------------------------------------ 3. Votación abierta y credenciales de voto
    const links: string[] = [];
    await test.step("abre la votación y emite los enlaces de voto", async () => {
      await page.getByRole("button", { name: "Abrir votación" }).click();
      const modal = page.getByRole("dialog", { name: "Abrir votación" });
      const closes = new Date(Date.now() + 2 * 86_400_000);
      const local = new Date(closes.getTime() - closes.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
      await modal.locator('input[type="datetime-local"]').fill(local);
      await modal.getByRole("button", { name: "Abrir votación" }).click();
      await expect(modal).toBeHidden({ timeout: 300_000 });
      await expect(page.getByRole("button", { name: "Cerrar votación y escrutar" })).toBeVisible({ timeout: 240_000 });

      await api(page.request, jefe, `/com/elections/${election}/credentials`, { method: "POST", data: {} });
      const list = (await api(page.request, jefe, `/com/elections/${election}/credentials`)).data as { worker_id: string; path: string | null }[];
      for (const w of workers) {
        const row = list.find((r) => r.worker_id === w);
        expect(row?.path, "enlace de voto del trabajador").toMatch(/^\/votar\//);
        links.push(row!.path!);
      }
    });

    // ------------------------------------------------ 4. Los trabajadores votan desde el celular, sin sesión
    await test.step("los trabajadores votan con su enlace de un solo uso", async () => {
      const choices = [`Ana ${surname}`, `Ana ${surname}`, "Voto en blanco"];
      for (const [i, link] of links.entries()) {
        const { context, page: phone } = await openAs(browser, null);
        try {
          await phone.setViewportSize({ width: 390, height: 844 });
          await phone.goto(link, { waitUntil: "domcontentloaded" });
          await expect(phone.getByRole("heading", { name: `Elección ${tag}` })).toBeVisible({ timeout: 240_000 });
          await expect(phone.getByText(`Hola, ${names[i]}.`)).toBeVisible();
          await phone.getByRole("radio", { name: new RegExp(choices[i]) }).click();
          await phone.getByRole("button", { name: "Emitir mi voto" }).click();
          await phone.getByRole("dialog", { name: "Confirmar voto" }).getByRole("button", { name: "Confirmar voto" }).click();
          await expect(phone.getByText(`Listo, ${names[i]}: tu voto quedó registrado.`)).toBeVisible({ timeout: 240_000 });
          await expect(phone.getByText(/Comprobante: [A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/)).toBeVisible();
          // El mismo enlace no sirve dos veces.
          await phone.reload({ waitUntil: "domcontentloaded" });
          await expect(phone.getByText("Este enlace ya se usó: ya se votó con él.")).toBeVisible({ timeout: 240_000 });
        } finally {
          await context.close();
        }
      }
      // Durante la votación: participación sí, resultados no.
      const live = (await api(page.request, jefe, `/com/elections/${election}`)).data;
      expect(live.votes_cast).toBe(3);
      expect(live.results).toBeNull();
    });

    // ------------------------------------------------ 5. Escrutinio, acta y miembros del comité
    await test.step("cierra la votación, genera el acta y el elegido queda en el comité", async () => {
      await page.reload({ waitUntil: "domcontentloaded" });
      await settle(page);
      await page.getByRole("tablist", { name: "Comité SST" }).getByRole("tab", { name: "Elección electrónica" }).click();
      await page.getByRole("button", { name: "Cerrar votación y escrutar" }).click();
      await page.getByRole("dialog", { name: "Cerrar votación y escrutar" }).getByRole("button", { name: "Cerrar y escrutar" }).click();
      await expect(page.getByRole("button", { name: "Generar acta" })).toBeVisible({ timeout: 300_000 });
      const winner = page.getByText(`Ana ${surname}`).first();
      await expect(winner).toBeVisible();
      await expect(page.locator("li, div").filter({ hasText: `Ana ${surname}` }).getByText("Titular", { exact: true }).first()).toBeVisible();
      await page.getByRole("button", { name: "Generar acta" }).click();
      await expect(page.getByText(/Resultados publicados el .*Los elegidos se registraron en el comité/)).toBeVisible({ timeout: 300_000 });
      await expect(page.getByRole("link", { name: "Descargar acta en PDF" })).toBeVisible();

      await page.getByRole("tablist", { name: "Comité SST" }).getByRole("tab", { name: "Comité vigente" }).click();
      await expect(page.getByText(`Ana ${surname}`).first()).toBeVisible({ timeout: 240_000 });
      expect(psql(`select member_type from committee_members where election_id = '${election}' and worker_id = '${workers[0]}' and deleted_at is null`)).toBe("titular");
      await expectHealthy(page);
    });
  } finally {
    if (election) {
      psql(`update committee_members set deleted_at = now() where election_id = '${election}'`);
      psql(`update elections set deleted_at = now() where id = '${election}'`);
    }
    if (workers.length) psql(`update workers set deleted_at = now() where id in (${workers.map((w) => `'${w}'`).join(",")})`);
  }
});
