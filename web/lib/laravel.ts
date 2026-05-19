"use client";

import { auth } from "@/lib/firebase";

let tokenPromise: Promise<string | undefined> | null = null;
let tokenExpiresAt = 0;

async function getAuthHeaders() {
  const now = Date.now();
  const user = auth.currentUser;

  if (!user) {
    return {
      "Content-Type": "application/json"
    };
  }

  if (!tokenPromise || now >= tokenExpiresAt) {
    tokenExpiresAt = now + 50_000;
    tokenPromise = user.getIdToken().catch(() => undefined);
  }

  const token = await tokenPromise;

  return {
    "Content-Type": "application/json",
    ...(token ? { Authorization: `Bearer ${token}` } : {})
  };
}

async function parse<T>(response: Response): Promise<T> {
  const payload = await response.json();

  if (!response.ok) {
    throw new Error(payload.message ?? "Laravel API request failed.");
  }

  return payload as T;
}

export async function laravelRequest<T>(path: string, init?: RequestInit) {
  const baseUrl = process.env.NEXT_PUBLIC_LARAVEL_API_URL;

  if (!baseUrl) {
    throw new Error("NEXT_PUBLIC_LARAVEL_API_URL chưa được cấu hình.");
  }

  const headers = await getAuthHeaders();
  const response = await fetch(`${baseUrl}${path}`, {
    ...init,
    headers: {
      ...headers,
      ...(init?.headers ?? {})
    }
  });

  return parse<T>(response);
}
