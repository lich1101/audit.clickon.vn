"use client";

import { laravelRequest } from "@/lib/laravel";
import { parseArticleUrls, parseCategories } from "@/lib/validators";
import type { AiProvider, AuditCategory, AuditRun } from "@/types";

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

export async function createAuditRun(input: {
  websiteId: string;
  websiteName?: string;
  websiteUrl?: string;
  targetUrlsInput: string;
  categoriesInput: string;
  checklistText?: string;
  aiProvider?: AiProvider;
  aiModel?: string;
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
      checklistText: input.checklistText?.trim() || undefined,
      aiProvider: input.aiProvider ?? "openai",
      aiModel: input.aiModel?.trim() || undefined
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

export function formatCategoriesInput(categories: AuditCategory[]) {
  return categories.map((category) => `${category.name}\t${category.url}`).join("\n");
}

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
