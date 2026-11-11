"use client";

// Curso del aula virtual dentro de la app del trabajador (M-AUL): el mismo reproductor de lecciones y evaluaciones.

import { useParams } from "next/navigation";
import { CoursePlayer } from "@/components/addons/AulLearner";

export default function WorkerCoursePage() {
  const { id } = useParams<{ id: string }>();
  return <CoursePlayer id={id} />;
}
