"use client";

// Importar preguntas desde Excel a un banco (M-AUL SCR-AUL-09, RN-AUL-14; mismo recorrido que la carga de
// trabajadores): plantilla oficial versionada → subir el archivo → revisión fila por fila (tipo, alternativas,
// correcta, dificultad, puntos, código repetido) → importa solo las filas válidas; cada fila entra completa o no entra.
// Endpoints: GET /aul/admin/banks/{id}/import/template, POST .../import/preview y POST .../import.

import { useRef } from "react";
import { API_URL, ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { Chip } from "./Summary";
import { SummaryRow, Wizard, type WizardStep } from "./Wizard";

type Row = { row: number; code: string; type: string | null; type_label: string; statement: string; options: number; correct: string; difficulty: string | null; points: number | null; action: "create" | "error"; errors: string[]; result?: string };
type Preview = { total: number; valid: number; with_errors: number; rows: Row[] };
type Data = { file_name: string; preview: Preview | null };

const QTYPE: Record<string, string> = { single_choice: "Opción única", multiple_choice: "Opción múltiple", true_false: "Verdadero o falso", short_answer: "Respuesta corta" };

async function postFile<T>(path: string, file: File): Promise<T> {
  const body = new FormData();
  body.append("file", file);
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

function downloadErrors(rows: Row[]) {
  const esc = (v: string) => `"${v.replace(/"/g, '""')}"`;
  const lines = [["Fila", "Código", "Tipo", "Enunciado", "Error"].map(esc).join(";")];
  rows.filter((r) => r.errors.length).forEach((r) => lines.push([String(r.row), r.code, r.type_label, r.statement, r.errors.join(" ")].map(esc).join(";")));
  const url = URL.createObjectURL(new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = "preguntas-con-error.csv";
  a.click();
  URL.revokeObjectURL(url);
}

function RowsTable({ rows, result }: { rows: Row[]; result?: boolean }) {
  return (
    <div className="max-h-[46vh] overflow-auto rounded-xl border border-line">
      <table className="w-full text-left text-sm">
        <thead className="sticky top-0 border-b border-line bg-bg text-xs font-semibold text-muted">
          <tr><th className="px-3 py-2">Fila</th><th className="px-3 py-2">Pregunta</th><th className="hidden px-3 py-2 sm:table-cell">Correcta</th><th className="px-3 py-2">Estado</th></tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const bad = result ? r.result !== "created" : r.action === "error";
            return (
              <tr key={r.row} className="border-b border-line align-top last:border-0">
                <td className="px-3 py-2 tabular-nums text-muted">{r.row}</td>
                <td className="px-3 py-2">
                  <b className="block">{r.statement || "—"}</b>
                  <span className="text-xs text-muted">{[r.code, r.type ? QTYPE[r.type] : r.type_label, r.options ? `${r.options} alternativas` : ""].filter(Boolean).join(" · ")}</span>
                  {r.errors.length > 0 && <ul className="mt-1 space-y-0.5 text-xs text-bad">{r.errors.map((e) => <li key={e}>{e}</li>)}</ul>}
                </td>
                <td className="hidden px-3 py-2 text-xs text-muted sm:table-cell">{r.correct}</td>
                <td className="px-3 py-2"><Chip tone={bad ? "bad" : "ok"}>{bad ? "Con error" : result ? "Importada" : "Se importa"}</Chip></td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export function AulQuestionImport({ bank, onClose, onDone }: { bank: { id: string; code: string; name: string }; onClose: () => void; onDone: () => void }) {
  const file = useRef<File | null>(null);
  const base = `/aul/admin/banks/${bank.id}/import`;
  const steps: WizardStep<Data>[] = [
    {
      title: "Plantilla",
      hint: "Descarga la plantilla oficial, escribe una pregunta por fila y guárdala en Excel.",
      render: () => (
        <div className="space-y-4">
          <Button icon="download" onClick={() => downloadFile(`${base}/template`, `plantilla-preguntas-${bank.code}.xlsx`).catch(() => undefined)}>Descargar plantilla (.xlsx)</Button>
          <ul className="list-disc space-y-1 pl-5 text-sm text-muted">
            <li>Tipo: Opción única, Opción múltiple, Verdadero o falso o Respuesta corta.</li>
            <li>Alternativas A a F seguidas desde la A; en «Correcta» la letra (A) o varias separadas por coma (A,C).</li>
            <li>Verdadero o falso: escribe V o F. Respuesta corta: las respuestas aceptadas separadas por «|»; vacío = pregunta abierta que califica el capacitador.</li>
            <li>Dificultad Baja, Media o Alta; puntos (vacío = 1). El código no puede repetirse en el banco.</li>
          </ul>
        </div>
      ),
    },
    {
      title: "Subir archivo",
      validate: () => (!file.current ? "Elige el archivo de Excel completado." : null),
      onNext: async () => ({ preview: await postFile<Preview>(`${base}/preview`, file.current!) }),
      render: (d, set) => (
        <div className="space-y-3">
          <label className="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-line p-8 text-center hover:border-brand/60">
            <span className="text-sm font-semibold">{file.current ? file.current.name : d.file_name ? `Vuelve a elegir «${d.file_name}»` : "Elige el archivo .xlsx o .csv"}</span>
            <span className="text-xs text-muted">Hasta 5 MB y 1000 preguntas</span>
            <input type="file" accept=".xlsx,.csv" className="sr-only" onChange={(e) => { file.current = e.target.files?.[0] ?? null; set({ file_name: file.current?.name ?? "", preview: null }); }} />
          </label>
          <p className="text-xs text-muted">Al continuar revisamos cada fila; todavía no se guarda nada.</p>
        </div>
      ),
    },
    {
      title: "Revisión",
      validate: (d) => (!d.preview ? "Vuelve a subir el archivo para revisarlo." : d.preview.valid === 0 ? "Ninguna fila es válida: corrige el archivo y vuelve a subirlo." : null),
      render: (d) => d.preview && (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <Chip tone="ok">{d.preview.valid} válida(s)</Chip>
            <Chip tone={d.preview.with_errors ? "bad" : "na"}>{d.preview.with_errors} con error</Chip>
            <span className="text-sm text-muted">de {d.preview.total} fila(s)</span>
            {d.preview.with_errors > 0 && <Button size="sm" icon="download" className="ml-auto" onClick={() => downloadErrors(d.preview!.rows)}>Filas con error</Button>}
          </div>
          <RowsTable rows={d.preview.rows} />
          {d.preview.with_errors > 0 && <p className="text-sm text-muted">Las filas con error no se importan y no bloquean a las demás.</p>}
        </div>
      ),
    },
  ];
  return (
    <Wizard<Data>
      id={`aul-import-${bank.id}`}
      title="Importar preguntas desde Excel"
      subtitle={`Al banco ${bank.code} · ${bank.name}`}
      icon="file"
      initial={{ file_name: "", preview: null }}
      steps={steps}
      confirmLabel="Importar preguntas"
      onClose={onClose}
      summary={(d, goTo) => (
        <div>
          <SummaryRow label="Archivo" value={d.file_name} onEdit={() => goTo(1)} />
          <SummaryRow label="Se importarán" value={String(d.preview?.valid ?? 0)} />
          <SummaryRow label="No se importan (con error)" value={String(d.preview?.with_errors ?? 0)} onEdit={() => goTo(2)} />
        </div>
      )}
      onConfirm={async () => {
        if (!file.current) throw new Error("Vuelve a elegir el archivo para importarlo.");
        const r = await postFile<{ created: number; failed: number; rows: Row[] }>(base, file.current);
        onDone();
        return (
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              {([["Importadas", r.created, "text-ok"], ["Con error", r.failed, "text-bad"]] as const).map(([l, v, c]) => (
                <div key={l} className="rounded-xl border border-line p-3 text-center"><p className="text-xs text-muted">{l}</p><p className={`font-display text-2xl font-bold ${c}`}>{v}</p></div>
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
