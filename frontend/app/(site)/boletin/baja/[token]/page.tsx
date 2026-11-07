import type { Metadata } from "next";
import { NewsletterAction } from "@/components/site/NewsletterAction";

export const metadata: Metadata = { title: "Darme de baja · EscudoSST", robots: { index: false } };

export default async function BajaBoletinPage({ params, searchParams }: { params: Promise<{ token: string }>; searchParams: Promise<{ e?: string }> }) {
  const [{ token }, { e }] = await Promise.all([params, searchParams]);
  return (
    <section className="px-4 py-16 lg:px-8">
      <NewsletterAction kind="unsubscribe" token={token} issue={typeof e === "string" ? e : undefined} />
    </section>
  );
}
