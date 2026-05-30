import { NextResponse } from "next/server";

import { IMPERSONATE_EMAIL_COOKIE, IMPERSONATE_NAME_COOKIE, IMPERSONATE_UID_COOKIE, ROLE_COOKIE, SESSION_COOKIE } from "@/lib/auth";

export async function POST() {
  const response = NextResponse.json({ message: "Logged out." });
  response.cookies.delete(SESSION_COOKIE);
  response.cookies.delete(ROLE_COOKIE);
  response.cookies.delete(IMPERSONATE_UID_COOKIE);
  response.cookies.delete(IMPERSONATE_EMAIL_COOKIE);
  response.cookies.delete(IMPERSONATE_NAME_COOKIE);
  return response;
}
