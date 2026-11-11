"use client";

// Cargar trabajadores desde Excel (M-TRB, SCR-TRB-04/05; prototipo «Cargar desde Excel»): plantilla versionada →
// subir el archivo → revisión fila por fila (errores en español: DNI de 8 dígitos, duplicado, sede o puesto que no
// existe) → resumen → importa solo las filas válidas (RN-TRB-05) y muestra el resultado con las filas con error
// descargables. Endpoints: GET /trb/imports/template, POST /trb/imports/preview y POST /trb/imports.

import { useRef } from "react";
import { API_URL, ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { ChoiceCards } from "@/components/ui/Form";
import { Chip } from "./Summary";
import { SummaryRow, Wizard, type WizardStep } from "./Wizard";

type Row = { row: number; document: string; name: string; site: string; area: string; position: string; action: "create" | "update" | "error"; errors: string[]; result?: string };
type Preview = { total: number; valid: number; with_errors: number; rows: Row[] };
type Data = { mode: "create" | "upsert"; file_name: string; preview: Preview | null };

async function postFile<T>(path: string, file: File, fields: Record<string, string>): Promise<T> {
  const body = new FormData();
  body.append("file", file);
  Object.entries(fields).forEach(([k, v]) => body.append(k, v));
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = session.getToken();
  const company = session.getCompanyId();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (company) headers["X-Company-Id"] = company;
  const res = await fetch(`${API_URL}/api/v1${path}`, { method: "POST", headers, body });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data.type ?? "error", data.title ?? "Error", data.detail, data.errors);
  return data.data as T;
}

