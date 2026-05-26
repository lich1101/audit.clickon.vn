"use client";

import { laravelRequest } from "@/lib/laravel";
import type { AiProvider, JsonFormatterProvider } from "@/types";

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
  maxParallelItems: number;
  step2BatchSize: number;
  step3BatchSize: number;
  deepResearchBatchSize: number;
  deepResearchResearchModel: string | null;
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
  maxParallelItems?: number;
  step2BatchSize?: number;
  step3BatchSize?: number;
  deepResearchBatchSize?: number;
  deepResearchResearchModel?: string | null;
  deepResearchReasoningModel?: string | null;
  deepResearchFormatterProvider?: JsonFormatterProvider;
  deepResearchFormatterModel?: string | null;
  minCreditsPerAiCall?: number;
  minCreditsPerRun?: number;
  minCreditsPerUrl?: number;
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
