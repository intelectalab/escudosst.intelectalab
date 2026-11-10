"use client";

// Encuesta psicosocial anónima (enlace firmado; sin cuenta).

import { useParams } from "next/navigation";
import { PsiSurveyForm } from "@/components/site/PsiSurveyForm";

export default function EncuestaPage() {
  const p = useParams<{ company: string; survey: string; sig: string }>();
  return <PsiSurveyForm path={`${p.company}/${p.survey}/${p.sig}`} />;
}
