// Pantalla inicial provisional: comprueba que la API Laravel responde.
// Se reemplaza cuando empiece la Fase 0 (docs/00-vision-y-alcance.md §7).

async function getApiStatus(): Promise<boolean> {
  const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
  try {
    const res = await fetch(`${apiUrl}/up`, { cache: "no-store" });
    return res.ok;
  } catch {
    return false;
  }
}

export default async function Home() {
  const apiOk = await getApiStatus();

  return (
    <main className="min-h-screen flex items-center justify-center p-8">
      <div className="max-w-md w-full space-y-4 text-center">
        <h1 className="text-3xl font-bold">EscudoSST</h1>
        <p className="text-gray-500">Entorno de desarrollo</p>
        <ul className="text-left space-y-2 border rounded-lg p-4">
          <li>✅ Web Next.js funcionando</li>
          <li>
            {apiOk ? "✅" : "❌"} API Laravel{" "}
            {apiOk ? "conectada" : "sin respuesta (¿está corriendo Docker?)"}
          </li>
        </ul>
      </div>
    </main>
  );
}
