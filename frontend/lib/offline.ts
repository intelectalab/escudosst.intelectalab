// App offline (complemento OFF, docs/16 y docs/modulos/M-OFF.md).
// Sin señal, los registros de campo se guardan en el dispositivo (IndexedDB, cifrados con AES-GCM) en una cola
// («outbox») y se envían en lotes a POST /sync/batches al volver la conexión. Cada operación lleva su
// idempotency_key (UUID v7 del dispositivo): reenviarla no duplica nada. Una operación sale de la cola solo cuando
// el servidor la confirma (aplicada, repetida o conflicto registrado); las rechazadas quedan visibles con su motivo.
// Nunca se guardan datos médicos (solo se aceptan las entidades de campo de ENTITIES).

import { api, ApiError } from "./api";

/** Registros de campo que se pueden capturar sin señal (docs/16 §2), por módulo. Igual que el servidor. */
export const OFFLINE_ENTITIES: Record<string, { module: string; label: string }> = {
  inspections: { module: "INS", label: "Inspección" },
  inspection_answers: { module: "INS", label: "Respuesta de inspección" },
  substandard_reports: { module: "ACT", label: "Reporte de acto o condición" },
  safety_talks: { module: "CH5", label: "Charla de 5 minutos" },
  talk_attendances: { module: "CH5", label: "Asistencia a charla" },
  ats_forms: { module: "ATS", label: "ATS" },
  ats_steps: { module: "ATS", label: "Paso de ATS" },
  ats_participants: { module: "ATS", label: "Participante de ATS" },
  ppe_deliveries: { module: "EPP", label: "Entrega de EPP" },
  training_attendances: { module: "CAP", label: "Asistencia a capacitación" },
  drill_participants: { module: "SIM", label: "Participante de simulacro" },
  sst_events: { module: "ACC", label: "Evento (accidente o incidente)" },
  event_people: { module: "ACC", label: "Persona del evento" },
  // PETAR en campo (docs/16 §2): inicio, suspensión y cierre (edición del permiso) y verificación de condiciones.
  work_permits: { module: "PTR", label: "Permiso de trabajo (PETAR)" },
  permit_checks: { module: "PTR", label: "Verificación del PETAR" },
};

/** Tablas que sin señal solo admiten ediciones (la solicitud y la aprobación del PETAR se hacen en línea). */
const UPDATE_ONLY = new Set(["work_permits"]);

export type OutboxOp = {
  idempotency_key: string;
  op: "create" | "update";
  entity: string;
  entity_id: string;
  base_version: number | null;
  payload: Record<string, unknown>;
  created_at_device: string;
  depends_on: string[];
  /** pending: por enviar · rejected: el servidor la rechazó (motivo en reason). */
  status: "pending" | "rejected";
  reason?: string;
  seq: number;
};

export type OfflineState = {
  enabled: boolean;
  online: boolean;
  syncing: boolean;
  pending: number;
  rejected: number;
  conflicts: number;
  lastSync: string | null;
  lastError: string | null;
};

type Ctx = { userId: string; companyId: string; enabled: boolean };

let ctx: Ctx | null = null;
let state: OfflineState = { enabled: false, online: true, syncing: false, pending: 0, rejected: 0, conflicts: 0, lastSync: null, lastError: null };
const listeners = new Set<(s: OfflineState) => void>();
let retryTimer: ReturnType<typeof setTimeout> | null = null;
let retryDelay = 5000;

function emit(patch: Partial<OfflineState>) {
  state = { ...state, ...patch };
  listeners.forEach((l) => l(state));
}

export function subscribeOffline(listener: (s: OfflineState) => void): () => void {
  listeners.add(listener);
  listener(state);
  return () => listeners.delete(listener);
}

export const offlineState = () => state;

// ── Identificadores ──────────────────────────────────────────────────────────

/** UUID v7 (tiempo + aleatorio): el registro tiene su id desde el dispositivo. */
export function uuidv7(): string {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  const ts = Date.now();
  for (let i = 0; i < 6; i++) b[i] = Math.floor(ts / 2 ** (8 * (5 - i))) & 0xff;
  b[6] = (b[6] & 0x0f) | 0x70;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, "0")).join("");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** Hora del dispositivo con su zona (se conserva la hora real del hecho, D.S. 005-2012-TR art. 33). */
function localIso(d = new Date()): string {
  const off = -d.getTimezoneOffset();
  const p = (n: number) => String(Math.floor(Math.abs(n))).padStart(2, "0");
  const local = new Date(d.getTime() + off * 60000).toISOString().slice(0, 19);
  return `${local}${off >= 0 ? "+" : "-"}${p(off / 60)}:${p(off % 60)}`;
}

