"use client";

import { AccountPanel } from "@/components/AccountPanel";
import { usePlatformUser } from "@/components/PlatformContext";

export default function PlataformaCuentaPage() {
  const user = usePlatformUser();
  return <AccountPanel user={user} roleLabel="Rol" roleValue={user.platform_role === "support" ? "Soporte de la plataforma" : "Dueño de la plataforma"} />;
}
