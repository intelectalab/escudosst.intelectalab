import type { Metadata } from "next";
import { VerifyResult } from "@/components/site/VerifyResult";

export const metadata: Metadata = { title: "Verificar política SST · EscudoSST", robots: { index: false, follow: false } };

export default async function VerificarPolitica({ params }: { params: Promise<{ company: string; id: string }> }) {
  const { company, id } = await params;
  return (
    <section className="mx-auto max-w-2xl space-y-4 px-4 py-12">
      <h1 className="text-2xl font-bold">Verificación de la política de seguridad y salud en el trabajo</h1>
      <VerifyResult kind="policy" code={`${company}/${id}`} />
    </section>
  );
}
