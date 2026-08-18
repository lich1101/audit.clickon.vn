"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { signOut } from "firebase/auth";

import { LoadingState } from "@/components/dashboard/loading-state";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/hooks/use-auth";
import { auth } from "@/lib/firebase";
import { getLocalAuthToken, isLocalAuthEnabled } from "@/lib/local-auth";
import { clearClientSession } from "@/lib/session-client";

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { firebaseUser, profile, loading, error } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const isAuthenticated = isLocalAuthEnabled() ? Boolean(getLocalAuthToken()) : Boolean(firebaseUser);

  useEffect(() => {
    if (!loading && !isAuthenticated) {
      router.replace(`/login?redirect=${encodeURIComponent(pathname)}`);
    }
  }, [isAuthenticated, loading, pathname, router]);

  if (loading || (isAuthenticated && !profile && !error)) {
    return <LoadingState title="Đang xác thực..." description="Đang kiểm tra phiên đăng nhập của bạn." />;
  }

  if (!isAuthenticated) {
    return <LoadingState title="Đang chuyển hướng..." description="Chuyển về trang đăng nhập." />;
  }

  if (!profile) {
    return (
      <div className="flex min-h-screen items-center justify-center p-6">
        <div className="premium-surface max-w-md space-y-4 p-8 text-center">
          <div className="space-y-2">
            <h1 className="text-xl font-semibold">Không thể tải phiên đăng nhập</h1>
            <p className="text-sm text-muted-foreground">{error ?? "Hồ sơ người dùng chưa sẵn sàng."}</p>
          </div>
          <Button
            onClick={() => {
              if (isLocalAuthEnabled()) {
                void clearClientSession().then(() => router.replace("/login"));
                return;
              }

              void signOut(auth);
              router.replace("/login");
            }}
          >
            Đăng nhập lại
          </Button>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
