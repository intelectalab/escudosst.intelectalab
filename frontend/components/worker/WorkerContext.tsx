"use client";

import { createContext, useContext } from "react";
import type { WorkerHome } from "@/lib/workerApp";

export type WorkerState = { home: WorkerHome; reload: () => Promise<void> };

export const WorkerContext = createContext<WorkerState | null>(null);

export function useWorker(): WorkerState {
  const v = useContext(WorkerContext);
  if (!v) throw new Error("useWorker debe usarse dentro de la app del trabajador");
  return v;
}
