// Datos de los recorridos de venta de cursos (E2E-16…E2E-20) en la empresa de demostración (Constructora Huaylas, con
// AUL y VTA activos): curso publicado con su oferta pública y, si hace falta, un cupón. Todo lleva el sufijo de la
// corrida y se da de baja al terminar (baja lógica: los pedidos y sus pagos se conservan, como en producción).

import type { APIRequestContext } from "@playwright/test";
import { api, DEMO_COMPANY, psql, uploadFile, type Who } from "./helpers";

export const HUAYLAS = DEMO_COMPANY;

/** Texto como literal SQL. */
export const lit = (v: string | number | boolean | null) => (v === null ? "null" : typeof v === "string" ? `'${v.replace(/'/g, "''")}'` : String(v));

/** Una fila: valores separados por «|» (psql -tA). */
export function row(sql: string): string[] {
  const out = psql(sql);
  return out.split(/\r?\n/).filter(Boolean)[0]?.split("|") ?? [];
}

export function one(sql: string): string {
  return row(sql)[0] ?? "";
}

/** Sufijo corto en minúsculas y números (para slugs, códigos y correos). */
export function slugSuffix(): string {
  return `${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`.toLowerCase();
}

/** DNI ficticio de 8 dígitos. */
export const dni = () => String(70_000_000 + Math.floor(Math.random() * 9_999_999));

/** RUC 20xxxxxxxxx con dígito verificador correcto. */
export function ruc(): string {
  const base = "20" + String(Math.floor(Math.random() * 100_000_000)).padStart(8, "0");
  const w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const sum = w.reduce((s, x, i) => s + Number(base[i]) * x, 0);
  const c = 11 - (sum % 11);
  return base + String(c === 10 ? 0 : c === 11 ? 1 : c);
}

export type Seeded = { course: string; offer: string; slug: string; title: string };

/** Curso publicado con su oferta pública (precio S/ 100, admite compra de empresas). */
export function seedOffer(suffix: string, extra: { title?: string; price?: number; minSeats?: number | null } = {}): Seeded {
  const title = extra.title ?? `Espacios confinados E2E ${suffix}`;
  const slug = `e2e-${suffix}`;
  const course = one(`insert into courses (company_id, code, title, summary, category, hours, status, published_at)
    values (${lit(HUAYLAS)}, ${lit(`E2E-${suffix}`.slice(0, 20).toUpperCase())}, ${lit(title)}, 'Curso de prueba E2E', 'Alto riesgo', 4, 'published', now()) returning id`);
  const offer = one(`insert into course_offers (company_id, course_id, slug, title, list_price, is_public, status, allows_company_purchase, min_company_seats)
    values (${lit(HUAYLAS)}, ${lit(course)}, ${lit(slug)}, ${lit(title)}, ${extra.price ?? 100}, true, 'published', true, ${extra.minSeats ?? "null"}) returning id`);
  return { course, offer, slug, title };
}

export function seedCoupon(code: string, percent = 20): string {
  return one(`insert into coupons (company_id, code, discount_type, discount_value, applies_to, max_redemptions_per_buyer, is_active)
    values (${lit(HUAYLAS)}, ${lit(code)}, 'percent', ${percent}, 'all', 1, true) returning id`);
}

/** Baja lógica de lo sembrado y de las personas creadas por la venta (correos de la corrida). */
export function cleanupSales(s: Seeded | null, emailLike?: string, couponId?: string | null) {
  try {
    if (emailLike) {
      psql(`update license_assignments set deleted_at = now() where deleted_at is null and course_license_id in (select id from course_licenses where company_id = ${lit(HUAYLAS)} and course_id = ${lit(s?.course ?? "00000000-0000-0000-0000-000000000000")})`);
      psql(`update external_learners set deleted_at = now() where deleted_at is null and company_id = ${lit(HUAYLAS)} and email like ${lit(emailLike)}`);
      psql(`update memberships set status = 'revoked', deleted_at = now() where deleted_at is null and user_id in (select id from users where email like ${lit(emailLike)})`);
      psql(`update users set deleted_at = now() where deleted_at is null and email like ${lit(emailLike)}`);
    }
    if (couponId) psql(`update coupons set is_active = false, deleted_at = now() where id = ${lit(couponId)}`);
    if (s) {
      psql(`update course_recommendations set deleted_at = now() where deleted_at is null and course_id = ${lit(s.course)}`);
      psql(`update course_offers set status = 'archived', is_public = false, deleted_at = now() where id = ${lit(s.offer)}`);
      psql(`update courses set deleted_at = now() where id = ${lit(s.course)}`);
    }
  } catch (e) {
    console.warn(`Limpieza incompleta: ${(e as Error).message.slice(0, 300)}`);
  }
}

