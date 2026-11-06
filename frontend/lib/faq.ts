// Temas de las preguntas frecuentes (site_faqs.topic), en el orden en que se muestran.
export const FAQ_TOPICS: [string, string][] = [
  ["general", "General"],
  ["product", "Producto"],
  ["plans", "Planes y precios"],
  ["billing", "Pagos y facturación"],
  ["security", "Seguridad"],
  ["data_protection", "Datos personales"],
  ["support", "Soporte"],
];

export const topicName = (t: string) => FAQ_TOPICS.find(([k]) => k === t)?.[1] ?? t;
