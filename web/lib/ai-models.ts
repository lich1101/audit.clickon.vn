"use client";

import { laravelRequest } from "@/lib/laravel";
import type { AiProvider } from "@/types";

export type AiModelOption = {
  id: string;
  label: string;
  default?: boolean;
};

export type AiModelCatalog = {
  provider: AiProvider;
  defaultModel: string;
  models: AiModelOption[];
  source: "api" | "fallback" | "config";
};

const cache = new Map<AiProvider, AiModelCatalog>();

export async function fetchAiModels(provider: AiProvider, force = false) {
  if (!force && cache.has(provider)) {
    return cache.get(provider)!;
  }

  const response = await laravelRequest<{ data: AiModelCatalog }>(`/api/admin/ai-models/${provider}`, {
    method: "GET",
    cache: "no-store"
  });

  cache.set(provider, response.data);

  return response.data;
}

export function resolveAiModelValue(catalog: AiModelCatalog | null, value?: string | null) {
  if (value && value.trim() !== "") {
    return value;
  }

  return catalog?.defaultModel ?? "";
}
