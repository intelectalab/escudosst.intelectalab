// Estados de un caso de éxito en el panel (máquina de estados 40): borrador → aprobado → publicado ⇄ oculto.

import type { ChipTone } from "@/components/ui/Display";

export type StoryStatus = "draft" | "approved" | "published" | "hidden";

export const STORY_STATUS: Record<StoryStatus, [string, ChipTone]> = {
  draft: ["Borrador", "amber"],
  approved: ["Aprobado, sin publicar", "brand"],
  published: ["Publicado", "ok"],
  hidden: ["Oculto", "na"],
};