/** «Registrar pago» manual (Yape con constancia) por la API, como lo hace la empresa vendedora. */
export async function payOrder(req: APIRequestContext, w: Who, orderId: string, method = "yape") {
  const evidence = await uploadFile(req, w, "VTA", "constancia.pdf");
  return api<{ data: { delivered: { type: string; code?: string; seats?: number; learner?: string }[] } }>(req, w, `/vta/orders/${orderId}/pay`, {
    method: "POST",
    data: { method, reference: `E2E-${Date.now() % 1_000_000}`, evidence_file_id: evidence },
  });
}

/** Pedido por número (la empresa lo ve en su bandeja). */
export function orderByNumber(orderNumber: string): { id: string; status: string; total: number; discount: number; coupon: string | null } {
  const [id, status, total, discount, coupon] = row(`select id, status, total, discount_total, coupon_id from orders where company_id = ${lit(HUAYLAS)} and order_number = ${lit(orderNumber)}`);
  return { id, status, total: Number(total), discount: Number(discount), coupon: coupon || null };
}

/** Persona que compra una oferta (la empresa crea el pedido y registra el pago); devuelve el pedido y la matrícula. */
export async function sellAndPay(req: APIRequestContext, w: Who, offer: string, buyer: { email: string; first: string; last: string; marketing?: boolean; document?: string }) {
  const document = buyer.document ?? dni();
  const o = await api<{ data: { id: string; order_number: string; total: number } }>(req, w, "/vta/sell", {
    method: "POST",
    data: { course_offer_id: offer, buyer_type: "person", document_type: "DNI", document_number: document, first_name: buyer.first, last_name: buyer.last, email: buyer.email },
  });
  await payOrder(req, w, o.data.id);
  const [enrollment, learner] = row(`select e.id, e.external_learner_id from course_enrollments e join order_items i on i.id = e.order_item_id where i.order_id = ${lit(o.data.id)}`);
  if (buyer.marketing !== undefined) psql(`update external_learners set marketing_consent = ${buyer.marketing}, marketing_consent_at = ${buyer.marketing ? "now()" : "null"} where id = ${lit(learner)}`);
  return { order: o.data, enrollment, learner, document };
}

/** Curso aprobado con su certificado validado y verificable en público (lo que deja el aula al terminar y validar). */
export function seedCertificate(s: Seeded, enrollment: string, learner: string, holder: string, expiresOn: string | null = null) {
  const n = String(Math.floor(Math.random() * 1_000_000)).padStart(6, "0");
  const verification = `E2EV${Date.now().toString(36).toUpperCase()}${n}`.slice(0, 24);
  const jefe = one(`select id from users where email = 'corporativo@escudo.test'`);
  psql(`update course_enrollments set status = 'completed', passed = true, completed_at = now(), progress_pct = 100 where id = ${lit(enrollment)}`);
  const id = one(`insert into training_certificates (company_id, course_id, course_enrollment_id, external_learner_id, certificate_code, verification_code, holder_name, course_title,
      issued_on, expires_on, status, validated_by, validated_at, public_verification_enabled)
    values (${lit(HUAYLAS)}, ${lit(s.course)}, ${lit(enrollment)}, ${lit(learner)}, ${lit(`E2E-${new Date().getFullYear()}-${n}`)}, ${lit(verification)}, ${lit(holder)}, ${lit(s.title)},
      current_date - 300, ${expiresOn ? lit(expiresOn) : "null"}, 'validated', ${lit(jefe)}, now(), true) returning id`);
  return { id, verification };
}

/** Varias filas (cada una con sus valores separados por «|»). */
export function psqlRows(sql: string): string[][] {
  return psql(sql).split(/\r?\n/).filter(Boolean).map((l) => l.split("|"));
}
