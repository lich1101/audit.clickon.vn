"use client";

import { auth } from "@/lib/firebase";

let tokenPromise: Promise<string | undefined> | null = null;
let tokenExpiresAt = 0;

async function getAuthHeaders() {
  const now = Date.now();
  const user = auth.currentUser;

  if (!user) {
    return {
      Accept: "application/json",
      "Content-Type": "application/json"
    };
  }

  if (!tokenPromise || now >= tokenExpiresAt) {
    tokenExpiresAt = now + 50_000;
    tokenPromise = user.getIdToken().catch(() => undefined);
  }

  const token = await tokenPromise;

  return {
    Accept: "application/json",
    "Content-Type": "application/json",
    ...(token ? { Authorization: `Bearer ${token}` } : {})
  };
}

function messageFromPayload(payload: unknown): string | null {
  if (!payload || typeof payload !== "object") {
    return null;
  }

  const record = payload as Record<string, unknown>;
  const message = record.message ?? record.error;

  if (typeof message === "string" && message.trim()) {
    return message;
  }

  if (record.errors && typeof record.errors === "object") {
    const first = Object.values(record.errors as Record<string, unknown>)[0];

    if (Array.isArray(first) && typeof first[0] === "string") {
      return first[0];
    }
  }

  return null;
}

async function parse<T>(response: Response): Promise<T> {
  const raw = await response.text();
  const trimmed = raw.trim();
  let payload: unknown = null;

  if (trimmed) {
    try {
      payload = JSON.parse(trimmed);
    } catch {
      const snippet = trimmed.replace(/\s+/g, " ").slice(0, 180);
      const message = response.ok
        ? "Laravel API trả về dữ liệu không phải JSON."
        : `Laravel API trả về lỗi không phải JSON (${response.status}). ${snippet}`;

      throw new Error(message);
    }
  }

  if (!response.ok) {
    throw new Error(messageFromPayload(payload) ?? `Laravel API request failed (${response.status}).`);
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
