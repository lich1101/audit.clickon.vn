import { cookies } from "next/headers";

import { adminAuth, adminDb } from "@/lib/firebase-admin";
import { ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";

export async function getVerifiedSession() {
  const store = await cookies();
  const sessionCookie = store.get(SESSION_COOKIE)?.value;

  if (!sessionCookie) {
    return null;
  }

  const decoded = await adminAuth.verifySessionCookie(sessionCookie, true);
  const profile = await adminDb.collection("users").doc(decoded.uid).get();
  const data = profile.data() ?? {};

  return {
    uid: decoded.uid,
    email: decoded.email ?? "",
    role: data.role === "admin" ? "admin" : "user",
    credits: Number(data.credits ?? 0)
  };
}

export async function clearSessionCookies() {
  const store = await cookies();
  store.delete(SESSION_COOKIE);
  store.delete(ROLE_COOKIE);
}
