"use client";

// Selector de empresa: el usuario elige con qué empresa trabajar (docs/08-api.md §3).

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { AppBrand } from "@/components/AppBrand";
import { api, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import type { CompanyOption, User } from "@/lib/types";

export default function SelectCompanyPage() {
  const router = useRouter();
  const [companies, setCompanies] = useState<CompanyOption[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [choosing, setChoosing] = useState<string | null>(null);

  const choose = useCallback(
    async (companyId: string) => {
      setChoosing(companyId);
      setError(null);
      try {
        await api("/me/switch-company", { method: "POST", body: { company_id: companyId } });
        session.setCompanyId(companyId);
        router.push("/inicio");
      } catch (err) {
        setError(err instanceof Error ? err.message : "No pudimos cambiar de empresa.");
        setChoosing(null);
      }
    },
    [router],
  );

  useEffect(() => {
    if (!session.getToken()) {
      router.replace("/login");
      return;
    }
    api<User>("/me")
      .then((me) => {
        if (me.is_platform_staff) {
          router.replace("/plataforma");
          return null;
        }
        return api<{ data: CompanyOption[] }>("/me/companies");
      })
      .then((res) => {
        if (!res) return;
        setCompanies(res.data);
        // Con una sola empresa no hay nada que elegir: entra directo.
        if (res.data.length === 1) choose(res.data[0].company_id);
      })
      .catch((err) => {
        if (err instanceof ApiError && err.status === 401) router.replace("/login");
        else setError(err instanceof Error ? err.message : "No pudimos cargar tus empresas.");
      });
  }, [router, choose]);

  return (
    <main className="min-h-screen flex items-center justify-center p-6">
      <div className="w-full max-w-lg space-y-6">
        <AppBrand />
        <div>
          <h1 className="text-2xl font-semibold">¿Con qué empresa vas a trabajar?</h1>
          <p className="text-muted mt-1">Puedes cambiar de empresa en cualquier momento.</p>
        </div>

        {error && (
          <div role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">
            {error}
          </div>
        )}

        {companies === null && !error && <p className="text-muted">Cargando…</p>}

        {companies?.length === 0 && (
          <div className="rounded-xl border border-line bg-surface p-6 text-muted">
            No tienes acceso a ninguna empresa todavía. Pide a tu administrador que te invite.
          </div>
        )}

        <ul className="space-y-3">
          {companies?.map((c) => (
            <li key={c.company_id}>
              <button
                onClick={() => choose(c.company_id)}
                disabled={choosing !== null}
                className="w-full text-left rounded-xl border border-line bg-surface p-5 hover:border-brand hover:bg-brand-soft disabled:opacity-60 transition-colors"
              >
                <span className="block font-display text-lg font-semibold">{c.name}</span>
                <span className="block text-sm text-muted">
                  RUC {c.ruc} · {c.role.name}
                </span>
                {choosing === c.company_id && <span className="block text-sm text-brand mt-1">Entrando…</span>}
              </button>
            </li>
          ))}
        </ul>
      </div>
    </main>
  );
}
