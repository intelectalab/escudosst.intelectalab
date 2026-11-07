import type { Metadata } from "next";
import { NewsletterAction } from "@/components/site/NewsletterAction";

export const metadata: Metadata = { title: "Confirmar suscripción · EscudoSST", robots: { index: false } };

export default async function ConfirmarBoletinPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return (
    <section className="px-4 py-16 lg:px-8">
      <NewsletterAction kind="confirm" token={token} />
    </section>
  );
}
