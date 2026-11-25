import type { Metadata } from "next";
import { CompanyVerifyPage } from "@/components/site/CompanyVerify";

export const metadata: Metadata = { title: "Verificar certificados · EscudoSST", robots: { index: false, follow: false } };

export default async function VerificarEmpresa({ params }: { params: Promise<{ company: string }> }) {
  const { company } = await params;
  return (
    <section className="mx-auto max-w-2xl space-y-4 px-4 py-12">
      <h1 className="text-2xl font-bold">Verificación de certificados</h1>
      <CompanyVerifyPage company={company} />
    </section>
  );
}
