import { NextResponse } from "next/server";

import { getClientCookieOptions, IMPERSONATE_EMAIL_COOKIE, IMPERSONATE_NAME_COOKIE, IMPERSONATE_UID_COOKIE } from "@/lib/auth";
import { getVerifiedSession } from "@/lib/server-session";

export async function POST() {
  try {
    const session = await getVerifiedSession();

    if (!session || session.realRole !== "admin") {
      return NextResponse.json({ message: "Forbidden." }, { status: 403 });
    }

    const response = NextResponse.json({ message: "Đã thoát đăng nhập nhanh." });

    response.cookies.set(IMPERSONATE_UID_COOKIE, "", {
      ...getClientCookieOptions(),
      maxAge: 0,
    });
    response.cookies.set(IMPERSONATE_EMAIL_COOKIE, "", {
      ...getClientCookieOptions(),
      maxAge: 0,
    });
    response.cookies.set(IMPERSONATE_NAME_COOKIE, "", {
      ...getClientCookieOptions(),
      maxAge: 0,
    });

    return response;
  } catch (error) {
    return NextResponse.json(
      { message: error instanceof Error ? error.message : "Unable to stop impersonation." },
      { status: 400 }
    );
  }
}
