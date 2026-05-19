"use client";

import { laravelRequest } from "@/lib/laravel";
import type { AiProvider } from "@/types";

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
  maxParallelItems: number;
  modelPricing?: ModelPricingRow[];
};

export type PublicAuditSettings = {
  aiProvider: AiProvider;
  aiModel: string | null;
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
