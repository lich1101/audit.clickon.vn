import { NextResponse } from "next/server";

import { getVerifiedSession } from "@/lib/server-session";
import { creditMutationSchema } from "@/lib/validators";

async function proxyToLaravel(path: string, body: unknown) {
  const baseUrl = process.env.LARAVEL_API_URL;
  const apiKey = process.env.LARAVEL_INTERNAL_API_KEY;

  if (!baseUrl || !apiKey) {
    throw new Error("Laravel API env chưa được cấu hình đầy đủ.");
  }

  const response = await fetch(`${baseUrl}${path}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-Api-Key": apiKey
    },
    body: JSON.stringify(body),
    cache: "no-store"
  });

  const payload = await response.json();

  if (!response.ok) {
    return NextResponse.json(payload, { status: response.status });
  }

  return NextResponse.json(payload);
}

export async function POST(request: Request) {
  try {
    const session = await getVerifiedSession();

    if (!session || session.role !== "admin") {
      return NextResponse.json({ message: "Forbidden." }, { status: 403 });
    }

    const body = creditMutationSchema.parse(await request.json());
    return proxyToLaravel("/api/credits/subtract", body);
  } catch (error) {
    return NextResponse.json({ message: error instanceof Error ? error.message : "Unable to subtract credits." }, { status: 400 });
  }
}
