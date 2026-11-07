// /robots.txt (RN-WEB-07): bloquea panel, aplicación, acceso, enlaces personales, seguimiento de reclamos y las
// páginas marcadas «Ocultar de Google»; indica dónde está el sitemap.

import type { MetadataRoute } from "next";
import { getSitemapData, PRIVATE_PATHS, siteUrl } from "@/lib/seo";

export const revalidate = 300;

export default async function robots(): Promise<MetadataRoute.Robots> {
  const data = await getSitemapData();
  const base = siteUrl(data.domain);
  return {
    rules: [{ userAgent: "*", allow: "/", disallow: [...PRIVATE_PATHS, ...data.noindex] }],
    sitemap: `${base}/sitemap.xml`,
    host: base,
  };
}
