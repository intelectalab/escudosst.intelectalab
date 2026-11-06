"use client";

import { Icon } from "./Icon";

type Variant = "primary" | "secondary" | "soft" | "danger" | "ghost" | "danger-ghost";
type Size = "sm" | "md" | "lg";

const VARIANTS: Record<Variant, string> = {
  primary: "bg-brand text-white shadow-sm shadow-brand/30 hover:bg-brand-strong hover:shadow-md",
  secondary: "border border-line bg-surface text-fg shadow-sm hover:border-brand hover:text-brand-strong",
  soft: "bg-brand-soft text-brand-strong hover:bg-brand hover:text-white",
  danger: "bg-bad text-white shadow-sm hover:brightness-95",
  ghost: "text-brand hover:bg-brand-soft",
  "danger-ghost": "text-bad hover:bg-bad-soft",
};

const SIZES: Record<Size, string> = {
  sm: "h-8 gap-1.5 px-3 text-sm",
  md: "h-10 gap-2 px-4 text-sm",
  lg: "h-12 gap-2 px-6 text-base",
};

/** Botón con estilos únicos para toda la aplicación. */
export function Button({
  variant = "secondary",
  size = "md",
  icon,
  loading,
  full,
  className = "",
  children,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant;
  size?: Size;
  icon?: string;
  loading?: boolean;
  full?: boolean;
}) {
  return (
    <button
      type={props.type ?? "button"}
      {...props}
      disabled={props.disabled || loading}
      className={`inline-flex items-center justify-center whitespace-nowrap rounded-lg font-semibold transition-all duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50 ${VARIANTS[variant]} ${SIZES[size]} ${full ? "w-full" : ""} ${className}`}
    >
      {loading ? (
        <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
      ) : (
        icon && <Icon name={icon} size={size === "sm" ? 15 : 17} />
      )}
      {children}
    </button>
  );
}
