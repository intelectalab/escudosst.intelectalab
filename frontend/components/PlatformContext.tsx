"use client";

import { createContext, useContext } from "react";
import type { User } from "@/lib/types";

export const PlatformUserContext = createContext<User | null>(null);

export function usePlatformUser(): User {
  const user = useContext(PlatformUserContext);
  if (!user) throw new Error("usePlatformUser debe usarse dentro del panel de la plataforma");
  return user;
}