export function deviceUid(): string {
  try {
    let id = localStorage.getItem("escudo.device_uid");
    if (!id) {
      id = `pwa-${uuidv7()}`;
      localStorage.setItem("escudo.device_uid", id);
    }
    return id;
  } catch {
    return "pwa-sin-almacenamiento";
  }
}

// ── IndexedDB cifrada ────────────────────────────────────────────────────────

const STORES = ["outbox", "records", "files", "catalogs", "sync_state"] as const;

function dbName(c: Ctx) {
  return `escudo_${c.userId}_${c.companyId}`;
}

function openDb(): Promise<IDBDatabase> {
  if (!ctx) return Promise.reject(new Error("offline sin contexto"));
  const name = dbName(ctx);
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(name, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      for (const s of STORES) if (!db.objectStoreNames.contains(s)) db.createObjectStore(s, { keyPath: s === "outbox" ? "k" : "id" });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function tx<T>(store: (typeof STORES)[number], mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  return openDb().then(
    (db) =>
      new Promise<T>((resolve, reject) => {
        const t = db.transaction(store, mode);
        const req = fn(t.objectStore(store));
        t.oncomplete = () => {
          db.close();
          resolve(req.result);
        };
        t.onerror = () => {
          db.close();
          reject(t.error);
        };
      }),
  );
}

/** Llave AES-GCM del dispositivo: no extraíble, vive solo en esta base y se borra con ella. */
async function key(): Promise<CryptoKey> {
  const saved = await tx<{ id: string; key: CryptoKey } | undefined>("sync_state", "readonly", (s) => s.get("key"));
  if (saved?.key) return saved.key;
  const k = await crypto.subtle.generateKey({ name: "AES-GCM", length: 256 }, false, ["encrypt", "decrypt"]);
  await tx("sync_state", "readwrite", (s) => s.put({ id: "key", key: k }));
  return k;
}

async function seal(op: OutboxOp): Promise<{ k: string; seq: number; iv: Uint8Array; data: ArrayBuffer }> {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await key(), new TextEncoder().encode(JSON.stringify(op)));
  return { k: op.idempotency_key, seq: op.seq, iv, data };
}

async function open(row: { iv: Uint8Array; data: ArrayBuffer }): Promise<OutboxOp | null> {
  try {
    const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: row.iv as BufferSource }, await key(), row.data);
    return JSON.parse(new TextDecoder().decode(plain)) as OutboxOp;
  } catch {
    return null;
  }
}

// ── Fotos sin señal ─────────────────────────────────────────────────────────
// La foto se guarda cifrada en el dispositivo y el registro lleva «local-file:<id>» en su campo de archivo. Al volver la
// señal, antes de enviar el lote se sube la foto y el campo pasa a tener el id real del archivo.

const LOCAL_FILE = "local-file:";

/**
 * RN-OFF-06: la foto tomada sin conexión se reduce hasta offline_photo_max_kb (el valor llega del servidor al registrar
 * el equipo) bajando primero la calidad y luego el tamaño. Si no es imagen o ya pesa menos, se guarda tal cual.
 */
export async function compressPhoto(file: File, maxKb?: number): Promise<File> {
  let limit = maxKb ?? 0;
  if (!limit) {
    try { limit = Number(localStorage.getItem("escudo.offline_photo_max_kb") ?? "800") || 800; } catch { limit = 800; }
  }
  if (!file.type.startsWith("image/") || file.type === "image/gif" || file.size <= limit * 1024 || typeof createImageBitmap === "undefined") return file;
  try {
    const bmp = await createImageBitmap(file);
    let scale = Math.min(1, 2048 / Math.max(bmp.width, bmp.height));
    for (let i = 0; i < 8; i++) {
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(bmp.width * scale));
      canvas.height = Math.max(1, Math.round(bmp.height * scale));
      canvas.getContext("2d")?.drawImage(bmp, 0, 0, canvas.width, canvas.height);
      for (const q of [0.82, 0.7, 0.58]) {
        const blob = await new Promise<Blob | null>((ok) => canvas.toBlob(ok, "image/jpeg", q));
        if (blob && blob.size <= limit * 1024) return new File([blob], (file.name || "foto").replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg" });
      }
      scale *= 0.75;
    }
  } catch {
    /* el navegador no pudo leer la imagen: se guarda la original */
  }
  return file;
}

