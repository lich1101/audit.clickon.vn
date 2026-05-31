"use client";

import { laravelRequest } from "@/lib/laravel";
import type { CaptchaSolveTask, KeywordRankBoard, KeywordRankKeyword, KeywordRankRun, KeywordRankRunItem } from "@/types";

export async function fetchKeywordRankBoard(websiteId: string) {
  const response = await laravelRequest<{ data: KeywordRankBoard }>(`/api/websites/${websiteId}/keyword-ranks`, {
    method: "GET",
    cache: "no-store",
  });

  return response.data;
}

export async function saveKeywordRankKeywords(websiteId: string, keywords: string[]) {
  const response = await laravelRequest<{ data: KeywordRankKeyword[] }>(`/api/websites/${websiteId}/keyword-ranks/keywords`, {
    method: "PUT",
    body: JSON.stringify({ keywords }),
  });

  return response.data;
}

export async function createKeywordRankRun(websiteId: string, input: { keywordIds: string[]; captchaEnabled: boolean }) {
  const response = await laravelRequest<{ data: KeywordRankRun; message?: string }>(`/api/websites/${websiteId}/keyword-rank-runs`, {
    method: "POST",
    body: JSON.stringify(input),
  });

  return response;
}

export async function recordKeywordRankRunItem(runPublicId: string, item: KeywordRankRunItem) {
  const response = await laravelRequest<{ data: KeywordRankRunItem }>(`/api/keyword-rank-runs/${runPublicId}/items`, {
    method: "POST",
    body: JSON.stringify(item),
  });

  return response.data;
}

export async function completeKeywordRankRun(runPublicId: string, input: { status?: KeywordRankRun["status"]; error?: string | null }) {
  const response = await laravelRequest<{ data: KeywordRankRun }>(`/api/keyword-rank-runs/${runPublicId}/complete`, {
    method: "POST",
    body: JSON.stringify(input),
  });

  return response.data;
}

export async function createCaptchaSolveTask(input: {
  runPublicId: string;
  websiteUrl: string;
  websiteKey: string;
  recaptchaDataSValue?: string | null;
  isInvisible?: boolean;
  userAgent?: string | null;
  cookies?: string | null;
}) {
  const response = await laravelRequest<{ data: CaptchaSolveTask }>("/api/keyword-rank-captcha-tasks", {
    method: "POST",
    body: JSON.stringify(input),
  });

  return response.data;
}

export async function pollCaptchaSolveTask(taskId: string) {
  const response = await laravelRequest<{ data: CaptchaSolveTask }>(`/api/keyword-rank-captcha-tasks/${taskId}`, {
    method: "GET",
    cache: "no-store",
  });

  return response.data;
}
