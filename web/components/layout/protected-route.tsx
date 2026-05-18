"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";

import { LoadingState } from "@/components/dashboard/loading-state";
import { useAuth } from "@/hooks/use-auth";

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { firebaseUser, loading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !firebaseUser) {
      router.replace(`/login?redirect=${encodeURIComponent(pathname)}`);
    }
  }, [firebaseUser, loading, pathname, router]);

  if (loading || !firebaseUser) {
    return <LoadingState title="Đang xác thực..." description="Đang kiểm tra phiên đăng nhập của bạn." />;
  }

  return <>{children}</>;
}
