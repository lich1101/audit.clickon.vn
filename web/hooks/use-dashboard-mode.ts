"use client";

import { usePathname } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { useAuth } from "@/hooks/use-auth";

const STORAGE_KEY = "clickon_dashboard_mode";

export type DashboardMode = "user" | "admin";

function isExplicitAdminPath(pathname: string): boolean {
  return pathname.startsWith("/admin");
}

function isExplicitUserPath(pathname: string): boolean {
  return pathname.startsWith("/dashboard")
    || pathname.startsWith("/websites")
    || pathname.startsWith("/billing")
    || pathname.startsWith("/credit-history")
    || pathname.startsWith("/settings");
}

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

    if (isExplicitAdminPath(pathname)) {
      setMode("admin");
      return;
    }

    if (isExplicitUserPath(pathname)) {
      setMode("user");
      return;
    }

    const stored = localStorage.getItem(STORAGE_KEY);

    if (stored === "admin" || stored === "user") {
      setMode(stored);
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
