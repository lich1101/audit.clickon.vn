import type { CreditBalanceResponse, CreditLog } from "@/types";
import type { CreditMutationValues } from "@/lib/validators";
import { laravelRequest } from "@/lib/laravel";

export async function addCredits(input: CreditMutationValues) {
  return laravelRequest<{ message: string; log: CreditLog }>("/api/credits/add", {
    method: "POST",
    body: JSON.stringify(input)
  });
}

export async function subtractCredits(input: CreditMutationValues) {
  return laravelRequest<{ message: string; log: CreditLog }>("/api/credits/subtract", {
    method: "POST",
    body: JSON.stringify(input)
  });
}

export async function getCreditBalance(userId: string) {
  return laravelRequest<CreditBalanceResponse>(`/api/credits/balance?userId=${encodeURIComponent(userId)}`, {
    method: "GET",
    cache: "no-store"
  });
}
