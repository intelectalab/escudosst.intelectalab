// /sitemap.xml (RN-WEB-07): solo páginas publicadas e indexables, con su última modificación. El blog de WordPress
// publica su propio sitemap.

import type { MetadataRoute } from "next";
import { getSitemapData, siteUrl, STATIC_PATHS } from "@/lib/seo";
import { getProductUpdates, getSavingsCalculator, getWebinars } from "@/lib/site";

export const revalidate = 300;

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [data, updates, webinars, savings] = await Promise.all([getSitemapData(), getProductUpdates(), getWebinars(), getSavingsCalculator()]);
  const base = siteUrl(data.domain);
  const seen = new Set<string>();
  const out: MetadataRoute.Sitemap = [];
  const add = (path: string, lastModified?: string | null, priority = 0.6) => {
    if (seen.has(path) || data.noindex.includes(path)) return;
    seen.add(path);
    out.push({ url: `${base}${path === "/" ? "" : path}`, lastModified: lastModified ? new Date(lastModified) : undefined, priority });
  };
  STATIC_PATHS.forEach((p) => add(p, null, p === "/" ? 1 : 0.8));
  data.entries.forEach((e) => add(e.path, e.last_modified, e.kind === "legal" ? 0.3 : 0.6));
  if (savings?.available) add("/herramientas/calculadora-ahorro", null, 0.8);
  updates.forEach((u) => add(`/novedades/${u.slug}`, u.published_at, 0.5));
  [...webinars.upcoming, ...webinars.recorded].forEach((w) => add(`/webinars/${w.slug}`, null, 0.5));
  return out;
}
