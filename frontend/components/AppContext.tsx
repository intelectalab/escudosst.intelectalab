"use client";

import { createContext, useContext } from "react";
import type { CompanyOption, ModuleItem, User } from "@/lib/types";

export type AppState = {
  user: User;
  company: CompanyOption;
  modules: ModuleItem[];
  companiesCount: number;
};

export const AppContext = createContext<AppState | null>(null);

export function useApp(): AppState {
  const value = useContext(AppContext);
  if (!value) throw new Error("useApp debe usarse dentro del layout de la aplicación");
  return value;
}
