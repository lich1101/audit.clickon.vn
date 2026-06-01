"use client";

import { laravelRequest } from "@/lib/laravel";
import { IMPERSONATE_UID_COOKIE, readClientCookie, ROLE_COOKIE } from "@/lib/auth";
import type {
  AiUsageReconciliationBackfillResult,
  AiUsageReconciliationReport,
  AppUser,
  CreditLog,
  Plan,
  Product,
  ProductRequest,
  UserRole
} from "@/types";

export async function fetchMe(): Promise<AppUser> {
  const response = await laravelRequest<{ data: AppUser }>("/api/me", { method: "GET", cache: "no-store" });
  const realRole = (readClientCookie(ROLE_COOKIE) === "admin" ? "admin" : "user") as UserRole;
  const isImpersonating = Boolean(readClientCookie(IMPERSONATE_UID_COOKIE));

  return {
    ...response.data,
    realRole,
    isImpersonating,
  };
}

export async function updateMe(input: { displayName?: string | null }) {
  const response = await laravelRequest<{ data: AppUser }>("/api/me", {
    method: "PUT",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function fetchAdminUsers(search = "") {
  const query = search ? `?search=${encodeURIComponent(search)}` : "";
  const response = await laravelRequest<{ data: AppUser[] }>(`/api/admin/users${query}`, { method: "GET", cache: "no-store" });
  return response.data;
}

export async function fetchAdminUser(uid: string) {
  const response = await laravelRequest<{ data: AppUser }>(`/api/admin/users/${uid}`, { method: "GET", cache: "no-store" });
  return response.data;
}

export async function createAdminUser(input: {
  email: string;
  password: string;
  displayName?: string;
  role?: "user" | "admin";
  captchaCredits?: number;
  balanceUsd?: number;
}) {
  const response = await laravelRequest<{ data: AppUser }>(`/api/admin/users`, {
    method: "POST",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function updateAdminUser(uid: string, input: { email?: string; password?: string; displayName?: string; role?: "user" | "admin"; captchaCredits?: number }) {
  const response = await laravelRequest<{ data: AppUser }>(`/api/admin/users/${uid}`, {
    method: "PUT",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function deleteAdminUser(uid: string, input: { mode: "purge" | "transfer"; transferToUid?: string }) {
  const response = await laravelRequest<{ ok: boolean; message?: string }>(`/api/admin/users/${uid}`, {
    method: "DELETE",
    body: JSON.stringify(input)
  });
  return response;
}

export async function grantWebsiteSameDayReaudit(websiteId: string) {
  const response = await laravelRequest<{ data: { sameDayReauditGrantedUntil?: string | null; sameDayReauditGrantedBy?: string | null }; message?: string }>(
    `/api/admin/websites/${websiteId}/same-day-reaudit`,
    {
      method: "POST",
    }
  );

  return response;
}

export async function revokeWebsiteSameDayReaudit(websiteId: string) {
  const response = await laravelRequest<{ data: { sameDayReauditGrantedUntil?: string | null; sameDayReauditGrantedBy?: string | null }; message?: string }>(
    `/api/admin/websites/${websiteId}/same-day-reaudit`,
    {
      method: "DELETE",
    }
  );

  return response;
}

export async function fetchPlans(activeOnly = true) {
  const response = await laravelRequest<{ data: Plan[] }>(`/api/plans?activeOnly=${activeOnly ? "1" : "0"}`, {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}

export async function fetchPlan(id: string) {
  const response = await laravelRequest<{ data: Plan }>(`/api/admin/plans/${id}`, { method: "GET", cache: "no-store" });
  return response.data;
}

export async function fetchAdminPlans() {
  return fetchPlans(false);
}

export async function createPlan(input: Pick<Plan, "name" | "price" | "credits" | "captchaCredits" | "isActive">) {
  const response = await laravelRequest<{ data: Plan }>("/api/admin/plans", {
    method: "POST",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function updatePlan(id: string, input: Partial<Pick<Plan, "name" | "price" | "credits" | "captchaCredits" | "isActive">>) {
  const response = await laravelRequest<{ data: Plan }>(`/api/admin/plans/${id}`, {
    method: "PUT",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function fetchCreditTransactions(options?: { userId?: string; limit?: number }) {
  const params = new URLSearchParams();
  if (options?.userId) params.set("userId", options.userId);
  if (options?.limit) params.set("limit", String(options.limit));
  const suffix = params.toString() ? `?${params.toString()}` : "";
  const response = await laravelRequest<{ data: CreditLog[] }>(`/api/credit-transactions${suffix}`, {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}

export async function fetchAdminCreditTransactions(limit = 100) {
  const response = await laravelRequest<{ data: CreditLog[] }>(`/api/admin/credit-transactions?limit=${limit}`, {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}

export async function fetchAiUsageReconciliationReport(input?: {
  status?: "undercharged" | "overcharged" | "aligned" | "all";
  provider?: string;
  userUid?: string;
  runPublicId?: string;
  limit?: number;
}) {
  const params = new URLSearchParams();
  if (input?.status) params.set("status", input.status);
  if (input?.provider) params.set("provider", input.provider);
  if (input?.userUid) params.set("userUid", input.userUid);
  if (input?.runPublicId) params.set("runPublicId", input.runPublicId);
  if (input?.limit) params.set("limit", String(input.limit));
  const suffix = params.toString() ? `?${params.toString()}` : "";

  return laravelRequest<AiUsageReconciliationReport>(`/api/admin/ai-usage-reconciliation${suffix}`, {
    method: "GET",
    cache: "no-store"
  });
}

export async function backfillAiUsageReconciliation(input?: {
  provider?: string;
  userUid?: string;
  runPublicId?: string;
  runPublicIds?: string[];
  limit?: number;
}) {
  return laravelRequest<AiUsageReconciliationBackfillResult>("/api/admin/ai-usage-reconciliation/backfill", {
    method: "POST",
    body: JSON.stringify(input ?? {})
  });
}

export async function fetchProducts(activeOnly = true) {
  const response = await laravelRequest<{ data: Product[] }>(`/api/products?activeOnly=${activeOnly ? "1" : "0"}`, {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}

export async function fetchAdminProducts() {
  return fetchProducts(false);
}

export async function fetchProduct(id: string) {
  const response = await laravelRequest<{ data: Product }>(`/api/admin/products/${id}`, {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}

export async function createProduct(input: Pick<Product, "name" | "type" | "price" | "captchaCredits" | "balanceUsd" | "credits" | "isActive">) {
  const response = await laravelRequest<{ data: Product }>("/api/admin/products", {
    method: "POST",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function updateProduct(id: string, input: Partial<Pick<Product, "name" | "type" | "price" | "captchaCredits" | "balanceUsd" | "credits" | "isActive">>) {
  const response = await laravelRequest<{ data: Product }>(`/api/admin/products/${id}`, {
    method: "PUT",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function fetchProductRequests() {
  const response = await laravelRequest<{ data: ProductRequest[] }>("/api/product-requests", {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}

export async function createProductRequest(productId: string) {
  const response = await laravelRequest<{ data: ProductRequest; message?: string }>("/api/product-requests", {
    method: "POST",
    body: JSON.stringify({ productId })
  });
  return response;
}

export async function fetchAdminProductRequests() {
  const response = await laravelRequest<{ data: ProductRequest[] }>("/api/admin/product-requests", {
    method: "GET",
    cache: "no-store"
  });
  return response.data;
}
