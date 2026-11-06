import type { Metadata } from "next";
import { Atkinson_Hyperlegible, Lexend } from "next/font/google";
import "./globals.css";
import { themeBootScript } from "@/lib/theme";

const body = Atkinson_Hyperlegible({
  variable: "--font-body",
  subsets: ["latin"],
  weight: ["400", "700"],
});

const title = Lexend({
  variable: "--font-title",
  subsets: ["latin"],
  weight: ["500", "600"],
});

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const PUBLIC_API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

// El favicon lo sube el dueño en Sitio web › Marca y menú; se consulta con caché de un minuto.
export async function generateMetadata(): Promise<Metadata> {
  let favicon: string | null = null;
  try {
    const res = await fetch(`${API}/api/v1/public/site`, { next: { revalidate: 60 } });
    favicon = ((await res.json()) as { data?: { settings?: { favicon_file_id?: string | null } } }).data?.settings?.favicon_file_id ?? null;
  } catch {
    favicon = null;
  }
  return {
    title: "EscudoSST",
    description: "Gestión de Seguridad y Salud en el Trabajo",
    ...(favicon ? { icons: { icon: `${PUBLIC_API}/api/v1/public/site/assets/${favicon}` } } : {}),
  };
}

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeBootScript }} />
      </head>
      <body className={`${body.variable} ${title.variable} font-sans antialiased`}>
        {children}
      </body>
    </html>
  );
}
