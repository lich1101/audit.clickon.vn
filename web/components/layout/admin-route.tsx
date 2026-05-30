"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

import { LoadingState } from "@/components/dashboard/loading-state";
import { useAuth } from "@/hooks/use-auth";

export function AdminRoute({ children }: { children: React.ReactNode }) {
  const { profile, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!loading && (profile?.realRole !== "admin" || profile?.isImpersonating)) {
      router.replace("/dashboard");
    }
  }, [loading, profile, router]);

  if (loading || !profile) {
    return <LoadingState title="Đang tải quyền truy cập..." description="Đang xác nhận vai trò quản trị." />;
  }

  if (profile.realRole !== "admin" || profile.isImpersonating) {
    return null;
  }

  return <>{children}</>;
}
