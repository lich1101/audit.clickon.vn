import { cookies } from "next/headers";

import type { SessionUser, UserRole } from "@/types";

export const SESSION_COOKIE = "clickon_audit_session";
export const ROLE_COOKIE = "clickon_audit_role";

export function isAdminRole(role: string | null | undefined): role is UserRole {
  return role === "admin";
}

export async function getSessionSnapshot() {
  const store = await cookies();
  return {
    token: store.get(SESSION_COOKIE)?.value ?? null,
    role: store.get(ROLE_COOKIE)?.value ?? null
  };
}

export function getRoleCookieOptions() {
  return {
    httpOnly: true,
    path: "/",
    sameSite: "lax" as const,
    secure: process.env.NODE_ENV === "production"
  };
}

export function mapSessionUser(data: Record<string, unknown>): SessionUser {
  return {
    uid: String(data.uid ?? ""),
    email: String(data.email ?? ""),
    role: (data.role === "admin" ? "admin" : "user") as UserRole,
    credits: Number(data.credits ?? 0),
    displayName: data.displayName ? String(data.displayName) : undefined
  };
}
