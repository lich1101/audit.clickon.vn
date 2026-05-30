import { cookies } from "next/headers";

import { getAdminAuth } from "@/lib/firebase-admin";
import { IMPERSONATE_EMAIL_COOKIE, IMPERSONATE_NAME_COOKIE, IMPERSONATE_UID_COOKIE, ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";

export async function getVerifiedSession() {
  const store = await cookies();
  const sessionCookie = store.get(SESSION_COOKIE)?.value;

  if (!sessionCookie) {
    return null;
  }

  const adminAuth = getAdminAuth();
  const decoded = await adminAuth.verifySessionCookie(sessionCookie, true);
  const realRole = store.get(ROLE_COOKIE)?.value === "admin" ? "admin" : "user";

  return {
    uid: decoded.uid,
    email: decoded.email ?? "",
    role: realRole,
    realRole,
    isImpersonating: Boolean(store.get(IMPERSONATE_UID_COOKIE)?.value),
    impersonateUid: store.get(IMPERSONATE_UID_COOKIE)?.value ?? null,
    impersonateEmail: store.get(IMPERSONATE_EMAIL_COOKIE)?.value ?? null,
    impersonateName: store.get(IMPERSONATE_NAME_COOKIE)?.value ?? null,
    balanceUsd: 0,
    credits: 0
  };
}

export async function clearSessionCookies() {
  const store = await cookies();
  store.delete(SESSION_COOKIE);
  store.delete(ROLE_COOKIE);
  store.delete(IMPERSONATE_UID_COOKIE);
  store.delete(IMPERSONATE_EMAIL_COOKIE);
  store.delete(IMPERSONATE_NAME_COOKIE);
}
