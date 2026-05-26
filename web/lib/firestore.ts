"use client";

import {
  doc,
  onSnapshot,
  type DocumentData,
} from "firebase/firestore";

import { db } from "@/lib/firebase";
import { laravelRequest } from "@/lib/laravel";
import { parseArticleUrls, parseCategories, websiteSchema, type WebsiteValues } from "@/lib/validators";
import { getAuditRun, listAuditRunsByWebsite } from "@/lib/audit-runs";
import type { AiProvider, AppUser, AuditRun, AuditRunItem, CreditLog, Plan, Website, WebsiteAudit } from "@/types";

function serializeTimestamp(value: unknown) {
  if (!value) {
    return new Date().toISOString();
  }

  if (typeof value === "string") {
    return value;
  }

  if (typeof value === "object" && value !== null && "toDate" in value && typeof value.toDate === "function") {
    return value.toDate().toISOString();
  }

  return new Date(String(value)).toISOString();
}

export function mapUser(docId: string, data: DocumentData): AppUser {
  return {
    uid: docId,
    email: data.email ?? "",
    displayName: data.displayName ?? undefined,
    role: data.role === "admin" ? "admin" : "user",
    credits: Number(data.credits ?? 0),
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapPlan(docId: string, data: DocumentData): Plan {
  return {
    id: docId,
    name: data.name ?? "",
    price: Number(data.price ?? 0),
    credits: Number(data.credits ?? 0),
    isActive: Boolean(data.isActive),
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapWebsite(docId: string, data: DocumentData): Website {
  return {
    id: docId,
    userId: data.userId ?? "",
    name: data.name ?? "",
    url: data.url ?? "",
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapAudit(docId: string, data: DocumentData): WebsiteAudit {
  return {
    id: docId,
    websiteId: data.websiteId ?? "",
    userId: data.userId ?? "",
    articleUrls: Array.isArray(data.articleUrls) ? data.articleUrls : [],
    categories: Array.isArray(data.categories) ? data.categories : [],
    checklistText: data.checklistText ?? null,
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapCreditLog(docId: string, data: DocumentData): CreditLog {
  return {
    id: docId,
    userId: data.userId ?? "",
    type: data.type === "subtract" ? "subtract" : "add",
    amount: Number(data.amount ?? 0),
    balanceBefore: Number(data.balanceBefore ?? 0),
    balanceAfter: Number(data.balanceAfter ?? 0),
    reason: data.reason ?? "",
    source: ["admin", "api", "plan", "audit", "system"].includes(data.source) ? data.source : "system",
    createdAt: serializeTimestamp(data.createdAt)
  };
}

export function mapAuditRun(docId: string, data: DocumentData): AuditRun {
  return {
    publicId: data.publicId ?? docId,
    databaseId: typeof data.databaseId === "number" ? data.databaseId : undefined,
    websiteId: data.websiteId ?? "",
    websiteName: data.websiteName ?? null,
    websiteUrl: data.websiteUrl ?? null,
    workflow: ["standard", "audit_deep_research"].includes(data.workflow) ? data.workflow : "standard",
    callbackUrl: data.callbackUrl ?? null,
    userId: data.userId ?? "",
    userEmail: data.userEmail ?? null,
    targetUrls: Array.isArray(data.targetUrls) ? data.targetUrls : [],
    categories: Array.isArray(data.categories) ? data.categories : [],
    categoryContexts: Array.isArray(data.categoryContexts) ? data.categoryContexts : [],
    checklistText: data.checklistText ?? null,
    aiProvider: ["openai", "gemini", "gemini_deep_research"].includes(data.aiProvider) ? data.aiProvider : "openai",
    aiModel: data.aiModel ?? null,
    step2AiProvider: ["openai", "gemini", "gemini_deep_research"].includes(data.step2AiProvider) ? data.step2AiProvider : data.aiProvider ?? "openai",
    step2AiModel: data.step2AiModel ?? data.aiModel ?? null,
    step3AiProvider: ["openai", "gemini", "gemini_deep_research"].includes(data.step3AiProvider) ? data.step3AiProvider : data.aiProvider ?? "openai",
    step3AiModel: data.step3AiModel ?? data.aiModel ?? null,
    deepResearchResearchModel: data.deepResearchResearchModel ?? "sonar-deep-research",
    deepResearchReasoningModel: data.deepResearchReasoningModel ?? "gpt-5.5",
    deepResearchFormatterProvider: ["openai", "gemini"].includes(data.deepResearchFormatterProvider) ? data.deepResearchFormatterProvider : "openai",
    deepResearchFormatterModel: data.deepResearchFormatterModel ?? "gpt-5.5",
    status: ["queued", "processing", "completed", "partial", "failed"].includes(data.status) ? data.status : "queued",
    totalUrls: Number(data.totalUrls ?? 0),
    processedUrls: Number(data.processedUrls ?? 0),
    completedUrls: Number(data.completedUrls ?? 0),
    failedUrls: Number(data.failedUrls ?? 0),
    startedAt: data.startedAt ? serializeTimestamp(data.startedAt) : null,
    completedAt: data.completedAt ? serializeTimestamp(data.completedAt) : null,
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt),
    lastError: data.lastError ?? null
  };
}

export function mapAuditRunItem(docId: string, data: DocumentData): AuditRunItem {
  return {
    publicId: data.publicId ?? docId,
    auditRunId: data.auditRunId ?? "",
    websiteId: data.websiteId ?? "",
    userId: data.userId ?? "",
    position: Number(data.position ?? 0),
    targetUrl: data.targetUrl ?? "",
    status: ["queued", "fetching", "analyzing", "completed", "failed"].includes(data.status) ? data.status : "queued",
    extractionSource: data.extractionSource ?? null,
    pageTitle: data.pageTitle ?? null,
    metaDescription: data.metaDescription ?? null,
    canonicalUrl: data.canonicalUrl ?? null,
    headings: typeof data.headings === "object" && data.headings ? data.headings : {},
    metrics: typeof data.metrics === "object" && data.metrics ? data.metrics : {},
    primaryKeyword: data.primaryKeyword ?? null,
    categoryName: data.categoryName ?? null,
    categoryUrl: data.categoryUrl ?? null,
    categoryMatchReason: data.categoryMatchReason ?? null,
    auditScore: typeof data.auditScore === "number" ? data.auditScore : (data.auditScore ? Number(data.auditScore) : null),
    auditFindings: Array.isArray(data.auditFindings) ? data.auditFindings : [],
    auditRecommendations: Array.isArray(data.auditRecommendations) ? data.auditRecommendations : [],
    contentRevisionDirection: data.contentRevisionDirection ?? null,
    contentExcerpt: data.contentExcerpt ?? null,
    promptSnapshots: typeof data.promptSnapshots === "object" && data.promptSnapshots ? data.promptSnapshots : {},
    errorMessage: data.errorMessage ?? null,
    completedAt: data.completedAt ? serializeTimestamp(data.completedAt) : null,
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function listenToUser(uid: string, callback: (user: AppUser | null) => void, onError?: (error: Error) => void) {
  void laravelRequest<{ data: AppUser }>("/api/me", { method: "GET", cache: "no-store" })
    .then((payload) => callback(payload.data.uid === uid ? payload.data : null))
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải hồ sơ user.")));

  return () => undefined;
}

export async function fetchWebsites() {
  const payload = await laravelRequest<{ data: Website[] }>("/api/websites", { method: "GET", cache: "no-store" });
  return payload.data;
}

export async function fetchCreditLogs(userId: string, take = 20) {
  const payload = await laravelRequest<{ data: CreditLog[] }>(`/api/credit-transactions?userId=${encodeURIComponent(userId)}&limit=${take}`, {
    method: "GET",
    cache: "no-store"
  });
  return payload.data;
}

export function listenToWebsites(userId: string, callback: (websites: Website[]) => void, onError?: (error: Error) => void) {
  void fetchWebsites()
    .then((websites) => callback(websites))
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải danh sách website.")));

  return () => undefined;
}

export function listenToPlans(callback: (plans: Plan[]) => void, onError?: (error: Error) => void, activeOnly = true) {
  void fetchPlans(activeOnly)
    .then((plans) => callback(plans))
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải gói cước.")));

  return () => undefined;
}

export async function fetchPlans(activeOnly = true) {
  const payload = await laravelRequest<{ data: Plan[] }>(`/api/plans?activeOnly=${activeOnly ? "1" : "0"}`, {
    method: "GET",
    cache: "no-store"
  });
  return payload.data;
}

export function listenToAllUsers(callback: (users: AppUser[]) => void, onError?: (error: Error) => void) {
  void laravelRequest<{ data: AppUser[] }>("/api/admin/users", { method: "GET", cache: "no-store" })
    .then((payload) => callback(payload.data))
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải danh sách users.")));

  return () => undefined;
}

export function listenToAllPlans(callback: (plans: Plan[]) => void, onError?: (error: Error) => void) {
  void laravelRequest<{ data: Plan[] }>("/api/admin/plans?activeOnly=0", { method: "GET", cache: "no-store" })
    .then((payload) => callback(payload.data))
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải danh sách plans.")));

  return () => undefined;
}

export function listenToCreditLogs(
  userId: string,
  callback: (logs: CreditLog[]) => void,
  onError?: (error: Error) => void,
  take = 20
) {
  void fetchCreditLogs(userId, take)
    .then(callback)
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải credit logs.")));

  return () => undefined;
}

export function listenToAllCreditLogs(callback: (logs: CreditLog[]) => void, onError?: (error: Error) => void, take = 100) {
  void laravelRequest<{ data: CreditLog[] }>(`/api/admin/credit-transactions?limit=${take}`, { method: "GET", cache: "no-store" })
    .then((payload) => callback(payload.data))
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải credit logs.")));

  return () => undefined;
}

export function listenToAuditRunsByWebsite(
  websiteId: string,
  callback: (runs: AuditRun[]) => void,
  onError?: (error: Error) => void
) {
  void listAuditRunsByWebsite(websiteId)
    .then(callback)
    .catch((error) => onError?.(error instanceof Error ? error : new Error("Không thể tải audit runs.")));

  return () => undefined;
}

export function listenToAuditRunSignal(
  publicId: string,
  callback: (run: AuditRun | null) => void,
  onError?: (error: Error) => void
) {
  let disposed = false;
  let lastVersion: unknown = null;

  const reload = () => {
    void getAuditRun(publicId)
      .then((run) => {
        if (!disposed) {
          callback(run);
        }
      })
      .catch((error) => {
        if (!disposed) {
          onError?.(error instanceof Error ? error : new Error("Không thể tải audit run."));
        }
      });
  };

  reload();

  const unsubscribe = onSnapshot(
    doc(db, "auditRunSignals", publicId),
    (snapshot) => {
      const version = snapshot.exists() ? snapshot.data().version ?? snapshot.data().updatedAt : null;

      if (version === lastVersion) {
        return;
      }

      lastVersion = version;
      reload();
    },
    (error) => onError?.(error)
  );

  return () => {
    disposed = true;
    unsubscribe();
  };
}

export async function createWebsite(userId: string, values: WebsiteValues) {
  const parsed = websiteSchema.parse(values);
  const payload = await laravelRequest<{ data: { id: string } }>("/api/websites", {
    method: "POST",
    body: JSON.stringify({
      name: parsed.name,
      url: parsed.url
    })
  });

  return payload.data.id;
}

export async function getWebsiteById(id: string) {
  const payload = await laravelRequest<{ data: Website }>(`/api/websites/${id}`, { method: "GET", cache: "no-store" });
  return payload.data;
}

export async function getPlanById(id: string) {
  const payload = await laravelRequest<{ data: Plan }>(`/api/admin/plans/${id}`, { method: "GET", cache: "no-store" });
  return payload.data;
}

export async function saveWebsiteAudit(input: {
  auditId?: string;
  websiteId: string;
  userId: string;
  articleUrlsInput: string;
  categoriesInput: string;
  checklistText?: string;
}) {
  const payload = await laravelRequest<{ data: WebsiteAudit }>("/api/website-audits", {
    method: "POST",
    body: JSON.stringify({
      auditId: input.auditId,
      websiteId: input.websiteId,
      articleUrlsInput: input.articleUrlsInput,
      categoriesInput: input.categoriesInput,
      checklistText: input.checklistText ?? ""
    })
  });

  return payload.data;
}

export async function getAuditByWebsiteId(websiteId: string) {
  const payload = await laravelRequest<{ data: WebsiteAudit | null }>(`/api/websites/${websiteId}/audit`, {
    method: "GET",
    cache: "no-store"
  });
  return payload.data;
}

export async function updatePlan(id: string, data: Partial<Plan>) {
  const payload = await laravelRequest<{ data: Plan }>(`/api/admin/plans/${id}`, {
    method: "PUT",
    body: JSON.stringify(data)
  });
  return payload.data;
}

export async function createPlan(data: Pick<Plan, "name" | "price" | "credits" | "isActive">) {
  const payload = await laravelRequest<{ data: Plan }>("/api/admin/plans", {
    method: "POST",
    body: JSON.stringify(data)
  });
  return payload.data.id;
}
