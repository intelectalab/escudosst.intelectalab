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
