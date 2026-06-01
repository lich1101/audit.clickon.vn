"use client";

import { laravelRequest } from "@/lib/laravel";

export type KeywordRankProxyRefreshResult = {
  fetchedAt: string;
  httpCount: number;
  socks5Count: number;
  totalCount: number;
  runProxyCount: number;
  proxyUrls: string[];
  sources: {
    http: string;
    socks5: string;
  };
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

export async function refreshKeywordRankProxiesForRun() {
  const response = await laravelRequest<{ message: string; data: KeywordRankProxyRefreshResult }>(
    "/api/keyword-rank-proxies/refresh",
    {
      method: "POST",
      cache: "no-store"
    }
  );

  return response;
}

export async function fetchAdminKeywordRankProxyPool() {
  const response = await laravelRequest<{ data: KeywordRankProxyPool }>("/api/admin/keyword-rank-proxies", {
    method: "GET",
    cache: "no-store"
  });

  return response.data;
}
