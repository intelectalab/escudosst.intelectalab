import type { Metadata } from "next";
import { DemoManage } from "@/components/site/DemoManage";

export const metadata: Metadata = { title: "Mi demo · EscudoSST", robots: { index: false } };

export default async function GestionarDemoPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return (
    <section className="px-4 py-12 lg:px-8">
      <DemoManage token={token} />
    </section>
  );
}
