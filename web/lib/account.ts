"use client";

import { laravelRequest } from "@/lib/laravel";
import type { AppUser, CreditLog, Plan } from "@/types";

export async function fetchMe() {
  const response = await laravelRequest<{ data: AppUser }>("/api/me", { method: "GET", cache: "no-store" });
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

export async function updateAdminUser(uid: string, input: { displayName?: string; role?: "user" | "admin" }) {
  const response = await laravelRequest<{ data: AppUser }>(`/api/admin/users/${uid}`, {
    method: "PUT",
    body: JSON.stringify(input)
  });
  return response.data;
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

export async function createPlan(input: Pick<Plan, "name" | "price" | "credits" | "isActive">) {
  const response = await laravelRequest<{ data: Plan }>("/api/admin/plans", {
    method: "POST",
    body: JSON.stringify(input)
  });
  return response.data;
}

export async function updatePlan(id: string, input: Partial<Pick<Plan, "name" | "price" | "credits" | "isActive">>) {
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
