import { NextResponse } from "next/server";

import { adminAuth, adminDb } from "@/lib/firebase-admin";
import { getRoleCookieOptions, ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";
import { sessionSchema } from "@/lib/validators";

export async function POST(request: Request) {
  try {
    const body = await request.json();
    const { idToken } = sessionSchema.parse(body);
    const decoded = await adminAuth.verifyIdToken(idToken);
    const sessionCookie = await adminAuth.createSessionCookie(idToken, {
      expiresIn: 1000 * 60 * 60 * 24 * 5
    });

    const userRef = adminDb.collection("users").doc(decoded.uid);
    const snapshot = await userRef.get();
    const role = snapshot.exists && snapshot.data()?.role === "admin" ? "admin" : "user";

    if (!snapshot.exists) {
      await userRef.set(
        {
          uid: decoded.uid,
          email: decoded.email ?? "",
          role,
          credits: 0,
          createdAt: new Date().toISOString(),
          updatedAt: new Date().toISOString()
        },
        { merge: true }
      );
    }

    const response = NextResponse.json({ message: "Session created." });
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
      { status: 400 }
    );
  }
}
