import { NextResponse } from "next/server";

import { getAdminAuth, getAdminDb } from "@/lib/firebase-admin";
import { getRoleCookieOptions, ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";
import { sessionSchema } from "@/lib/validators";

function serializeDate(value: unknown, fallback: string) {
  if (!value) {
    return fallback;
  }

  if (typeof value === "string") {
    return value;
  }

  if (typeof value === "object" && value !== null && "toDate" in value && typeof value.toDate === "function") {
    return value.toDate().toISOString();
  }

  return new Date(String(value)).toISOString();
}

export async function POST(request: Request) {
  try {
    const adminAuth = getAdminAuth();
    const adminDb = getAdminDb();
    const body = await request.json();
    const { idToken } = sessionSchema.parse(body);
    const decoded = await adminAuth.verifyIdToken(idToken);
    const sessionCookie = await adminAuth.createSessionCookie(idToken, {
      expiresIn: 1000 * 60 * 60 * 24 * 5
    });

    const userRef = adminDb.collection("users").doc(decoded.uid);
    const snapshot = await userRef.get();
    const existing = snapshot.data() ?? {};
    const now = new Date().toISOString();
    const role = existing.role === "admin" ? "admin" : "user";
    const profile = {
      uid: decoded.uid,
      email: decoded.email ?? String(existing.email ?? ""),
      displayName: String(existing.displayName ?? decoded.name ?? ""),
      role,
      credits: Number(existing.credits ?? 0),
      createdAt: serializeDate(existing.createdAt, now),
      updatedAt: now
    };

    const shouldSyncProfile =
      String(existing.email ?? "") !== profile.email
      || String(existing.displayName ?? "") !== profile.displayName
      || existing.role !== profile.role
      || Number(existing.credits ?? 0) !== profile.credits;

    if (shouldSyncProfile) {
      await userRef.set(profile, { merge: true });
    }

    const response = NextResponse.json({ message: "Session created.", user: profile });
    response.cookies.set(SESSION_COOKIE, sessionCookie, {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 60 * 60 * 24 * 5
    });
    response.cookies.set(ROLE_COOKIE, role, {
      ...getRoleCookieOptions(),
      maxAge: 60 * 60 * 24 * 5
    });

    return response;
  } catch (error) {
    return NextResponse.json(
      {
        message: error instanceof Error ? error.message : "Unable to create session."
      },
      { status: 500 }
    );
  }
}
