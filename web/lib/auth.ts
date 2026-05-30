import { cookies } from "next/headers";

import type { SessionUser, UserRole } from "@/types";

export const SESSION_COOKIE = "clickon_audit_session";
export const ROLE_COOKIE = "clickon_audit_role";
export const IMPERSONATE_UID_COOKIE = "clickon_audit_impersonate_uid";
export const IMPERSONATE_EMAIL_COOKIE = "clickon_audit_impersonate_email";
export const IMPERSONATE_NAME_COOKIE = "clickon_audit_impersonate_name";

export function isAdminRole(role: string | null | undefined): role is UserRole {
  return role === "admin";
}

export async function getSessionSnapshot() {
  const store = await cookies();
  return {
    token: store.get(SESSION_COOKIE)?.value ?? null,
    role: store.get(ROLE_COOKIE)?.value ?? null,
    impersonateUid: store.get(IMPERSONATE_UID_COOKIE)?.value ?? null,
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

export function getClientCookieOptions() {
  return {
    httpOnly: false,
    path: "/",
    sameSite: "lax" as const,
    secure: process.env.NODE_ENV === "production"
  };
}

export function readClientCookie(name: string) {
  if (typeof document === "undefined") {
    return null;
  }

  const prefix = `${encodeURIComponent(name)}=`;
  const match = document.cookie
    .split("; ")
    .find((chunk) => chunk.startsWith(prefix));

  return match ? decodeURIComponent(match.slice(prefix.length)) : null;
}

export function mapSessionUser(data: Record<string, unknown>): SessionUser {
  return {
    uid: String(data.uid ?? ""),
    email: String(data.email ?? ""),
    role: (data.role === "admin" ? "admin" : "user") as UserRole,
    realRole: (data.realRole === "admin" ? "admin" : (data.role === "admin" ? "admin" : "user")) as UserRole,
    isImpersonating: Boolean(data.isImpersonating),
    balanceUsd: Number(data.balanceUsd ?? 0),
    credits: Number(data.credits ?? 0),
    displayName: data.displayName ? String(data.displayName) : undefined
  };
}
