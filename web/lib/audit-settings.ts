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
  minCreditsPerCall: number;
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
  deepResearchBatchSize: number;
  deepResearchResearchProvider: DeepResearchResearchProvider;
  deepResearchResearchModel: string | null;
  deepResearchReasoningProvider: DeepResearchReasoningProvider;
  deepResearchReasoningModel: string | null;
  deepResearchFormatterProvider: JsonFormatterProvider;
  deepResearchFormatterModel: string | null;
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
