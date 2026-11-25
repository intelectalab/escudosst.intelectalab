import type { Metadata } from "next";
import { VerifyResult } from "@/components/site/VerifyResult";
import { CompanyVerifyText } from "@/components/site/CompanyVerify";

export const metadata: Metadata = { title: "Verificar certificado · EscudoSST", robots: { index: false, follow: false } };

export default async function VerificarCertificado({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<{ empresa?: string }> }) {
  const { code } = await params;
  const { empresa } = await searchParams;
  const company = empresa && /^[0-9a-f-]{36}$/i.test(empresa) ? empresa : undefined;
  return (
    <section className="mx-auto max-w-2xl space-y-4 px-4 py-12">
      <h1 className="text-2xl font-bold">Verificación de certificado</h1>
      {company && <CompanyVerifyText company={company} />}
      <VerifyResult kind="certificate" code={code} company={company} />
    </section>
  );
}
