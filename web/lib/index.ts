"use client";

import { laravelRequest } from "@/lib/laravel";
import type {
  IndexImportResult,
  IndexPreview,
  IndexProperty,
  IndexPropertyDetail,
  IndexQuotaStatus,
  IndexSettings,
  IndexSettingsTestResult,
  IndexUrlList,
  IndexUrlView
} from "@/types";

type IndexListResponse = {
  data: IndexProperty[];
  quota: IndexQuotaStatus;
};

type IndexDetailResponse = {
  data: IndexPropertyDetail;
};

type IndexPreviewResponse = {
  data: IndexPreview;
};

type IndexQuotaResponse = {
  data: IndexQuotaStatus;
};

export async function fetchIndexProperties() {
  return laravelRequest<IndexListResponse>("/api/index/properties");
}

export async function fetchIndexPropertyReport(propertyId: number) {
  return laravelRequest<IndexDetailResponse>(`/api/index/properties/${propertyId}/report`);
}

export async function fetchIndexUrls(view: IndexUrlView, page = 1, perPage: number | "all" = 10) {
  const params = new URLSearchParams({
    view,
    page: String(page),
    perPage: String(perPage)
  });
  return laravelRequest<{ data: IndexUrlList }>(`/api/index/urls?${params.toString()}`);
}

export async function createIndexProperty(input: {
  site: string;
  name?: string;
  links?: string;
  confirmOwned?: boolean;
}) {
  return laravelRequest<Record<string, unknown>>("/api/index/properties", {
    method: "POST",
    body: JSON.stringify(input)
  });
}

export async function previewIndexLinks(text: string) {
  return laravelRequest<IndexPreviewResponse>("/api/index/preview", {
    method: "POST",
    body: JSON.stringify({ text })
  });
}

export async function importIndexLinks(propertyId: number, text: string) {
  return laravelRequest<IndexImportResult>(`/api/index/properties/${propertyId}/import`, {
    method: "POST",
    body: JSON.stringify({ text })
  });
}

export async function importIndexLinksGlobal(text: string) {
  return laravelRequest<{
    ok: boolean;
    totalLinks?: number;
    results?: IndexImportResult[];
    message?: string;
  }>("/api/index/import-global", {
    method: "POST",
    body: JSON.stringify({ text })
  });
}

export async function fetchIndexQuota() {
  return laravelRequest<IndexQuotaResponse>("/api/index/quota");
}

export async function verifyIndexOwnership(site: string) {
  return laravelRequest<{ data: Record<string, unknown> }>("/api/index/verify-ownership", {
    method: "POST",
    body: JSON.stringify({ site })
  });
}

export async function fetchIndexSettings() {
  return laravelRequest<{ data: IndexSettings }>("/api/index/settings");
}

export async function saveIndexSettings(input: { serviceAccountJson?: string; dryRun?: boolean }) {
  return laravelRequest<{
    ok: boolean;
    partial?: boolean;
    message?: string;
    settings?: IndexSettings;
    test?: IndexSettingsTestResult;
  }>("/api/index/settings", {
    method: "PUT",
    body: JSON.stringify(input)
  });
}

export async function testIndexSettings() {
  return laravelRequest<{ data: IndexSettingsTestResult }>("/api/index/settings/test", {
    method: "POST"
  });
}

export async function syncIndexFromGsc() {
  return laravelRequest<{
    ok?: boolean;
    created?: number;
    updated?: number;
    revoked?: number;
    message?: string;
  }>("/api/index/sync-gsc", {
    method: "POST"
  });
}

export async function runIndexPublish(batchSize = 50) {
  return laravelRequest<{ ok: boolean; message?: string; data?: { sent: number; failed: number } }>("/api/index/publish", {
    method: "POST",
    body: JSON.stringify({ batchSize })
  });
}
