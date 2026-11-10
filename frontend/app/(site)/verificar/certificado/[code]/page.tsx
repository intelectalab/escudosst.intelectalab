import type { Metadata } from "next";
import { VerifyResult } from "@/components/site/VerifyResult";

export const metadata: Metadata = { title: "Verificar certificado · EscudoSST", robots: { index: false, follow: false } };

export default async function VerificarCertificado({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  return (
    <section className="mx-auto max-w-2xl space-y-4 px-4 py-12">
      <h1 className="text-2xl font-bold">Verificación de certificado</h1>
      <VerifyResult kind="certificate" code={code} />
    </section>
  );
}
