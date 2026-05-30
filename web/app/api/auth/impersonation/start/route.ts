import { NextResponse } from "next/server";
import { z } from "zod";

import {
  getClientCookieOptions,
  getRoleCookieOptions,
  IMPERSONATE_EMAIL_COOKIE,
  IMPERSONATE_NAME_COOKIE,
  IMPERSONATE_UID_COOKIE,
  ROLE_COOKIE
} from "@/lib/auth";
import { getVerifiedSession } from "@/lib/server-session";

const schema = z.object({
  uid: z.string().trim().min(1),
  email: z.string().trim().min(1),
  displayName: z.string().trim().optional(),
});

export async function POST(request: Request) {
  try {
    const session = await getVerifiedSession();

    if (!session || session.realRole !== "admin") {
      return NextResponse.json({ message: "Forbidden." }, { status: 403 });
    }

    const target = schema.parse(await request.json());
    const response = NextResponse.json({
      message: `Đang đăng nhập nhanh vào ${target.email}.`,
    });

    response.cookies.set(IMPERSONATE_UID_COOKIE, target.uid, {
      ...getClientCookieOptions(),
      maxAge: 60 * 60 * 24 * 5,
    });
    response.cookies.set(IMPERSONATE_EMAIL_COOKIE, target.email, {
      ...getClientCookieOptions(),
      maxAge: 60 * 60 * 24 * 5,
    });
    response.cookies.set(IMPERSONATE_NAME_COOKIE, target.displayName ?? "", {
      ...getClientCookieOptions(),
      maxAge: 60 * 60 * 24 * 5,
    });
    response.cookies.set(ROLE_COOKIE, session.realRole, {
      ...getRoleCookieOptions(),
      maxAge: 60 * 60 * 24 * 5,
    });

    return response;
  } catch (error) {
    return NextResponse.json(
      { message: error instanceof Error ? error.message : "Unable to start impersonation." },
      { status: 400 }
    );
  }
}
