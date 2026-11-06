// Tipos provisionales de la API. Reemplazar por los generados desde api/openapi.yaml.

export type User = {
  id: string;
  email: string;
  full_name: string;
  locale: string;
  timezone: string;
  mfa_enabled: boolean;
  is_platform_staff: boolean;
  last_login_at: string | null;
};

export type SessionResponse = {
  mfa_required: false;
  token: string;
  expires_at: string | null;
  user: User;
  recovery_codes?: string[];
  recovery_codes_left?: number;
};

export type MfaChallenge = {
  mfa_required: true;
  mfa_setup_required: boolean;
  mfa_challenge_id: string;
  expires_in: number;
};

export type LoginResponse = SessionResponse | MfaChallenge;

export type MfaSetup = {
  secret: string;
  otpauth_uri: string;
  issuer: string;
  account: string;
};

export type MfaStatus = {
  mfa_enabled: boolean;
  mfa_required: boolean;
  recovery_codes_left: number;
};

export type CompanyOption = {
  company_id: string;
  name: string;
  ruc: string;
  role: { code: string; name: string };
  expires_at: string | null;
};

export type ModuleMode = "enabled" | "read_only" | "locked" | "disabled_by_company";

export type ModuleItem = {
  code: string;
  number: string;
  name: string;
  solves: string | null;
  group_number: number;
  plan_code: string;
  mode: ModuleMode;
  /** Plan más bajo que hoy incluye el módulo (E, P, C) o «+» si es complemento. */
  required_plan: string;
  /** El rol tiene algún permiso del módulo. */
  role_can_use: boolean;
};

// ---- Panel interno (SUS) ----

export type PlanCode = "E" | "P" | "C" | "K";

export type PlanLimits = {
  max_companies: number | null;
  max_workers: number | null;
  max_sites: number | null;
  max_management_users: number | null;
  storage_gb: number | null;
  ai_requests_month: number | null;
  qr_report_points: number | null;
};

export type PlanSummary = {
  code: PlanCode;
  name: string;
  description: string | null;
  sort_order: number;
  price_reference: string | null;
  currency: string;
  billing_periods: ("monthly" | "annual")[];
  is_public: boolean;
  is_active: boolean;
  limits: PlanLimits;
  modules_count: number;
  companies_count: number;
};

export type PlanMatrixModule = {
  code: string;
  number: string;
  name: string;
  group_number: number;
  plan_code: string;
  phase: number;
  assignable: boolean;
  core: boolean;
  plans: Record<"E" | "P" | "C", "native" | "inherited" | null>;
};

export type Addon = {
  code: string;
  name: string;
  description: string | null;
  module_code: string;
  available_in_v1: boolean;
  is_per_sector: boolean;
  price_reference: string | null;
  currency: string;
  is_active: boolean;
  subscriptions_count: number;
};

export type PlanMatrix = {
  plans: PlanSummary[];
  modules: PlanMatrixModule[];
  addons: Addon[];
};

export type PermissionsResponse = {
  company_id: string;
  role_code: string;
  data: string[];
};