/** Guarda una foto o archivo para subirlo después. Devuelve la referencia que va en el campo del registro. */
export async function queueFile(input: File, module?: string): Promise<string> {
  const file = await compressPhoto(input);
  const id = uuidv7();
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await key(), await file.arrayBuffer());
  await tx("files", "readwrite", (s) => s.put({ id, iv, data, name: file.name || "foto.jpg", type: file.type || "image/jpeg", module: module ?? null }));
  return LOCAL_FILE + id;
}

const hasLocalFile = (v: unknown): boolean => (typeof v === "string" && v.startsWith(LOCAL_FILE)) || (Array.isArray(v) && v.some(hasLocalFile));

/** Sube una foto guardada en el equipo y devuelve el id del archivo (null si ya no está o el servidor no la aceptó). */
async function uploadLocal(ref: string): Promise<string | null> {
  const { uploadFile } = await import("./records");
  const id = ref.slice(LOCAL_FILE.length);
  const row = await tx<{ iv: Uint8Array; data: ArrayBuffer; name: string; type: string; module: string | null } | undefined>("files", "readonly", (s) => s.get(id));
  if (!row) return null;   // la foto ya no está en el equipo: el registro se envía sin ella
  const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: row.iv as BufferSource }, await key(), row.data);
  let out: string | null = null;
  try {
    out = (await uploadFile(new File([plain], row.name, { type: row.type }), row.module ?? undefined)).id;
  } catch (e) {
    if (e instanceof TypeError || isNetwork(e)) throw e;   // sin señal otra vez (fetch no llegó): se reintenta luego
    out = null;   // el servidor no aceptó el archivo: el registro se envía sin la foto
  }
  await tx("files", "readwrite", (s) => s.delete(id));
  return out;
}

async function uploadQueuedFiles(op: OutboxOp): Promise<OutboxOp> {
  const refs = Object.entries(op.payload).filter(([, v]) => hasLocalFile(v));
  if (!refs.length) return op;
  const payload = { ...op.payload };
  for (const [field, ref] of refs) {
    if (Array.isArray(ref)) {
      // Varias fotos (p. ej. photo_file_ids del evento): se suben las guardadas y se conservan las que ya estaban subidas.
      const ids: unknown[] = [];
      for (const v of ref) {
        if (typeof v === "string" && v.startsWith(LOCAL_FILE)) {
          const up = await uploadLocal(v);
          if (up) ids.push(up);
        } else ids.push(v);
      }
      payload[field] = ids;
      continue;
    }
    const up = await uploadLocal(ref as string);
    if (up) payload[field] = up;
    else delete payload[field];
  }
  const next = { ...op, payload };
  await putOp(next);
  return next;
}

export async function listOutbox(): Promise<OutboxOp[]> {
  if (!ctx) return [];
  const rows = await tx<{ k: string; seq: number; iv: Uint8Array; data: ArrayBuffer }[]>("outbox", "readonly", (s) => s.getAll());
  const ops = (await Promise.all(rows.map(open))).filter((o): o is OutboxOp => !!o);
  return ops.sort((a, b) => a.seq - b.seq);
}

async function putOp(op: OutboxOp) {
  const row = await seal(op);
  await tx("outbox", "readwrite", (s) => s.put(row));
}

async function deleteOp(k: string) {
  await tx("outbox", "readwrite", (s) => s.delete(k));
}

async function refreshCounts() {
  const ops = await listOutbox().catch(() => []);
  emit({ pending: ops.filter((o) => o.status === "pending").length, rejected: ops.filter((o) => o.status === "rejected").length });
}

/** Borrado remoto o cierre de sesión confirmado: base local y caché de la API fuera. */
export async function wipeLocal(): Promise<void> {
  if (!ctx) return;
  const name = dbName(ctx);
  await new Promise<void>((resolve) => {
    const req = indexedDB.deleteDatabase(name);
    req.onsuccess = req.onerror = req.onblocked = () => resolve();
  });
  navigator.serviceWorker?.controller?.postMessage({ type: "clear-api" });
  emit({ pending: 0, rejected: 0, conflicts: 0 });
}

// ── Contexto y conexión ─────────────────────────────────────────────────────

/** Lo llama el indicador con la sesión activa. Sin el complemento OFF no se encola nada (solo en línea). */
export function setOfflineContext(c: Ctx) {
  ctx = c;
  emit({ enabled: c.enabled, online: typeof navigator === "undefined" ? true : navigator.onLine });
  if (c.enabled) {
    navigator.storage?.persist?.().catch(() => undefined);
    void refreshCounts();
  }
}

