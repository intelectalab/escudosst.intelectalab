"use client";

import QRCode from "qrcode";
import { useEffect, useState } from "react";

/** Dibuja el enlace otpauth como QR en el navegador (el secreto no sale a ningún servicio externo). */
export function QrCode({ value, size = 200 }: { value: string; size?: number }) {
  const [src, setSrc] = useState<string | null>(null);

  useEffect(() => {
    QRCode.toDataURL(value, { width: size, margin: 1, errorCorrectionLevel: "M" })
      .then(setSrc)
      .catch(() => setSrc(null));
  }, [value, size]);

  if (!src) return <div style={{ width: size, height: size }} className="rounded-lg bg-na-soft" aria-hidden="true" />;

  // eslint-disable-next-line @next/next/no-img-element
  return <img src={src} width={size} height={size} alt="Código QR para tu app de autenticación" className="rounded-lg bg-white p-2" />;
}
