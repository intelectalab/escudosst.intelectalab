// Reseñas de Google (SCR-WEB-80): reseñas reales con autor, foto, calificación, fecha y texto íntegro (con «Leer más»),
// atribución «Reseñas de Google» y enlace a cada una. Si no hay copia vigente, el bloque no se muestra.

import { ReviewText } from "@/components/site/ReviewText";
import { getReviews } from "@/lib/site";

export async function ReviewsBlock() {
  const { attribution, reviews } = await getReviews();
  if (reviews.length === 0) return null;
  return (
    <section className="px-4 py-14 lg:px-8" aria-labelledby="resenas">
      <div className="mx-auto max-w-[1200px] space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h2 id="resenas" className="font-display text-[28px] font-bold text-[var(--site-navy-title)]">
            Lo que opinan de nosotros
          </h2>
          <span className="flex items-center gap-2 text-sm font-semibold text-[var(--site-text-2)]">
            <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
              <path fill="#4285F4" d="M22.5 12.3c0-.8-.1-1.5-.2-2.3H12v4.3h5.9a5 5 0 0 1-2.2 3.3v2.7h3.6c2.1-1.9 3.2-4.8 3.2-8z" />
              <path fill="#34A853" d="M12 23c3 0 5.5-1 7.3-2.7l-3.6-2.7c-1 .7-2.2 1.1-3.7 1.1-2.9 0-5.3-1.9-6.2-4.5H2.1v2.8A11 11 0 0 0 12 23z" />
              <path fill="#FBBC05" d="M5.8 14.2a6.6 6.6 0 0 1 0-4.3V7.1H2.1a11 11 0 0 0 0 9.9l3.7-2.8z" />
              <path fill="#EA4335" d="M12 5.4c1.6 0 3.1.6 4.2 1.7l3.2-3.2A11 11 0 0 0 2.1 7.1l3.7 2.8C6.7 7.3 9.1 5.4 12 5.4z" />
            </svg>
            {attribution}
          </span>
        </div>
        <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {reviews.map((r, k) => (
            <li key={k} className="site-card flex flex-col gap-3 p-5">
              <div className="flex items-center gap-3">
                {r.author_photo_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={r.author_photo_url} alt="" className="h-10 w-10 rounded-full object-cover" referrerPolicy="no-referrer" />
                ) : (
                  <span className="grid h-10 w-10 place-items-center rounded-full bg-[var(--site-surface)] font-bold text-[var(--site-navy-title)]">{r.author_name.charAt(0)}</span>
                )}
                <div className="min-w-0">
                  <b className="block truncate text-[var(--site-navy-title)]">{r.author_name}</b>
                  {r.review_published_at && (
                    <span className="text-xs text-[var(--site-text-2)]">{new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeZone: "America/Lima" }).format(new Date(r.review_published_at))}</span>
                  )}
                </div>
              </div>
              <p className="text-[#F4B90F]" aria-label={`${r.rating} de 5 estrellas`}>
                {"★".repeat(r.rating)}
                <span className="text-[#d5dde7]">{"★".repeat(5 - r.rating)}</span>
              </p>
              {r.body && <ReviewText text={r.body} />}
              {r.provider_review_url && (
                <a href={r.provider_review_url} target="_blank" rel="noopener nofollow" className="mt-auto text-sm font-semibold text-[var(--site-action)] hover:underline">
                  Ver en Google
                </a>
              )}
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