export function setOnline(online: boolean) {
  emit({ online });
  if (online) void syncNow();
}

export function canQueue(table: string): boolean {
  return !!ctx?.enabled && table in OFFLINE_ENTITIES && !UPDATE_ONLY.has(table);
}

/** Ediciones que se pueden encolar sin señal (con base_version: si el servidor cambió, queda un conflicto y no se pisa). */
export function canQueueUpdate(table: string): boolean {
  return !!ctx?.enabled && table in OFFLINE_ENTITIES;
}

/** Encola una operación de campo. Devuelve el id que el registro ya tiene en el dispositivo. */
export async function enqueue(input: { entity: string; op?: "create" | "update"; payload: Record<string, unknown>; entity_id?: string; base_version?: number | null }): Promise<OutboxOp> {
  if (input.op === "update" ? !canQueueUpdate(input.entity) : !canQueue(input.entity)) throw new ApiError(0, "network_error", "Sin conexión", "Este registro necesita conexión.");
  const queued = await listOutbox();
  // RN-OFF-07: pasados offline_max_days sin sincronizar (contados desde la última sincronización o, si nunca sincronizó,
  // desde el registro pendiente más antiguo) no se aceptan nuevas capturas hasta sincronizar.
  const blocked = captureBlockedDays(queued);
  if (blocked !== null) {
    throw new ApiError(0, "sync_required", "Sincroniza primero", `Este equipo lleva ${blocked} días sin sincronizar: conéctate y sincroniza antes de registrar más.`);
  }
  const op: OutboxOp = {
    idempotency_key: uuidv7(),
    op: input.op ?? "create",
    entity: input.entity,
    entity_id: input.entity_id ?? uuidv7(),
    base_version: input.base_version ?? null,
    payload: input.payload,
    created_at_device: localIso(),
    depends_on: queued.filter((o) => Object.values(input.payload).includes(o.entity_id)).map((o) => o.idempotency_key),
    status: "pending",
    seq: Date.now() * 1000 + (queued.length % 1000),
  };
  await putOp(op);
  await refreshCounts();
  return op;
}

const isNetwork = (e: unknown) => e instanceof ApiError && e.status === 0;

/** Alta de un registro: en línea va directo; sin señal (y con OFF) queda en la cola con su id definitivo. */
export async function createOrQueue<T extends { id: string }>(table: string, send: () => Promise<{ data: T }>, body: Record<string, unknown>): Promise<{ data: T }> {
  // Con una foto guardada en el equipo, el registro va por la cola: al sincronizar se sube la foto y luego el registro.
  if (canQueue(table) && Object.values(body).some(hasLocalFile)) {
    const op = await enqueue({ entity: table, payload: body });
    if (typeof navigator !== "undefined" && navigator.onLine) void syncNow();
    return { data: { ...body, id: op.entity_id, own: true, offline_pending: true } as unknown as T };
  }
  try {
    return await send();
  } catch (e) {
    if (!isNetwork(e) || !canQueue(table)) throw e;
    const op = await enqueue({ entity: table, payload: body });
    return { data: { ...body, id: op.entity_id, own: true, offline_pending: true } as unknown as T };
  }
}

/**
 * Edición de un registro de campo: en línea va directo; sin señal (y con OFF) queda en la cola con la versión que tenía el
 * registro (base_version). Hoy se usa en el PETAR en campo: iniciar, suspender o cerrar el permiso (docs/16 §2).
 */
export async function updateOrQueue<T extends { id: string }>(table: string, id: string, send: () => Promise<{ data: T }>, body: Record<string, unknown>, baseVersion?: number | null): Promise<{ data: T }> {
  try {
    return await send();
  } catch (e) {
    if (!isNetwork(e) || !canQueueUpdate(table) || baseVersion == null) throw e;
    await enqueue({ entity: table, op: "update", entity_id: id, base_version: baseVersion, payload: body });
    const local = await localRecord<Record<string, unknown>>(table, id).catch(() => null);
    if (local) await putLocalRecord(table, { ...local, ...body, offline_pending: true });
    return { data: { ...(local ?? {}), ...body, id, own: true, offline_pending: true } as unknown as T };
  }
}

