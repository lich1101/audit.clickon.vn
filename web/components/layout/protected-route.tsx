"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { signOut } from "firebase/auth";

import { LoadingState } from "@/components/dashboard/loading-state";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/hooks/use-auth";
import { auth } from "@/lib/firebase";

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { firebaseUser, profile, loading, error } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !firebaseUser) {
      router.replace(`/login?redirect=${encodeURIComponent(pathname)}`);
    }
  }, [firebaseUser, loading, pathname, router]);

  if (loading || !firebaseUser || (!profile && !error)) {
    return <LoadingState title="Đang xác thực..." description="Đang kiểm tra phiên đăng nhập của bạn." />;
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
