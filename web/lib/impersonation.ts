"use client";

import type { AppUser } from "@/types";

async function parseMessage(response: Response, fallback: string) {
  try {
    const payload = (await response.json()) as { message?: string };
    return payload.message || fallback;
  } catch {
    return fallback;
  }
}

export async function startImpersonation(user: Pick<AppUser, "uid" | "email" | "displayName">) {
  const response = await fetch("/api/auth/impersonation/start", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    credentials: "same-origin",
    body: JSON.stringify(user),
  });

  if (!response.ok) {
    throw new Error(await parseMessage(response, "Không thể đăng nhập nhanh vào tài khoản này."));
  }

  return response.json() as Promise<{ message?: string }>;
}

export async function stopImpersonation() {
  const response = await fetch("/api/auth/impersonation/stop", {
    method: "POST",
    credentials: "same-origin",
  });

  if (!response.ok) {
    throw new Error(await parseMessage(response, "Không thể thoát đăng nhập nhanh."));
  }

  return response.json() as Promise<{ message?: string }>;
}
