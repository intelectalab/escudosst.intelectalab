"use client";

// M-SUS §11 · Reportes del panel en Excel: «cuentas por plan y complemento; altas y bajas» y «comprobantes emitidos por
// periodo con estado SUNAT». Elige el periodo (por defecto, el mes en curso) y descarga el archivo.

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";

const REPORTS = {
  accounts: { path: "/internal/reports/accounts.xlsx", label: "Cuentas por plan, altas y bajas", file: "cuentas" },
  invoices: { path: "/internal/reports/invoices.xlsx", label: "Comprobantes del periodo", file: "comprobantes" },
} as const;

const today = () => new Date().toLocaleDateString("en-CA", { timeZone: "America/Lima" });

export function PlatformReportDownload({ report }: { report: keyof typeof REPORTS }) {
  const r = REPORTS[report];
  const [from, setFrom] = useState(() => `${today().slice(0, 7)}-01`);
  const [to, setTo] = useState(today);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function download() {
    setBusy(true);
    setError(null);
    try {
      await downloadFile(`${r.path}?from=${from}&to=${to}`, `${r.file}-${from}-${to}.xlsx`);
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.message) : "No se pudo descargar el reporte.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-wrap items-end gap-2">
      <label className="text-xs text-muted">
        Desde
        <input type="date" className="input mt-1 block !h-9" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
      </label>
      <label className="text-xs text-muted">
        Hasta
        <input type="date" className="input mt-1 block !h-9" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
      </label>
      <Button icon="file" loading={busy} disabled={!from || !to} onClick={download}>{r.label} (Excel)</Button>
      {error && <p role="alert" className="w-full text-xs text-bad">{error}</p>}
    </div>
  );
}
