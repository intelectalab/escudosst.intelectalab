// E2E-06 (docs/12 §5): accidente → registro → alerta de plazo → notificación registrada → investigación → cierre.
// El Jefe SST registra el accidente en la pantalla, ve el plazo de notificación y registra la constancia; la
// investigación (equipo, causas y acción correctiva) se arma por la API, el responsable sube la evidencia y otra
// persona la verifica; luego el Jefe SST aprueba y cierra la investigación y cierra el evento en la pantalla.

import { expect, expectHealthy, test } from "../fixtures";
import { api, apiRaw, day, minimalPdf, uniq, uploadFile, who } from "./helpers";
import { demoSite, demoWorkers, fillForm, localDateTime, openModule, openNew, openRecord, recordsSection, sqlIn, submitNew, userId } from "./b-records";

test.use({ user: "jefe" });

test("E2E-06 accidente: registro, plazo, notificación, investigación y cierre", async ({ page, request }) => {
  test.setTimeout(12 * 60_000);
  const jefe = who("jefe");
  const otro = who("aprobador");
  const company = jefe.company!;
  const tag = uniq("E2E06");
  const site = demoSite(company);
  const [injured, leader, rep] = demoWorkers(company, 3);
  let eventId = "";
  let eventCode = "";

  try {
    await test.step("registra el accidente en la pantalla", async () => {
      await openModule(page, "ACC");
      const section = await recordsSection(page);
      const form = await openNew(page, section, "Registrar evento");
      await fillForm(form, {
        "Tipo de evento": "Accidente incapacitante",
        "Ocurrido el": localDateTime(-2),
        Sede: site.name,
        Descripción: `${tag} Corte en la mano al manipular una plancha metálica`,
      });
      await submitNew(form);
      const list = await api<{ data: { id: string; code: string; notification_deadline_at: string | null; status: string }[] }>(request, jefe, `/records/ACC/sst_events?q=${encodeURIComponent(tag)}`);
      expect(list.data).toHaveLength(1);
      eventId = list.data[0].id;
      eventCode = list.data[0].code;
      expect(list.data[0].code).toMatch(/^ACC-/);
      expect(list.data[0].notification_deadline_at, "el accidente incapacitante tiene plazo de notificación").not.toBeNull();
    });

    await test.step("la ficha muestra la alerta de plazo", async () => {
      const section = await recordsSection(page);
      const ficha = await openRecord(page, section, eventCode);
      const alert = ficha.getByRole("status").filter({ hasText: "Plazo de notificación" });
      await expect(alert).toBeVisible();
      await expect(alert).toContainText(/quedan \d+ (h|días)/);
      await expect(ficha.getByRole("button", { name: "Cerrar evento" })).toBeVisible();
      await page.keyboard.press("Escape");
      await expect(ficha).toBeHidden();
    });

    await test.step("registra la notificación con su constancia", async () => {
      const notifs = await api<{ data: { id: string; status: string }[] }>(request, jefe, `/records/ACC/event_notifications?f[event_id]=${eventId}`);
      expect(notifs.data.length, "el accidente genera sus notificaciones").toBeGreaterThan(0);
      // Las notificaciones se ven dentro de la ficha del evento; su ficha trae «Registrar notificación».
      const event = await openRecord(page, await recordsSection(page), eventCode);
      const tab = event.locator('[aria-label="Partes de la ficha"]').getByRole("tab", { name: /Notificaci/ });
      if (await tab.count()) await tab.first().click();
      const nested = event.locator('section[data-records="event_notifications"]');
      await expect(nested.locator("tbody tr").first()).toBeVisible({ timeout: 60_000 });
      await nested.locator("tbody tr").first().click();
      const ficha = page.getByRole("dialog").filter({ has: page.locator('[aria-label="Partes de la ficha"]') }).filter({ has: page.getByLabel("Número de constancia") }).last();
      await expect(ficha).toBeVisible({ timeout: 90_000 });
      await ficha.getByLabel("Número de constancia").fill(`SAT-${tag.split(" ")[1]}`);
      await ficha.getByRole("button", { name: "Registrar notificación" }).click();
      await expect.poll(async () => (await api<{ data: { status: string } }>(request, jefe, `/records/ACC/event_notifications/${notifs.data[0].id}`)).data.status, { timeout: 60_000 }).toBe("sent");
      await page.keyboard.press("Escape");
      // Las demás notificaciones obligatorias (si hay) se registran por la API.
      for (const n of notifs.data.slice(1)) {
        if (n.status !== "pending" && n.status !== "overdue") continue;
        await api(request, jefe, `/records/ACC/event_notifications/${n.id}`, { method: "PATCH", data: { status: "sent", notified_at: new Date().toISOString(), notification_number: `N-${tag.split(" ")[1]}` } });
      }
    });

    let invId = "";
    await test.step("arma la investigación: afectado, equipo, causas y acción", async () => {
      await api(request, jefe, "/records/ACC/event_people", {
        method: "POST",
        data: { event_id: eventId, worker_id: injured.id, person_role: "injured", lost_days: 3, injury_type: "Corte", body_part: "Mano", injury_agent: "Plancha metálica" },
      });
      const inv = await api<{ data: { id: string }[] }>(request, jefe, `/records/INV/investigations?f[event_id]=${eventId}`);
      expect(inv.data.length, "el accidente incapacitante abre su investigación").toBe(1);
      invId = inv.data[0].id;
      await api(request, jefe, "/records/INV/investigation_causes", { method: "POST", data: { investigation_id: invId, cause_level: "immediate", cause_category: "unsafe_act", description: "No usó guantes anticorte" } });
      const basic = await api<{ data: { id: string } }>(request, jefe, "/records/INV/investigation_causes", {
        method: "POST",
        data: { investigation_id: invId, cause_level: "basic", cause_category: "job_factor", description: "Procedimiento de manipulación sin actualizar" },
      });
      await api(request, jefe, "/records/INV/investigation_team", { method: "POST", data: { investigation_id: invId, worker_id: leader.id, role: "leader" } });
      await api(request, jefe, "/records/INV/investigation_team", { method: "POST", data: { investigation_id: invId, worker_id: rep.id, role: "committee_representative" } });
      const action = await api<{ data: { id: string } }>(request, jefe, `/acr/from/investigation_causes/${basic.data.id}`, {
        method: "POST",
        data: { title: `${tag} Actualizar el procedimiento de manipulación`, responsible_user_id: userId("revb.aprobador@escudo.test"), due_on: day(10) },
      });
      await api(request, jefe, `/records/INV/investigations/${invId}`, { method: "PATCH", data: { conclusions: `${tag}: faltó el uso de guantes y el procedimiento estaba desactualizado.` } });

      // El responsable sube la evidencia y la pasa a verificación; la verifica otra persona (el Jefe SST).
      const file = await uploadFile(request, otro, "ACR", "procedimiento.pdf", "application/pdf", minimalPdf("Procedimiento actualizado"));
      await api(request, otro, "/records/ACR/action_evidences", { method: "POST", data: { corrective_action_id: action.data.id, evidence_type: "document", description: "Procedimiento actualizado", file_id: file } });
      await api(request, otro, `/records/ACR/corrective_actions/${action.data.id}`, { method: "PATCH", data: { status: "pending_verification" } });
      const self = await apiRaw(request, otro, `/records/ACR/corrective_actions/${action.data.id}`, { method: "PATCH", data: { status: "verified", effectiveness: "effective" } });
      expect(self.status, "el responsable no verifica su propia acción").toBe(422);
      await api(request, jefe, `/records/ACR/corrective_actions/${action.data.id}`, { method: "PATCH", data: { status: "verified", effectiveness: "effective" } });
    });

    await test.step("aprueba y cierra la investigación en la pantalla", async () => {
      await openModule(page, "INV");
      const section = await recordsSection(page);
      const code = (await api<{ data: { code: string } }>(request, jefe, `/records/INV/investigations/${invId}`)).data.code;
      let ficha = await openRecord(page, section, code);
      await ficha.getByRole("button", { name: "Aprobar investigación" }).click();
      await expect.poll(async () => (await api<{ data: { status: string } }>(request, jefe, `/records/INV/investigations/${invId}`)).data.status, { timeout: 60_000 }).toBe("approved");
      ficha = page.getByRole("dialog").filter({ has: page.locator('[aria-label="Partes de la ficha"]') }).last();
      await ficha.getByRole("button", { name: "Cerrar investigación" }).click();
      await expect.poll(async () => (await api<{ data: { status: string } }>(request, jefe, `/records/INV/investigations/${invId}`)).data.status, { timeout: 60_000 }).toBe("closed");
      await page.keyboard.press("Escape");
    });

    await test.step("cierra el evento en la pantalla", async () => {
      await openModule(page, "ACC");
      const section = await recordsSection(page);
      const ficha = await openRecord(page, section, eventCode);
      await ficha.getByRole("button", { name: "Cerrar evento" }).click();
      await expect.poll(async () => (await api<{ data: { status: string } }>(request, jefe, `/records/ACC/sst_events/${eventId}`)).data.status, { timeout: 60_000 }).toBe("closed");
      await expect(ficha.getByText("Evento cerrado: queda como registro.").first()).toBeVisible({ timeout: 60_000 });
      await expectHealthy(page);
    });
  } finally {
    // Los eventos cerrados no se borran desde la aplicación: se retiran de la demostración directamente en la base.
    if (eventId) {
      try {
        sqlIn(company, `update investigations set deleted_at = now() where event_id = '${eventId}'; update sst_events set deleted_at = now() where id = '${eventId}'`);
      } catch {
        /* queda marcado con el sufijo E2E06 */
      }
    }
  }
});
