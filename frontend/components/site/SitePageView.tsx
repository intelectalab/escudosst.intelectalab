// Página del sitio armada con bloques (sectores, diferenciadores, consultoras). Solo existe si está publicada.

import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PageBlocks } from "@/components/site/PageBlocks";
import { PageWhatsAppMessage } from "@/components/site/WhatsAppFloat";
import { getModules, getPage } from "@/lib/site";

export async function pageMetadata(slug: string): Promise<Metadata> {
  const p = await getPage(slug);
  if (!p) return { title: "EscudoSST" };
  return {
    title: p.seo?.meta_title || `${p.title} · EscudoSST`,
    description: p.seo?.meta_description || p.summary || undefined,
    robots: p.seo?.noindex ? { index: false } : undefined,
  };
}

export async function SitePageView({ slug }: { slug: string }) {
  const [page, modules] = await Promise.all([getPage(slug), getModules()]);
  if (!page) notFound();
  return (
    <>
      <PageWhatsAppMessage message={page.whatsapp_message ?? null} />
      <PageBlocks blocks={page.blocks} modules={modules} />
    </>
  );
}
