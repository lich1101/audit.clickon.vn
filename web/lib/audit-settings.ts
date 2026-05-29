"use client";

import { laravelRequest } from "@/lib/laravel";
import type {
  AiProvider,
  AuditWorkflow,
  DeepResearchReasoningProvider,
  DeepResearchResearchProvider,
  JsonFormatterProvider
} from "@/types";

export type ModelPricingRow = {
  provider: AiProvider;
  model: string;
  label: string;
  creditsPer1kInput: number;
  creditsPer1kOutput: number;
  usdPer1MInput?: number | null;
  usdPer1MOutput?: number | null;
  usdPer1MReasoning?: number | null;
  usdPer1MCitation?: number | null;
  usdPer1kSearchQueries?: number | null;
  minCreditsPerCall: number;
};

export type GeminiPdfAttachment = {
  slot: string;
  path: string;
  originalName: string;
  bytes: number;
  uploadedAt: string;
  geminiFileUri?: string | null;
  geminiFileName?: string | null;
};

export type AuditSystemSettings = {
  aiProvider: AiProvider;
  aiModel: string | null;
  step2AiProvider: AiProvider;
  step2AiModel: string | null;
  step3AiProvider: AiProvider;
  step3AiModel: string | null;
  step2FormatterProvider: JsonFormatterProvider;
  step2FormatterModel: string | null;
  step3FormatterProvider: JsonFormatterProvider;
  step3FormatterModel: string | null;
  step3FlowMode: AuditWorkflow;
  maxParallelItems: number;
  step2BatchSize: number;
  step3BatchSize: number;
  minValidUrlsAfterStep1: number;
  deepResearchBatchSize: number;
  deepResearchResearchProvider: DeepResearchResearchProvider;
  deepResearchResearchModel: string | null;
  deepResearchReasoningProvider: DeepResearchReasoningProvider;
  deepResearchReasoningModel: string | null;
  deepResearchFormatterProvider: JsonFormatterProvider;
  deepResearchFormatterModel: string | null;
  geminiPdfAttachments?: Record<string, GeminiPdfAttachment>;
  modelPricing?: ModelPricingRow[];
};

export type PublicAuditSettings = {
  aiProvider: AiProvider;
  aiModel: string | null;
  step2AiProvider?: AiProvider;
  step2AiModel?: string | null;
  step3AiProvider?: AiProvider;
  step3AiModel?: string | null;
  step2FormatterProvider?: JsonFormatterProvider;
  step2FormatterModel?: string | null;
  step3FormatterProvider?: JsonFormatterProvider;
  step3FormatterModel?: string | null;
  step3FlowMode?: AuditWorkflow;
  maxParallelItems?: number;
  step2BatchSize?: number;
  step3BatchSize?: number;
  deepResearchBatchSize?: number;
  deepResearchResearchProvider?: DeepResearchResearchProvider;
  deepResearchResearchModel?: string | null;
  deepResearchReasoningProvider?: DeepResearchReasoningProvider;
  deepResearchReasoningModel?: string | null;
  deepResearchFormatterProvider?: JsonFormatterProvider;
  deepResearchFormatterModel?: string | null;
  minCreditsPerAiCall?: number;
  minCreditsPerRun?: number;
  minCreditsPerUrl?: number;
};

export type AuditConfigurationCheckItem = {
  status: "ok" | "warning" | "error";
  label: string;
  message: string;
};

export type AuditConfigurationCheckGroup = {
  id: string;
  title: string;
  status: "ok" | "warning" | "error";
  items: AuditConfigurationCheckItem[];
};

export type AuditConfigurationCheckReport = {
  ready: boolean;
  checkedAt: string;
  step3FlowMode: AuditWorkflow;
  summary: {
    ok: number;
    warning: number;
    error: number;
  };
  groups: AuditConfigurationCheckGroup[];
};

export async function fetchPublicAuditSettings() {
  const response = await laravelRequest<{ data: PublicAuditSettings }>("/api/audit-settings", {
    method: "GET",
    cache: "no-store"
  });

  return response.data;
}

export async function fetchAdminAuditSettings() {
  const response = await laravelRequest<{ data: AuditSystemSettings }>("/api/admin/audit-settings", {
    method: "GET",
    cache: "no-store"
  });

  return response.data;
}

export async function updateAdminAuditSettings(input: AuditSystemSettings) {
  const response = await laravelRequest<{ data: AuditSystemSettings }>("/api/admin/audit-settings", {
    method: "PUT",
    body: JSON.stringify(input)
  });

  return response.data;
}

export async function checkAdminAuditSettingsConfiguration(input?: AuditSystemSettings) {
  const response = await laravelRequest<{ data: AuditConfigurationCheckReport }>("/api/admin/audit-settings/check", {
    method: input ? "POST" : "GET",
    cache: "no-store",
    body: input ? JSON.stringify(input) : undefined
  });

  return response.data;
}

export async function uploadAdminGeminiPdf(slot: string, file: File) {
  const baseUrl = process.env.NEXT_PUBLIC_LARAVEL_API_URL;

  if (!baseUrl) {
    throw new Error("NEXT_PUBLIC_LARAVEL_API_URL chưa được cấu hình.");
  }

  const { auth } = await import("@/lib/firebase");
  const user = auth.currentUser;
  const token = user ? await user.getIdToken().catch(() => undefined) : undefined;
  const form = new FormData();
  form.append("pdf", file);

  const response = await fetch(`${baseUrl}/api/admin/audit-settings/gemini-pdf/${slot}`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {})
    },
    body: form
  });

  const raw = await response.text();
  const trimmed = raw.trim();
  let payload: unknown = null;

  if (trimmed) {
    payload = JSON.parse(trimmed);
  }

  if (!response.ok) {
    const record = payload && typeof payload === "object" ? (payload as Record<string, unknown>) : null;
    const message = typeof record?.message === "string" ? record.message : `Upload PDF failed (${response.status}).`;
    throw new Error(message);
  }

  return (payload as { data: GeminiPdfAttachment }).data;
}

export async function deleteAdminGeminiPdf(slot: string) {
  const response = await laravelRequest<{ data: { slot: string; deleted: boolean } }>(
    `/api/admin/audit-settings/gemini-pdf/${slot}`,
    {
      method: "DELETE"
    }
  );

  return response.data;
}
