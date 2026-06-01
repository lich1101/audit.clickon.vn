"use client";

import type { AppUser } from "@/types";

const SESSION_SYNC_TIMEOUT_MS = 20_000;
const SESSION_CLEAR_TIMEOUT_MS = 10_000;

async function readSessionError(response: Response, fallbackMessage: string) {
  try {
    const data = (await response.json()) as { message?: string };
    if (data.message) {
      return data.message;
    }
  } catch {
    // Ignore JSON parsing failures and fall back to the generic message.
  }

  return fallbackMessage;
}

async function fetchWithTimeout(input: RequestInfo | URL, init: RequestInit, timeoutMs: number) {
  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort("timeout"), timeoutMs);

  try {
    return await fetch(input, {
      ...init,
      signal: controller.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") {
      throw new Error("Hết thời gian chờ đồng bộ phiên đăng nhập. Kiểm tra web/api rồi tải lại trang.");
    }

    throw error;
  } finally {
    window.clearTimeout(timeoutId);
  }
}

export async function syncClientSession(idToken: string) {
  const response = await fetchWithTimeout("/api/auth/session", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    credentials: "same-origin",
    body: JSON.stringify({ idToken })
  }, SESSION_SYNC_TIMEOUT_MS);

  if (!response.ok) {
    throw new Error(await readSessionError(response, "Không thể tạo phiên đăng nhập."));
  }

  const data = (await response.json()) as { user?: AppUser };

  if (!data.user) {
    throw new Error("Phiên đăng nhập không trả về hồ sơ người dùng.");
  }

  return data.user;
}

export async function clearClientSession() {
  const response = await fetchWithTimeout("/api/auth/logout", {
    method: "POST",
    credentials: "same-origin"
  }, SESSION_CLEAR_TIMEOUT_MS);

  if (!response.ok) {
    throw new Error(await readSessionError(response, "Không thể xóa phiên đăng nhập."));
  }
}
