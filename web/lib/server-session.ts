import { cookies } from "next/headers";

import { getAdminAuth } from "@/lib/firebase-admin";
import { IMPERSONATE_EMAIL_COOKIE, IMPERSONATE_NAME_COOKIE, IMPERSONATE_UID_COOKIE, ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";
import { createHmac } from "node:crypto";

function verifyLocalSessionToken(token: string) {
  if (!token.startsWith("local.")) {
    return null;
  }

  const parts = token.split(".");
  if (parts.length !== 3) {
    return null;
  }

  const [, body, signature] = parts;
  const secret = process.env.LARAVEL_INTERNAL_API_KEY || "change-this-in-production";
  const expected = createHmac("sha256", secret).update(body).digest("hex");

  if (expected !== signature) {
    return null;
  }

  const padded = body.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (body.length % 4)) % 4);
  const payload = JSON.parse(Buffer.from(padded, "base64").toString("utf8")) as {
    uid?: string;
    email?: string;
    role?: string;
    exp?: number;
  };

  if (!payload.uid || !payload.exp || payload.exp < Math.floor(Date.now() / 1000)) {
    return null;
  }

  return payload;
}

export async function getVerifiedSession() {
  const store = await cookies();
  const sessionCookie = store.get(SESSION_COOKIE)?.value;

  if (!sessionCookie) {
    return null;
  }

  const local = verifyLocalSessionToken(sessionCookie);
  if (local) {
    const realRole = store.get(ROLE_COOKIE)?.value === "admin" || local.role === "admin" ? "admin" : "user";

    return {
      uid: local.uid as string,
      email: local.email ?? "",
      role: realRole,
      realRole,
      isImpersonating: Boolean(store.get(IMPERSONATE_UID_COOKIE)?.value),
      impersonateUid: store.get(IMPERSONATE_UID_COOKIE)?.value ?? null,
      impersonateEmail: store.get(IMPERSONATE_EMAIL_COOKIE)?.value ?? null,
      impersonateName: store.get(IMPERSONATE_NAME_COOKIE)?.value ?? null,
      balanceUsd: 0,
      credits: 0,
      captchaCredits: 0
    };
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
    credits: 0,
    captchaCredits: 0
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