/** Respuestas de una inspección: sin señal se encola una operación por ítem (solo-anexar). */
export async function submitInspectionAnswers(inspectionId: string, answers: { item_id: string; answer: unknown; comment?: string | null }[], location?: { lat: number; lng: number; accuracy_m: number } | null): Promise<{ queued: boolean }> {
  try {
    await api(`/ins/inspections/${inspectionId}/answers`, { method: "POST", body: location ? { answers, location } : { answers }, withCompany: true });
    return { queued: false };
  } catch (e) {
    if (!isNetwork(e) || !canQueue("inspection_answers")) throw e;
    for (const a of answers) await enqueue({ entity: "inspection_answers", payload: { inspection_id: inspectionId, ...a } });
    return { queued: true };
  }
}

// ── Sincronización ──────────────────────────────────────────────────────────

type SyncResult = { idempotency_key: string; result: "applied" | "conflict" | "rejected"; reason?: string };

let running: Promise<void> | null = null;

export function syncNow(): Promise<void> {
  if (!ctx?.enabled || running) return running ?? Promise.resolve();
  running = doSync().finally(() => {
    running = null;
  });
  return running;
}

async function doSync() {
  if (retryTimer) clearTimeout(retryTimer);
  retryTimer = null;
  const pending = (await listOutbox().catch(() => [])).filter((o) => o.status === "pending");
  if (!pending.length) {
    await loadConflicts();
    await pullChanges().catch(() => undefined);
    return;
  }
  emit({ syncing: true, lastError: null });
  try {
    for (let i = 0; i < pending.length; i += 50) {
      const chunk: OutboxOp[] = [];
      for (const o of pending.slice(i, i + 50)) chunk.push(await uploadQueuedFiles(o));
      const res = await api<{ data: { results: SyncResult[] } }>("/sync/batches", {
        method: "POST",
        withCompany: true,
        body: {
          batch_uuid: uuidv7(),
          device_uid: deviceUid(),
          platform: "web_pwa",
          client_created_at: localIso(),
          operations: chunk.map(({ idempotency_key, op, entity, entity_id, base_version, payload, created_at_device, depends_on }) => ({
            idempotency_key, op, entity, entity_id, base_version, payload, created_at_device, depends_on,
          })),
        },
      });
      for (const r of res.data.results) {
        const op = chunk.find((o) => o.idempotency_key === r.idempotency_key);
        if (!op) continue;
        if (r.result === "rejected") await putOp({ ...op, status: "rejected", reason: r.reason });
        else await deleteOp(op.idempotency_key);   // aplicada, repetida o conflicto registrado en el servidor
      }
    }
    retryDelay = 5000;
    const now = new Date().toISOString();
    try {
      localStorage.setItem("escudo.last_sync", now);
    } catch {
      /* sin almacenamiento local: solo se pierde el aviso */
    }
    emit({ lastSync: now });
    await loadConflicts();
    // docs/08 §13: después de enviar lo pendiente, se traen los cambios del servidor para lo que guarda el equipo.
    await pullChanges().catch((e) => { if (e instanceof ApiError && e.type === "device_revoked") throw e; });
  } catch (e) {
    if (e instanceof ApiError && e.type === "device_revoked") {
      await wipeLocal();
      emit({ lastError: e.detail ?? "Dispositivo revocado." });
    } else {
      emit({ lastError: e instanceof Error ? e.message : "No se pudo sincronizar." });
      // Reintento con espera creciente (hasta 5 minutos).
      retryTimer = setTimeout(() => void syncNow(), retryDelay);
      retryDelay = Math.min(retryDelay * 2, 300000);
    }
  } finally {
    await refreshCounts();
    emit({ syncing: false });
  }
}

export type SyncConflict = {
  id: string;
  entity_type: string;
  entity_id: string | null;
  operation: "create" | "update";
  conflict_type: string;
  client_payload: Record<string, unknown> | null;
  server_snapshot: Record<string, unknown> | null;
  created_at: string;
  user_name: string | null;
};

export async function loadConflicts(): Promise<SyncConflict[]> {
  if (!ctx?.enabled) return [];
  try {
    const res = await api<{ data: SyncConflict[] }>("/sync/conflicts", { withCompany: true });
    emit({ conflicts: res.data.length });
    return res.data;
  } catch {
    return [];
  }
}

export async function resolveConflict(id: string, resolution: "server_wins" | "client_wins" | "discarded" | "merged", payload?: Record<string, unknown>): Promise<void> {
  await api(`/sync/conflicts/${id}/resolve`, { method: "POST", withCompany: true, body: payload ? { resolution, payload } : { resolution } });
  await loadConflicts();
}

export async function retryOp(k: string) {
  const op = (await listOutbox()).find((o) => o.idempotency_key === k);
  if (op) await putOp({ ...op, status: "pending", reason: undefined });
  await refreshCounts();
  void syncNow();
}

