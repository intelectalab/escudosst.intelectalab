"use client";

// SCR-USR-05 · Mi cuenta (app de empresa).

import { AccountPanel } from "@/components/AccountPanel";
import { useApp } from "@/components/AppContext";

export default function CuentaPage() {
  const { user, company } = useApp();
  return <AccountPanel user={user} roleLabel={`Rol en ${company.name}`} roleValue={company.role.name} />;
}
