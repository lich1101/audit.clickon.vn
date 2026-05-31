"use client";

import { laravelRequest } from "@/lib/laravel";

export type KeywordRankSystemSettings = {
  extensionInstallUrl: string;
  serpPages: number;
};

export async function fetchAdminKeywordRankSettings() {
  const response = await laravelRequest<{ data: KeywordRankSystemSettings }>("/api/admin/keyword-rank-settings", {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}

export async function updateAdminKeywordRankSettings(input: Partial<KeywordRankSystemSettings>) {
  const response = await laravelRequest<{ data: KeywordRankSystemSettings }>("/api/admin/keyword-rank-settings", {
    method: "PUT",
    body: JSON.stringify(input)
  });
  return response.data;
}