export async function discardOp(k: string) {
  await deleteOp(k);
  await refreshCounts();
}

/** Registra el dispositivo (al activar OFF); si fue revocado, borra los datos locales. */
export async function registerDevice(): Promise<void> {
  if (!ctx?.enabled || !navigator.onLine) return;
  try {
    const r = await api<{ data: { offline_warning_days?: number; offline_photo_max_kb?: number; offline_max_days?: number } }>("/sync/devices", { method: "POST", withCompany: true, body: { device_uid: deviceUid(), platform: "web_pwa", model: navigator.userAgent.slice(0, 120) } });
    try {
      if (r.data?.offline_warning_days) localStorage.setItem("escudo.offline_warning_days", String(r.data.offline_warning_days));
      if (r.data?.offline_photo_max_kb) localStorage.setItem("escudo.offline_photo_max_kb", String(r.data.offline_photo_max_kb));
      if (r.data?.offline_max_days) localStorage.setItem("escudo.offline_max_days", String(r.data.offline_max_days));
    } catch {
      /* sin almacenamiento local */
    }
  } catch (e) {
    if (e instanceof ApiError && e.type === "device_revoked") await wipeLocal();
  }
}

/** Pendientes del dispositivo (para advertir antes de cerrar sesión). */
export async function pendingCount(): Promise<number> {
  return (await listOutbox().catch(() => [])).length;
}

/**
 * M-OFF §10 «Dispositivo sin sincronizar» (aviso local): días desde la última sincronización si hay registros pendientes
 * y ya pasaron offline_warning_days (el valor llega del servidor al registrar el dispositivo). null = sin aviso.
 */
export function unsyncedDays(pending: number): number | null {
  if (pending <= 0) return null;
  try {
    const last = localStorage.getItem("escudo.last_sync");
    const limit = Number(localStorage.getItem("escudo.offline_warning_days") ?? "3") || 3;
    if (!last) return null;
    const days = Math.floor((Date.now() - new Date(last).getTime()) / 86400000);
    return days >= limit ? days : null;
  } catch {
    return null;
  }
}

/** Días sin sincronizar si ya pasaron offline_max_days (bloquea nuevas capturas); null = puede seguir registrando. */
export function captureBlockedDays(queued: { created_at_device?: string }[]): number | null {
  try {
    const max = Number(localStorage.getItem("escudo.offline_max_days") ?? "7") || 7;
    const last = localStorage.getItem("escudo.last_sync");
    const oldest = queued.map((q) => q.created_at_device).filter(Boolean).sort()[0];
    const since = last ?? oldest;
    if (!since || queued.length === 0) return null;
    const days = Math.floor((Date.now() - new Date(since).getTime()) / 86400000);
    return days >= max ? days : null;
  } catch {
    return null;
  }
}

// ── Paquete de datos de referencia (SCR-OFF-02) ──────────────────────────────

export type OfflinePackageInfo = { prepared_at: string; size_bytes: number; counts: Record<string, number>; scoped: boolean };

/** Descarga el paquete para trabajar sin conexión y lo guarda cifrado en el equipo. Sin datos médicos (RN-OFF-05). */
export async function preparePackage(): Promise<OfflinePackageInfo> {
  const r = await api<{ data: Record<string, unknown>; meta: { size_bytes: number; counts: Record<string, number>; scoped: boolean } }>("/sync/package", { withCompany: true });
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await key(), new TextEncoder().encode(JSON.stringify(r.data)));
  const info: OfflinePackageInfo = { prepared_at: new Date().toISOString(), size_bytes: r.meta.size_bytes, counts: r.meta.counts, scoped: r.meta.scoped };
  await tx("catalogs", "readwrite", (s) => s.put({ id: "package", iv, data, info }));
  void pullChanges().catch(() => undefined);   // registros propios y permisos en curso (y lo que cambió desde el paquete)
  return info;
}

export async function packageInfo(): Promise<OfflinePackageInfo | null> {
  if (!ctx) return null;
  const row = await tx<{ info: OfflinePackageInfo } | undefined>("catalogs", "readonly", (s) => s.get("package")).catch(() => undefined);
  return row?.info ?? null;
}

/** Lee el paquete guardado (para las pantallas que trabajan sin señal). */
export async function readPackage<T = Record<string, unknown>>(): Promise<T | null> {
  const row = await tx<{ iv: Uint8Array; data: ArrayBuffer } | undefined>("catalogs", "readonly", (s) => s.get("package")).catch(() => undefined);
  if (!row) return null;
  try {
    const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: row.iv as BufferSource }, await key(), row.data);
    return JSON.parse(new TextDecoder().decode(plain)) as T;
  } catch {
    return null;
  }
}

