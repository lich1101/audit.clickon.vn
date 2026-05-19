"use client";

import { useCallback, useEffect, useState } from "react";

import { useAuth } from "@/hooks/use-auth";

const STORAGE_KEY = "clickon_dashboard_mode";

export type DashboardMode = "user" | "admin";

export function useDashboardMode() {
  const { profile } = useAuth();
  const isAdmin = profile?.role === "admin";
  const [mode, setMode] = useState<DashboardMode>("user");

  useEffect(() => {
    if (!isAdmin) {
      setMode("user");
      return;
    }

    setMode(localStorage.getItem(STORAGE_KEY) === "admin" ? "admin" : "user");
  }, [isAdmin]);

  const setDashboardMode = useCallback(
    (nextMode: DashboardMode) => {
      if (!isAdmin) {
        setMode("user");
        return;
      }

      localStorage.setItem(STORAGE_KEY, nextMode);
      setMode(nextMode);
    },
    [isAdmin]
  );

  return {
    mode: isAdmin ? mode : "user",
    isAdmin,
    setDashboardMode
  };
}
