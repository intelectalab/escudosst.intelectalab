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
};

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

/** Guarda una foto o archivo para subirlo después. Devuelve la referencia que va en el campo del registro. */
export async function queueFile(file: File, module?: string): Promise<string> {
  const id = uuidv7();
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const data = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, await key(), await file.arrayBuffer());
  await tx("files", "readwrite", (s) => s.put({ id, iv, data, name: file.name || "foto.jpg", type: file.type || "image/jpeg", module: module ?? null }));
  return LOCAL_FILE + id;
}

async function uploadQueuedFiles(op: OutboxOp): Promise<OutboxOp> {
  const refs = Object.entries(op.payload).filter(([, v]) => typeof v === "string" && v.startsWith(LOCAL_FILE));
  if (!refs.length) return op;
  const { uploadFile } = await import("./records");
  const payload = { ...op.payload };
  for (const [field, ref] of refs) {
    const id = (ref as string).slice(LOCAL_FILE.length);
    const row = await tx<{ iv: Uint8Array; data: ArrayBuffer; name: string; type: string; module: string | null } | undefined>("files", "readonly", (s) => s.get(id));
    if (!row) {
      delete payload[field];   // la foto ya no está en el equipo: el registro se envía sin ella
      continue;
    }
    const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: row.iv as BufferSource }, await key(), row.data);
    try {
      payload[field] = (await uploadFile(new File([plain], row.name, { type: row.type }), row.module ?? undefined)).id;
    } catch (e) {
      if (e instanceof TypeError || isNetwork(e)) throw e;   // sin señal otra vez (fetch no llegó): se reintenta luego
      delete payload[field];       // el servidor no aceptó el archivo: el registro se envía sin la foto
    }
    await tx("files", "readwrite", (s) => s.delete(id));
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
  return !!ctx?.enabled && table in OFFLINE_ENTITIES;
}

/** Encola una operación de campo. Devuelve el id que el registro ya tiene en el dispositivo. */
export async function enqueue(input: { entity: string; op?: "create" | "update"; payload: Record<string, unknown>; entity_id?: string; base_version?: number | null }): Promise<OutboxOp> {
  if (!canQueue(input.entity)) throw new ApiError(0, "network_error", "Sin conexión", "Este registro necesita conexión.");
  const queued = await listOutbox();
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
  try {
    return await send();
  } catch (e) {
    if (!isNetwork(e) || !canQueue(table)) throw e;
    const op = await enqueue({ entity: table, payload: body });
    return { data: { ...body, id: op.entity_id, own: true, offline_pending: true } as unknown as T };
  }
}

/** Respuestas de una inspección: sin señal se encola una operación por ítem (solo-anexar). */
export async function submitInspectionAnswers(inspectionId: string, answers: { item_id: string; answer: unknown; comment?: string | null }[]): Promise<{ queued: boolean }> {
  try {
    await api(`/ins/inspections/${inspectionId}/answers`, { method: "POST", body: { answers }, withCompany: true });
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

export async function resolveConflict(id: string, resolution: "server_wins" | "client_wins" | "discarded"): Promise<void> {
  await api(`/sync/conflicts/${id}/resolve`, { method: "POST", withCompany: true, body: { resolution } });
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
    const r = await api<{ data: { offline_warning_days?: number } }>("/sync/devices", { method: "POST", withCompany: true, body: { device_uid: deviceUid(), platform: "web_pwa", model: navigator.userAgent.slice(0, 120) } });
    try {
      if (r.data?.offline_warning_days) localStorage.setItem("escudo.offline_warning_days", String(r.data.offline_warning_days));
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