/** Descarga las filas con error en CSV (se abre en Excel) con la columna del error. */
function downloadErrors(rows: Row[]) {
  const esc = (v: string) => `"${v.replace(/"/g, '""')}"`;
  const lines = [["Fila", "Documento", "Nombre", "Sede", "Área", "Puesto", "Error"].map(esc).join(";")];
  rows.filter((r) => r.errors.length).forEach((r) => lines.push([String(r.row), r.document, r.name, r.site, r.area, r.position, r.errors.join(" ")].map(esc).join(";")));
  const url = URL.createObjectURL(new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = "trabajadores-con-error.csv";
  a.click();
  URL.revokeObjectURL(url);
}

function RowsTable({ rows, result }: { rows: Row[]; result?: boolean }) {
  const label = (r: Row) =>
    result
      ? r.result === "created" ? ["Creado", "ok"] : r.result === "updated" ? ["Actualizado", "ok"] : ["Con error", "bad"]
      : r.action === "error" ? ["Con error", "bad"] : r.action === "update" ? ["Se actualiza", "warn"] : ["Se crea", "ok"];
  return (
    <div className="max-h-[46vh] overflow-auto rounded-xl border border-line">
      <table className="w-full text-left text-sm">
        <thead className="sticky top-0 border-b border-line bg-bg text-xs font-semibold text-muted">
          <tr><th className="px-3 py-2">Fila</th><th className="px-3 py-2">Trabajador</th><th className="hidden px-3 py-2 sm:table-cell">Sede · Puesto</th><th className="px-3 py-2">Estado</th></tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const [l, t] = label(r);
            return (
              <tr key={r.row} className="border-b border-line align-top last:border-0">
                <td className="px-3 py-2 tabular-nums text-muted">{r.row}</td>
                <td className="px-3 py-2">
                  <b className="block">{r.name || "—"}</b>
                  <span className="text-xs text-muted">{r.document}</span>
                  {r.errors.length > 0 && <ul className="mt-1 space-y-0.5 text-xs text-bad">{r.errors.map((e) => <li key={e}>{e}</li>)}</ul>}
                </td>
                <td className="hidden px-3 py-2 text-xs text-muted sm:table-cell">{[r.site, r.area, r.position].filter(Boolean).join(" · ")}</td>
                <td className="px-3 py-2"><Chip tone={t as "ok" | "warn" | "bad"}>{l}</Chip></td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export function TrbImport({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const file = useRef<File | null>(null);
  const initial: Data = { mode: "create", file_name: "", preview: null };

  const steps: WizardStep<Data>[] = [
    {
      title: "Plantilla",
      hint: "Descarga la plantilla, completa una fila por trabajador y guárdala en Excel.",
      render: (d, set) => (
        <div className="space-y-4">
          <Button icon="download" onClick={() => downloadFile("/trb/imports/template", "plantilla-trabajadores.xlsx").catch(() => undefined)}>Descargar plantilla (.xlsx)</Button>
          <p className="text-sm text-muted">Columnas: tipo y número de documento, nombres, apellidos, fecha de nacimiento, sexo, celular, correo, fecha de ingreso, tipo de vínculo, sede, área, puesto y código de empleado. La sede y el puesto deben existir en «Empresa y sedes».</p>
          <ChoiceCards<"create" | "upsert"> label="Modo de carga" value={d.mode} onChange={(mode) => set({ mode, preview: null })}
            options={[
              { value: "create", title: "Solo altas", detail: "Crea trabajadores nuevos; si el documento ya existe, la fila queda con error." },
              { value: "upsert", title: "Altas y actualización", detail: "Crea los nuevos y actualiza los que ya existen por su documento, sin duplicar." },
            ]} />
        </div>
      ),
    },
    {
      title: "Subir archivo",
      validate: () => (!file.current ? "Elige el archivo de Excel completado." : null),
      onNext: async (d) => ({ preview: await postFile<Preview>("/trb/imports/preview", file.current!, { mode: d.mode }) }),
      render: (d, set) => (
        <div className="space-y-3">
          <label className="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-line p-8 text-center hover:border-brand/60">
            <span className="text-sm font-semibold">{file.current ? file.current.name : d.file_name ? `Vuelve a elegir «${d.file_name}»` : "Elige el archivo .xlsx o .csv"}</span>
            <span className="text-xs text-muted">Hasta 5 MB y 2000 trabajadores</span>
            <input type="file" accept=".xlsx,.csv" className="sr-only" onChange={(e) => {
              file.current = e.target.files?.[0] ?? null;
              set({ file_name: file.current?.name ?? "", preview: null });
            }} />
          </label>
          <p className="text-xs text-muted">Al continuar revisamos cada fila; todavía no se guarda nada.</p>
        </div>
      ),
    },
    {
      title: "Revisión",
      validate: (d) => (!d.preview ? "Vuelve a subir el archivo para revisarlo." : d.preview.valid === 0 ? "Ninguna fila es válida: corrige el archivo y vuelve a subirlo." : null),
      render: (d) =>
        d.preview && (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <Chip tone="ok">{d.preview.valid} válida(s)</Chip>
              <Chip tone={d.preview.with_errors ? "bad" : "na"}>{d.preview.with_errors} con error</Chip>
              <span className="text-sm text-muted">de {d.preview.total} fila(s)</span>
              {d.preview.with_errors > 0 && <Button size="sm" icon="download" className="ml-auto" onClick={() => downloadErrors(d.preview!.rows)}>Filas con error</Button>}
            </div>
            <RowsTable rows={d.preview.rows} />
            {d.preview.with_errors > 0 && <p className="text-sm text-muted">Las filas con error no se importan y no bloquean a las demás. Puedes corregirlas y subir el archivo otra vez.</p>}
          </div>
        ),
    },
  ];

  return (
    <Wizard<Data>
      id="trb-import"
      title="Cargar desde Excel"
      subtitle="Alta masiva de trabajadores con su sede y puesto"
      icon="people"
      initial={initial}
      steps={steps}
      confirmLabel="Importar trabajadores"
      onClose={onClose}
      summary={(d, goTo) => (
        <div>
          <SummaryRow label="Archivo" value={d.file_name} onEdit={() => goTo(1)} />
          <SummaryRow label="Modo" value={d.mode === "create" ? "Solo altas" : "Altas y actualización"} onEdit={() => goTo(0)} />
          <SummaryRow label="Se crearán" value={String(d.preview?.rows.filter((r) => r.action === "create").length ?? 0)} />
          {d.mode === "upsert" && <SummaryRow label="Se actualizarán" value={String(d.preview?.rows.filter((r) => r.action === "update").length ?? 0)} />}
          <SummaryRow label="No se importan (con error)" value={String(d.preview?.with_errors ?? 0)} onEdit={() => goTo(2)} />
        </div>
      )}
      onConfirm={async (d) => {
        if (!file.current) throw new Error("Vuelve a elegir el archivo para importarlo.");
        const r = await postFile<{ created: number; updated: number; failed: number; rows: Row[] }>("/trb/imports", file.current, { mode: d.mode });
        onDone();
        return (
          <div className="space-y-3">
            <div className="grid grid-cols-3 gap-3">
              {[["Creados", r.created, "text-ok"], ["Actualizados", r.updated, "text-brand"], ["Con error", r.failed, "text-bad"]].map(([l, v, c]) => (
                <div key={String(l)} className="rounded-xl border border-line p-3 text-center">
                  <p className="text-xs text-muted">{l}</p>
                  <p className={`font-display text-2xl font-bold ${c}`}>{v}</p>
                </div>
              ))}
            </div>
            {r.failed > 0 && <Button icon="download" onClick={() => downloadErrors(r.rows)}>Descargar filas con error</Button>}
            <RowsTable rows={r.rows} result />
          </div>
        );
      }}
    />
  );
}
