"use client";

// SCR-USR-05 · Mi cuenta (app de empresa): datos, seguridad y mi firma.

import { AccountPanel } from "@/components/AccountPanel";
import { useApp } from "@/components/AppContext";
import { MySignature } from "@/components/addons/SignaturePad";

export default function CuentaPage() {
  const { user, company } = useApp();
  return (
    <div className="space-y-6">
      <AccountPanel user={user} roleLabel={`Rol en ${company.name}`} roleValue={company.role.name} />
      <div className="max-w-3xl">
        <MySignature />
      </div>
    </div>
  );
}
