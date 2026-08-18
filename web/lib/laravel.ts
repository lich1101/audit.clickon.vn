"use client";

import { auth } from "@/lib/firebase";
import { IMPERSONATE_UID_COOKIE, readClientCookie } from "@/lib/auth";
import { getLocalAuthToken } from "@/lib/local-auth";

let tokenPromise: Promise<string | undefined> | null = null;
let tokenExpiresAt = 0;
const LARAVEL_REQUEST_TIMEOUT_MS = 20_000;

export class LaravelRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly payload?: unknown,
  ) {
    super(message);
    this.name = "LaravelRequestError";
  }
}

async function getAuthHeaders() {
  const now = Date.now();
  const localToken = getLocalAuthToken();
  const impersonateUid = readClientCookie(IMPERSONATE_UID_COOKIE);

  if (localToken) {
    return {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${localToken}`,
      ...(impersonateUid ? { "X-Impersonate-Uid": impersonateUid } : {})
    };
  }

  const user = auth?.currentUser;

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
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(impersonateUid ? { "X-Impersonate-Uid": impersonateUid } : {})
  };
}

function messageFromPayload(payload: unknown): string | null {
  if (!payload || typeof payload !== "object") {
    return null;
  }

  const record = payload as Record<string, unknown>;
  if (record.errors && typeof record.errors === "object") {
    const first = Object.values(record.errors as Record<string, unknown>)[0];

    if (Array.isArray(first) && typeof first[0] === "string") {
      return first[0];
    }
  }

  const message = record.message ?? record.error;

  if (typeof message === "string" && message.trim()) {
    return message;
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
    throw new LaravelRequestError(
      messageFromPayload(payload) ?? `Laravel API request failed (${response.status}).`,
      response.status,
      payload,
    );
  }

  return payload as T;
}

export async function laravelRequest<T>(path: string, init?: RequestInit) {
  const baseUrl = process.env.NEXT_PUBLIC_LARAVEL_API_URL;

  if (!baseUrl) {
    throw new Error("NEXT_PUBLIC_LARAVEL_API_URL chưa được cấu hình.");
  }

  const headers = await getAuthHeaders();
  const shouldBypassImpersonation = path.startsWith("/api/admin") || path.startsWith("/api/credits");
  const mergedHeaders = {
    ...headers,
    ...(init?.headers ?? {})
  } as Record<string, string>;

  if (shouldBypassImpersonation) {
    delete mergedHeaders["X-Impersonate-Uid"];
  }

  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort("timeout"), LARAVEL_REQUEST_TIMEOUT_MS);

  let response: Response;

  try {
    response = await fetch(`${baseUrl}${path}`, {
      ...init,
      headers: mergedHeaders,
      signal: controller.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") {
      throw new Error("Laravel API phản hồi quá lâu. Kiểm tra web/api rồi thử lại.");
    }

    throw error;
  } finally {
    window.clearTimeout(timeoutId);
  }

  return parse<T>(response);
}
