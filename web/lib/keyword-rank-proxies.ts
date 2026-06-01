"use client";

import { laravelRequest } from "@/lib/laravel";

export type KeywordRankProxyAdminConfig = {
  enabled: boolean;
  useGithubHttp: boolean;
  useGithubSocks5: boolean;
  refreshGithubOnRun: boolean;
  manualProxies: string[];
  manualProxiesText: string;
  runSampleSize: number;
};

export type KeywordRankProxyPool = {
  fetchedAt: string | null;
  httpCount: number;
  socks5Count: number;
  totalCount: number;
  proxies: string[];
  sources: {
    http: string;
    socks5: string;
  };
};

export type KeywordRankProxyForRunResult = {
  proxyEnabled: boolean;
  proxyUrls: string[];
  fetchedAt: string | null;
  httpCount: number;
  socks5Count: number;
  totalCount: number;
  runProxyCount: number;
  manualCount: number;
  usedCache?: boolean;
  sources: {
    http: string;
    socks5: string;
  };
};

export async function resolveKeywordRankProxiesForRun() {
  const response = await laravelRequest<{ message: string; data: KeywordRankProxyForRunResult }>(
    "/api/keyword-rank-proxies/for-run",
    {
      method: "POST",
      cache: "no-store"
    }
  );

  return response;
}

export async function fetchAdminKeywordRankProxySettings() {
  const response = await laravelRequest<{
    data: {
      config: KeywordRankProxyAdminConfig;
      pool: KeywordRankProxyPool;
    };
  }>("/api/admin/keyword-rank-proxies", {
    method: "GET",
    cache: "no-store"
  });

  return response.data;
}

export async function updateAdminKeywordRankProxySettings(
  input: Partial<
    Pick<
      KeywordRankProxyAdminConfig,
      "enabled" | "useGithubHttp" | "useGithubSocks5" | "refreshGithubOnRun" | "runSampleSize"
    > & { manualProxiesText?: string }
  >
) {
  return laravelRequest<{ message: string; data: KeywordRankProxyAdminConfig }>("/api/admin/keyword-rank-proxies", {
    method: "PUT",
    body: JSON.stringify(input)
  });
}

export async function refreshAdminKeywordRankGithubPool() {
  const response = await laravelRequest<{ message: string; data: KeywordRankProxyPool }>(
    "/api/admin/keyword-rank-proxies/refresh-github",
    {
      method: "POST",
      cache: "no-store"
    }
  );

  return response;
}
