// Muestra un texto legal por secciones (sitio público y vista previa del panel). Sin HTML: solo texto seguro.

import { blocks, type LegalDoc, slugify, TEST_MARK } from "@/lib/legalText";

function Text({ text }: { text: string }) {
  // Resalta la marca de texto de prueba para que nadie la confunda con el texto real.
  const parts = text.split(TEST_MARK);
  return (
    <>
      {parts.map((p, i) => (
        <span key={i}>
          {i > 0 && <mark className="rounded bg-[#F4B90F]/30 px-1 font-semibold text-[#6b5003]">{TEST_MARK}</mark>}
          {p}
        </span>
      ))}
    </>
  );
}

export function LegalBody({ doc }: { doc: LegalDoc }) {
  return (
    <div className="space-y-8 text-[16px] leading-relaxed">
      {doc.intro && (
        <div className="space-y-3">
          {blocks(doc.intro).map((b, i) => (
            <Block key={i} b={b} />
          ))}
        </div>
      )}
      {doc.sections.map((s, i) => (
        <section key={i} id={slugify(s.title) || `seccion-${i + 1}`} className="scroll-mt-28 space-y-3">
          <h2 className="font-display text-xl font-bold text-[#00254D]">
            {i + 1}. {s.title}
          </h2>
          {blocks(s.body).map((b, j) => (
            <Block key={j} b={b} />
          ))}
        </section>
      ))}
    </div>
  );
}

function Block({ b }: { b: ReturnType<typeof blocks>[number] }) {
  if (b.kind === "list")
    return (
      <ul className="list-disc space-y-1 pl-6">
        {b.items!.map((it, k) => (
          <li key={k}>
            <Text text={it} />
          </li>
        ))}
      </ul>
    );
  if (b.kind === "note")
    return (
      <p className="rounded-r-lg border-l-4 border-[#F4B90F] bg-[#F4B90F]/10 px-4 py-3">
        <Text text={b.text!} />
      </p>
    );
  return (
    <p className="text-[#262B33]">
      <Text text={b.text!} />
    </p>
  );
}
