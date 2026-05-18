"use client";

import { auth } from "@/lib/firebase";

async function getAuthHeaders() {
  const token = await auth.currentUser?.getIdToken();

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
