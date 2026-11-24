// Ubicación de la firma (M-FIR: geo_lat, geo_lng). Es opcional: si el navegador no la da en unos segundos,
// o la persona no lo permite, se firma igual sin ubicación.

export async function signPlace(): Promise<{ geo_lat?: number; geo_lng?: number }> {
  if (typeof navigator === "undefined" || !navigator.geolocation) return {};
  return new Promise((resolve) => {
    const done = setTimeout(() => resolve({}), 5000);
    navigator.geolocation.getCurrentPosition(
      (p) => {
        clearTimeout(done);
        resolve({ geo_lat: p.coords.latitude, geo_lng: p.coords.longitude });
      },
      () => {
        clearTimeout(done);
        resolve({});
      },
      { enableHighAccuracy: false, timeout: 4500, maximumAge: 600000 },
    );
  });
}

/** M-INS SCR-INS-03: ubicación GPS del equipo para la inspección (alta precisión; null si no la da en unos segundos). */
export async function fieldPlace(): Promise<{ lat: number; lng: number; accuracy_m: number } | null> {
  if (typeof navigator === "undefined" || !navigator.geolocation) return null;
  return new Promise((resolve) => {
    const done = setTimeout(() => resolve(null), 9000);
    navigator.geolocation.getCurrentPosition(
      (p) => {
        clearTimeout(done);
        resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy_m: p.coords.accuracy });
      },
      () => {
        clearTimeout(done);
        resolve(null);
      },
      { enableHighAccuracy: true, timeout: 8500, maximumAge: 120000 },
    );
  });
}
