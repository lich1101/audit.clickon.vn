import { cookies } from "next/headers";

import { getAdminAuth } from "@/lib/firebase-admin";
import { ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";

export async function getVerifiedSession() {
  const store = await cookies();
  const sessionCookie = store.get(SESSION_COOKIE)?.value;

  if (!sessionCookie) {
    return null;
  }

  const adminAuth = getAdminAuth();
  const decoded = await adminAuth.verifySessionCookie(sessionCookie, true);
  const role = store.get(ROLE_COOKIE)?.value === "admin" ? "admin" : "user";

  return {
    uid: decoded.uid,
    email: decoded.email ?? "",
    role,
    balanceUsd: 0,
    credits: 0
  };
}

export async function clearSessionCookies() {
  const store = await cookies();
  store.delete(SESSION_COOKIE);
  store.delete(ROLE_COOKIE);
}
