"use client";

import { useParams } from "next/navigation";
import { CoursePlayer } from "@/components/addons/AulLearner";

export default function AulaCursoPage() {
  const { id } = useParams<{ id: string }>();
  return <CoursePlayer id={id} />;
}
