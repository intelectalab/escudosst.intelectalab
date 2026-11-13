"use client";

// SCR-USR-05 · Mi cuenta (app de empresa): datos, seguridad, mi firma y mis avisos (SCR-NOT-03).

import { AccountPanel } from "@/components/AccountPanel";
import { useApp } from "@/components/AppContext";
import { MySignature } from "@/components/addons/SignaturePad";
import { NotificationPrefs } from "@/components/NotificationPrefs";

export default function CuentaPage() {
  const { user, company } = useApp();
  return (
    <div className="space-y-6">
      <AccountPanel user={user} roleLabel={`Rol en ${company.name}`} roleValue={company.role.name} />
      <div id="firma" className="scroll-mt-24">
        <MySignature />
      </div>
      <NotificationPrefs />
    </div>
  );
}