/**
 * Sube el archivo; sin señal, y si el registro se puede capturar sin conexión, lo guarda cifrado en el equipo (comprimido
 * según offline_photo_max_kb, RN-OFF-06) y se sube al sincronizar. queued = true cuando quedó en el equipo.
 */
export async function uploadOrQueue(file: File, module: string | undefined, table?: string): Promise<{ id: string; original_name: string; queued: boolean }> {
  const queueable = !!table && canQueue(table);
  if (queueable && typeof navigator !== "undefined" && !navigator.onLine) return { id: await queueFile(file, module), original_name: file.name, queued: true };
  const { uploadFile } = await import("./records");
  try {
    return { ...(await uploadFile(file, module)), queued: false };
  } catch (e) {
    if (queueable && (e instanceof TypeError || isNetwork(e))) return { id: await queueFile(file, module), original_name: file.name, queued: true };
    throw e;
  }
}

export const isQueuedFile = (v: unknown) => typeof v === "string" && v.startsWith(LOCAL_FILE);

/** Tabla del paquete sin conexión que corresponde a un campo de referencia (por su ref o, si no llega, por el nombre). */
function packageList(ref: string | undefined, field: string): { key: string; label: (r: Record<string, unknown>) => string } | null {
  const t = ref ?? (field.endsWith("worker_id") ? "workers" : field.endsWith("template_id") ? "inspection_templates" : field === "ppe_item_id" ? "ppe_items" : field === "site_id" ? "sites" : "");
  if (t === "workers") return { key: "workers", label: (r) => `${r.full_name}${r.document_last4 ? ` · ***${r.document_last4}` : ""}` };
  if (t === "inspection_templates") return { key: "inspection_templates", label: (r) => String(r.code ? `${r.code} · ${r.name}` : r.name) };
  if (t === "ppe_items") return { key: "ppe_items", label: (r) => String(r.code ? `${r.code} · ${r.name}` : r.name) };
  if (t === "sites") return { key: "sites", label: (r) => String(r.name) };
  return null;
}

/**
 * SCR-OFF-02: sin señal, las opciones de trabajadores, plantillas y EPP salen del paquete guardado en el equipo.
 * null = ese campo no está en el paquete (o no hay paquete).
 */
export async function offlineOptions(ref: string | undefined, field: string, params: { q?: string; id?: string }): Promise<{ data: { id: string; label: string }[] } | null> {
  const list = packageList(ref, field);
  if (!list) return null;
  const pkg = await readPackage<Record<string, Record<string, unknown>[]>>();
  const rows = pkg?.[list.key];
  if (!rows) return null;
  const norm = (x: string) => x.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
  const q = params.q ? norm(params.q.trim()) : "";
  const out = rows
    .filter((r) => (params.id ? r.id === params.id : !q || norm(list.label(r)).includes(q)))
    .slice(0, 50)
    .map((r) => ({ id: String(r.id), label: list.label(r) }));
  return { data: out };
}

// ── Cambios del servidor (GET /sync/changes, docs/08 §13) ───────────────────
// Al recuperar la señal (y después de enviar la cola) se piden los cambios desde la última marca: los catálogos del paquete
// (trabajadores, sedes, plantillas, EPP, fotochecks) se actualizan en el paquete guardado y los registros de la persona
// (estado y versión, y los permisos en curso del PETAR en campo) en el almacén «records». Las lápidas los quitan del equipo.

type Change = { entity: string; id: string; op: "upsert" | "delete"; ts: string; data?: Record<string, unknown> };

/** Catálogos del paquete que se actualizan con los cambios (clave en el paquete). */
const CATALOG_KEYS: Record<string, string> = { workers: "workers", sites: "sites", inspection_templates: "inspection_templates", ppe_items: "ppe_items", credentials: "credentials" };

async function sealJson(value: unknown): Promise<{ iv: Uint8Array; data: ArrayBuffer }> {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await key(), new TextEncoder().encode(JSON.stringify(value)));
  return { iv, data };
}

async function openJson<T>(row: { iv: Uint8Array; data: ArrayBuffer } | undefined): Promise<T | null> {
  if (!row) return null;
  try {
    const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: row.iv as BufferSource }, await key(), row.data);
    return JSON.parse(new TextDecoder().decode(plain)) as T;
  } catch {
    return null;
  }
}

