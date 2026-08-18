import { NextResponse } from "next/server";

import {
  getClientCookieOptions,
  getRoleCookieOptions,
  IMPERSONATE_EMAIL_COOKIE,
  IMPERSONATE_NAME_COOKIE,
  IMPERSONATE_UID_COOKIE,
  ROLE_COOKIE,
  SESSION_COOKIE,
} from "@/lib/auth";

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as { email?: string; password?: string };
    const baseUrl = process.env.LARAVEL_API_URL;

    if (!baseUrl) {
      throw new Error("LARAVEL_API_URL chưa được cấu hình.");
    }

    const laravelResponse = await fetch(`${baseUrl}/api/auth/local-login`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ email: body.email, password: body.password }),
      cache: "no-store",
    });

    const payload = (await laravelResponse.json()) as {
      message?: string;
      token?: string;
      user?: {
        uid: string;
        email: string;
        displayName?: string;
        role: "admin" | "user";
        balanceUsd?: number;
        credits: number;
        captchaCredits?: number;
        createdAt: string;
        updatedAt: string;
      };
    };

    if (!laravelResponse.ok || !payload.token || !payload.user) {
      return NextResponse.json(
        { message: payload.message || "Đăng nhập thất bại." },
        { status: laravelResponse.status || 401 },
      );
    }

    const profile = {
      uid: payload.user.uid,
      email: payload.user.email,
      displayName: payload.user.displayName ?? "",
      role: payload.user.role === "admin" ? "admin" : "user",
      realRole: payload.user.role === "admin" ? "admin" : "user",
      isImpersonating: false,
      balanceUsd: Number(payload.user.balanceUsd ?? 0),
      credits: Number(payload.user.credits ?? 0),
      captchaCredits: Number(payload.user.captchaCredits ?? 0),
      createdAt: payload.user.createdAt,
      updatedAt: payload.user.updatedAt,
    };

    const response = NextResponse.json({ message: "Session created.", token: payload.token, user: profile });
    response.cookies.set(SESSION_COOKIE, payload.token, {
      httpOnly: true,
      secure: process.env.NODE_ENV === "production",
      sameSite: "lax",
      path: "/",
      maxAge: 60 * 60 * 24 * 5,
    });
    response.cookies.set(ROLE_COOKIE, profile.role, {
      ...getRoleCookieOptions(),
      maxAge: 60 * 60 * 24 * 5,
    });
    response.cookies.set(IMPERSONATE_UID_COOKIE, "", { ...getClientCookieOptions(), maxAge: 0 });
    response.cookies.set(IMPERSONATE_EMAIL_COOKIE, "", { ...getClientCookieOptions(), maxAge: 0 });
    response.cookies.set(IMPERSONATE_NAME_COOKIE, "", { ...getClientCookieOptions(), maxAge: 0 });

    return response;
  } catch (error) {
    return NextResponse.json(
      { message: error instanceof Error ? error.message : "Unable to create session." },
      { status: 500 },
    );
  }
}
