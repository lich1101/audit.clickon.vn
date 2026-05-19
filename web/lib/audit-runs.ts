"use client";

import { laravelRequest } from "@/lib/laravel";
import { parseArticleUrls, parseCategories, formatCategoriesInput } from "@/lib/validators";
import type { AuditRun, WebsiteAudit, WebsiteAuditUrlResult } from "@/types";
import type { PublicAuditSettings } from "@/lib/audit-settings";

export type AuditBoard = {
  website: {
    id: string;
    name: string;
    url: string;
  };
  audit: WebsiteAudit | null;
  run: AuditRun | null;
  urlResults?: WebsiteAuditUrlResult[];
  systemAi?: PublicAuditSettings;
};

type AuditRunResponse = {
  data: AuditRun;
};

type CreateAuditRunResponse = {
  data: {
    publicId: string;
    status: AuditRun["status"];
    totalUrls: number;
  };
};

export async function stopAuditRun(publicId: string) {
  return laravelRequest<{ data: { publicId: string; status: AuditRun["status"] } }>(`/api/audit-runs/${publicId}/stop`, {
    method: "POST"
  });
}

export async function fetchAuditBoard(websiteId: string): Promise<AuditBoard> {
  const response = await laravelRequest<{ data: AuditBoard }>(`/api/websites/${websiteId}/audit-board`, {
    method: "GET",
    cache: "no-store"
  });

  return {
    website: response.data.website,
    audit: response.data.audit
      ? {
          ...response.data.audit,
          articleUrls: Array.isArray(response.data.audit.articleUrls) ? response.data.audit.articleUrls : [],
          categories: Array.isArray(response.data.audit.categories) ? response.data.audit.categories : []
        }
      : null,
    run: response.data.run ? normalizeAuditRun(response.data.run) : null,
    urlResults: Array.isArray(response.data.urlResults) ? response.data.urlResults : [],
    systemAi: response.data.systemAi ?? { aiProvider: "openai", aiModel: null, minCreditsPerRun: 0, minCreditsPerUrl: 0 }
  };
}

export async function listAuditRunsByWebsite(websiteId: string) {
  const response = await laravelRequest<{ data: AuditRun[] }>(`/api/websites/${websiteId}/audit-runs`, {
    method: "GET",
    cache: "no-store"
  });

  return response.data.map(normalizeAuditRun);
}

export function isActiveAuditRun(status?: AuditRun["status"]) {
  return status === "queued" || status === "processing";
}

export async function createAuditRun(input: {
  websiteId: string;
  websiteName?: string;
  websiteUrl?: string;
  targetUrlsInput: string;
  categoriesInput: string;
  checklistText?: string;
}) {
  const targetUrls = parseArticleUrls(input.targetUrlsInput);
  const categories = parseCategories(input.categoriesInput);

  return laravelRequest<CreateAuditRunResponse>("/api/audit-runs", {
    method: "POST",
    body: JSON.stringify({
      websiteId: input.websiteId,
      websiteName: input.websiteName,
      websiteUrl: input.websiteUrl,
      targetUrls,
      categories,
      checklistText: input.checklistText?.trim() || undefined
    })
  });
}

export async function getAuditRun(publicId: string) {
  const response = await laravelRequest<AuditRunResponse>(`/api/audit-runs/${publicId}`, {
    method: "GET",
    cache: "no-store"
  });

  return normalizeAuditRun(response.data);
}

export { formatCategoriesInput } from "@/lib/validators";

export function normalizeAuditRun(run: AuditRun): AuditRun {
  return {
    ...run,
    targetUrls: Array.isArray(run.targetUrls) ? run.targetUrls : [],
    categories: Array.isArray(run.categories) ? run.categories : [],
    categoryContexts: Array.isArray(run.categoryContexts) ? run.categoryContexts : [],
    aiProvider: run.aiProvider ?? "openai",
    aiModel: run.aiModel ?? null,
    items: Array.isArray(run.items) ? run.items.map((item) => ({
      ...item,
      auditFindings: Array.isArray(item.auditFindings) ? item.auditFindings : [],
      auditRecommendations: Array.isArray(item.auditRecommendations) ? item.auditRecommendations : [],
      headings: item.headings ?? {},
      metrics: item.metrics ?? {},
      promptSnapshots: item.promptSnapshots ?? {}
    })) : []
  };
}
