"use client";

import { laravelRequest } from "@/lib/laravel";
import { parseArticleUrls, parseCategories, formatCategoriesInput } from "@/lib/validators";
import type { AuditRun, AuditRunStartStep, AuditWorkflow, WebsiteAudit, WebsiteAuditUrlResult } from "@/types";
import type { PublicAuditSettings } from "@/lib/audit-settings";

export const ACTIVE_AUDIT_POLL_INTERVAL_MS = 3000;

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
  message: string;
  data: {
    publicId: string;
    status: AuditRun["status"];
    workflow?: AuditWorkflow;
    startFromStep: AuditRunStartStep;
    requestedTotalUrls: number;
    totalUrls: number;
    queuedTargetUrls: string[];
    skippedTargetUrls: string[];
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
  const defaultSystemAi: PublicAuditSettings = {
    aiProvider: "openai",
    aiModel: null,
    step2AiProvider: "openai",
    step2AiModel: null,
    step3AiProvider: "openai",
    step3AiModel: null,
    step2FormatterProvider: "gemini",
    step2FormatterModel: "gemini-2.5-flash",
    step3FormatterProvider: "gemini",
    step3FormatterModel: "gemini-2.5-flash",
    step3FlowMode: "standard",
    maxParallelItems: 3,
    step2BatchSize: 60,
    step3BatchSize: 30,
    deepResearchBatchSize: 5,
    deepResearchResearchProvider: "perplexity",
    deepResearchResearchModel: "sonar-deep-research",
    deepResearchReasoningProvider: "openai",
    deepResearchReasoningModel: "gpt-5.5",
    deepResearchFormatterProvider: "openai",
    deepResearchFormatterModel: "gpt-5.5",
    minCreditsPerAiCall: 0,
    minCreditsPerRun: 0,
    minCreditsPerUrl: 0
  };
  const systemAi = response.data.systemAi ?? defaultSystemAi;

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
    systemAi: {
      ...defaultSystemAi,
      ...systemAi,
      step3FlowMode: systemAi.step3FlowMode ?? "standard",
      step2AiProvider: systemAi.step2AiProvider ?? systemAi.aiProvider,
      step3AiProvider: systemAi.step3AiProvider ?? systemAi.aiProvider,
      deepResearchResearchProvider: systemAi.deepResearchResearchProvider ?? "perplexity",
      deepResearchReasoningProvider: systemAi.deepResearchReasoningProvider ?? "openai"
    }
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
  callbackUrl?: string;
  startFromStep?: AuditRunStartStep;
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
      callbackUrl: input.callbackUrl?.trim() || undefined,
      startFromStep: input.startFromStep ?? 2,
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
    workflow: run.workflow ?? "standard",
    callbackUrl: run.callbackUrl ?? null,
    targetUrls: Array.isArray(run.targetUrls) ? run.targetUrls : [],
    categories: Array.isArray(run.categories) ? run.categories : [],
    categoryContexts: Array.isArray(run.categoryContexts) ? run.categoryContexts : [],
    aiProvider: run.aiProvider ?? "openai",
    aiModel: run.aiModel ?? null,
    step2AiProvider: run.step2AiProvider ?? run.aiProvider ?? "openai",
    step2AiModel: run.step2AiModel ?? run.aiModel ?? null,
    step3AiProvider: run.step3AiProvider ?? run.aiProvider ?? "openai",
    step3AiModel: run.step3AiModel ?? run.aiModel ?? null,
    step2FormatterProvider: run.step2FormatterProvider ?? "gemini",
    step2FormatterModel: run.step2FormatterModel ?? "gemini-2.5-flash",
    step3FormatterProvider: run.step3FormatterProvider ?? "gemini",
    step3FormatterModel: run.step3FormatterModel ?? "gemini-2.5-flash",
    deepResearchResearchProvider: run.deepResearchResearchProvider ?? "perplexity",
    deepResearchResearchModel: run.deepResearchResearchModel ?? "sonar-deep-research",
    deepResearchReasoningProvider: run.deepResearchReasoningProvider ?? "openai",
    deepResearchReasoningModel: run.deepResearchReasoningModel ?? "gpt-5.5",
    deepResearchFormatterProvider: run.deepResearchFormatterProvider ?? "openai",
    deepResearchFormatterModel: run.deepResearchFormatterModel ?? "gpt-5.5",
    aiStepResponses: run.aiStepResponses ?? {},
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
