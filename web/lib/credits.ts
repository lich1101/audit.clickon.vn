import type { CreditBalanceResponse, CreditLog } from "@/types";
import type { CreditMutationValues } from "@/lib/validators";

async function parseApiResponse<T>(response: Response): Promise<T> {
  const payload = await response.json();

  if (!response.ok) {
    throw new Error(payload.message ?? "Request thất bại.");
  }

  return payload as T;
}

export async function addCredits(input: CreditMutationValues) {
  const response = await fetch("/api/credits/add", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify(input)
  });

  return parseApiResponse<{ message: string; log: CreditLog }>(response);
}

export async function subtractCredits(input: CreditMutationValues) {
  const response = await fetch("/api/credits/subtract", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify(input)
  });

  return parseApiResponse<{ message: string; log: CreditLog }>(response);
}

export async function getCreditBalance(userId: string) {
  const response = await fetch(`/api/credits/balance?userId=${encodeURIComponent(userId)}`, {
    method: "GET",
    cache: "no-store"
  });

  return parseApiResponse<CreditBalanceResponse>(response);
}