async function putLocalRecord(entity: string, data: Record<string, unknown>): Promise<void> {
  const sealed = await sealJson(data);
  await tx("records", "readwrite", (s) => s.put({ id: `${entity}:${String(data.id)}`, entity, ...sealed }));
}

/** Registro guardado en el equipo (por ejemplo, el PETAR para verlo sin señal); null si no está. */
export async function localRecord<T = Record<string, unknown>>(entity: string, id: string): Promise<T | null> {
  if (!ctx) return null;
  const row = await tx<{ iv: Uint8Array; data: ArrayBuffer } | undefined>("records", "readonly", (s) => s.get(`${entity}:${id}`)).catch(() => undefined);
  return openJson<T>(row);
}

/** Registros de una entidad guardados en el equipo. */
export async function localRecords<T = Record<string, unknown>>(entity: string): Promise<T[]> {
  if (!ctx) return [];
  const rows = await tx<{ id: string; entity: string; iv: Uint8Array; data: ArrayBuffer }[]>("records", "readonly", (s) => s.getAll()).catch(() => []);
  const out = await Promise.all(rows.filter((r) => r.entity === entity).map((r) => openJson<T>(r)));
  return out.filter((x): x is Awaited<T> => x !== null) as T[];
}

let pulling: Promise<number> | null = null;

/** Trae los cambios del servidor desde la última marca y los aplica en el equipo. Devuelve cuántos cambios aplicó. */
export function pullChanges(): Promise<number> {
  if (!ctx?.enabled || (typeof navigator !== "undefined" && !navigator.onLine)) return Promise.resolve(0);
  pulling ??= doPull().finally(() => { pulling = null; });
  return pulling;
}

async function doPull(): Promise<number> {
  const saved = await tx<{ id: string; since?: string } | undefined>("sync_state", "readonly", (s) => s.get("changes")).catch(() => undefined);
  let since = saved?.since ?? null;
  let applied = 0;
  for (let page = 0; page < 50; page++) {
    const qs = new URLSearchParams({ device_id: deviceUid(), limit: "500" });
    if (since) qs.set("since", since);
    let r: { data: { changes: Change[]; next_since: string; has_more: boolean } };
    try {
      r = await api<{ data: { changes: Change[]; next_since: string; has_more: boolean } }>(`/sync/changes?${qs}`, { withCompany: true });
    } catch (e) {
      if (e instanceof ApiError && e.type === "device_revoked") await wipeLocal();
      throw e;
    }
    await applyChanges(r.data.changes);
    applied += r.data.changes.length;
    since = r.data.next_since;
    await tx("sync_state", "readwrite", (s) => s.put({ id: "changes", since, pulled_at: new Date().toISOString() }));
    if (!r.data.has_more) break;
  }
  return applied;
}

async function applyChanges(changes: Change[]): Promise<void> {
  if (!changes.length) return;
  const catalog = changes.filter((c) => c.entity in CATALOG_KEYS);
  if (catalog.length) {
    const row = await tx<{ id: string; iv: Uint8Array; data: ArrayBuffer; info?: OfflinePackageInfo } | undefined>("catalogs", "readonly", (s) => s.get("package")).catch(() => undefined);
    const pkg = (await openJson<Record<string, Record<string, unknown>[]>>(row)) ?? {};
    for (const c of catalog) {
      const k = CATALOG_KEYS[c.entity];
      const list = (pkg[k] ?? []).filter((x) => x.id !== c.id);
      let data = c.data;
      if (c.entity === "inspection_templates") {
        // La plantilla llega con sus ítems: se reemplazan los del paquete.
        const items = Array.isArray(c.data?.items) ? (c.data.items as Record<string, unknown>[]) : [];
        pkg.inspection_template_items = [...(pkg.inspection_template_items ?? []).filter((x) => x.template_id !== c.id), ...(c.op === "upsert" ? items : [])];
        if (data) data = Object.fromEntries(Object.entries(data).filter(([f]) => f !== "items"));
      }
      if (c.op === "upsert" && data) list.push(data);
      pkg[k] = list;
    }
    const sealed = await sealJson(pkg);
    await tx("catalogs", "readwrite", (s) => s.put({ id: "package", ...sealed, info: row?.info ?? { prepared_at: new Date().toISOString(), size_bytes: 0, counts: {}, scoped: false } }));
  }
  for (const c of changes.filter((x) => !(x.entity in CATALOG_KEYS))) {
    if (c.op === "delete") await tx("records", "readwrite", (s) => s.delete(`${c.entity}:${c.id}`));
    else if (c.data) await putLocalRecord(c.entity, c.data);
  }
}
