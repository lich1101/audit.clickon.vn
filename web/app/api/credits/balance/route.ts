import { NextResponse } from "next/server";
import { z } from "zod";

import { getVerifiedSession } from "@/lib/server-session";

const querySchema = z.object({
  userId: z.string().trim().min(1)
});

export async function GET(request: Request) {
  try {
    const session = await getVerifiedSession();

    if (!session) {
      return NextResponse.json({ message: "Unauthenticated." }, { status: 401 });
    }

    const query = querySchema.parse(Object.fromEntries(new URL(request.url).searchParams.entries()));

    if (session.role !== "admin" && session.uid !== query.userId) {
      return NextResponse.json({ message: "Forbidden." }, { status: 403 });
    }

    const baseUrl = process.env.LARAVEL_API_URL;
    const apiKey = process.env.LARAVEL_INTERNAL_API_KEY;

    if (!baseUrl || !apiKey) {
      throw new Error("Laravel API env chưa được cấu hình đầy đủ.");
    }

    const response = await fetch(`${baseUrl}/api/credits/balance?userId=${encodeURIComponent(query.userId)}`, {
      headers: {
        "X-Api-Key": apiKey
      },
      cache: "no-store"
    });
    const payload = await response.json();

    if (!response.ok) {
      return NextResponse.json(payload, { status: response.status });
    }

    return NextResponse.json(payload);
  } catch (error) {
    return NextResponse.json({ message: error instanceof Error ? error.message : "Unable to load balance." }, { status: 400 });
  }
}
