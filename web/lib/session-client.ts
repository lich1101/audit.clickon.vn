"use client";

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

export async function syncClientSession(idToken: string) {
  const response = await fetch("/api/auth/session", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    credentials: "same-origin",
    body: JSON.stringify({ idToken })
  });

  if (!response.ok) {
    throw new Error(await readSessionError(response, "Không thể tạo phiên đăng nhập."));
  }
}

export async function clearClientSession() {
  const response = await fetch("/api/auth/logout", {
    method: "POST",
    credentials: "same-origin"
  });

  if (!response.ok) {
    throw new Error(await readSessionError(response, "Không thể xóa phiên đăng nhập."));
  }
}
