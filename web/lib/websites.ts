"use client";

import { laravelRequest } from "@/lib/laravel";

export async function deleteWebsite(websiteId: string) {
  const response = await laravelRequest<{ message: string; data: { id: string } }>(`/api/websites/${websiteId}`, {
    method: "DELETE"
  });

  return response;
}
