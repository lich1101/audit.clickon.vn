import { NextResponse } from "next/server";

import { getAdminAuth } from "@/lib/firebase-admin";
import { getRoleCookieOptions, ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";
import { sessionSchema } from "@/lib/validators";

export async function POST(request: Request) {
  try {
    const adminAuth = getAdminAuth();
    const body = await request.json();
    const { idToken } = sessionSchema.parse(body);
    const decoded = await adminAuth.verifyIdToken(idToken);
    const sessionCookie = await adminAuth.createSessionCookie(idToken, {
      expiresIn: 1000 * 60 * 60 * 24 * 5
    });

    const baseUrl = process.env.LARAVEL_API_URL;
    let profile: {
      uid: string;
      email: string;
      displayName: string;
      role: "admin" | "user";
      credits: number;
      createdAt: string;
      updatedAt: string;
    };

    if (!baseUrl) {
      throw new Error("LARAVEL_API_URL chưa được cấu hình.");
    }

    const meResponse = await fetch(`${baseUrl}/api/me`, {
      method: "GET",
      headers: {
        Authorization: `Bearer ${idToken}`
      },
      cache: "no-store"
    });

    if (!meResponse.ok) {
      throw new Error("Không thể đồng bộ hồ sơ từ Laravel API.");
    }

    const mePayload = (await meResponse.json()) as {
      data?: {
        uid: string;
        email: string;
        displayName?: string;
        role: "admin" | "user";
        credits: number;
        createdAt: string;
        updatedAt: string;
      };
    };

    if (!mePayload.data) {
      throw new Error("Laravel API không trả về hồ sơ người dùng.");
    }

    profile = {
      uid: mePayload.data.uid || decoded.uid,
      email: mePayload.data.email || decoded.email || "",
      displayName: mePayload.data.displayName ?? "",
      role: mePayload.data.role === "admin" ? "admin" : "user",
      credits: Number(mePayload.data.credits ?? 0),
      createdAt: mePayload.data.createdAt,
      updatedAt: mePayload.data.updatedAt
    };

    const response = NextResponse.json({ message: "Session created.", user: profile });
    response.cookies.set(SESSION_COOKIE, sessionCookie, {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 60 * 60 * 24 * 5
    });
    response.cookies.set(ROLE_COOKIE, profile.role, {
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
