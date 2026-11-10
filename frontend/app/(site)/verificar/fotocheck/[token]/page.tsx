import type { Metadata } from "next";
import { VerifyResult } from "@/components/site/VerifyResult";

export const metadata: Metadata = { title: "Verificar fotocheck · EscudoSST", robots: { index: false, follow: false } };

export default async function VerificarFotocheck({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return (
    <section className="mx-auto max-w-2xl space-y-4 px-4 py-12">
      <h1 className="text-2xl font-bold">Verificación de fotocheck</h1>
      <VerifyResult kind="credential" code={token} />
    </section>
  );
}
