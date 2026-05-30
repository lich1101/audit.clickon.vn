"use client";

import { usePathname } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { useAuth } from "@/hooks/use-auth";

const STORAGE_KEY = "clickon_dashboard_mode";

export type DashboardMode = "user" | "admin";

export function useDashboardMode() {
  const { profile } = useAuth();
  const pathname = usePathname();
  const isAdmin = profile?.realRole === "admin";
  const isImpersonating = profile?.isImpersonating === true;
  const [mode, setMode] = useState<DashboardMode>("user");

  useEffect(() => {
    if (!isAdmin || isImpersonating) {
      setMode("user");
      return;
    }

    if (pathname.startsWith("/admin")) {
      setMode("admin");
      return;
    }

    setMode("user");
  }, [isAdmin, isImpersonating, pathname]);

  const setDashboardMode = useCallback(
    (nextMode: DashboardMode) => {
      if (!isAdmin || isImpersonating) {
        setMode("user");
        return;
      }

      localStorage.setItem(STORAGE_KEY, nextMode);
      setMode(nextMode);
    },
    [isAdmin, isImpersonating]
  );

  return {
    mode: isAdmin && !isImpersonating ? mode : "user",
    isAdmin,
    isImpersonating,
    setDashboardMode
  };
}
