"use client";

import { onAuthStateChanged, type User } from "firebase/auth";
import { useEffect, useState } from "react";

import { auth, isFirebaseConfigured } from "@/lib/firebase";
import { fetchMe } from "@/lib/account";
import { clearClientSession, syncClientSession } from "@/lib/session-client";
import { AuthContext } from "@/hooks/use-auth";
import type { AppUser } from "@/types";

function isSameProfile(current: AppUser | null, next: AppUser): boolean {
  if (!current) {
    return false;
  }

  return (
    current.uid === next.uid &&
    current.email === next.email &&
    current.displayName === next.displayName &&
    current.role === next.role &&
    current.credits === next.credits &&
    current.updatedAt === next.updatedAt
  );
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [firebaseUser, setFirebaseUser] = useState<User | null>(null);
  const [profile, setProfile] = useState<AppUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isFirebaseConfigured) {
      setError("Firebase chưa được cấu hình.");
      setLoading(false);
      return;
    }

    let refreshTimer: number | undefined;
    let disposed = false;

    async function refreshProfile() {
      try {
        const nextProfile = await fetchMe();
        if (!disposed) {
          setProfile((current) => (isSameProfile(current, nextProfile) ? current : nextProfile));
          setError(null);
        }
      } catch (profileError) {
        if (!disposed) {
          setError(profileError instanceof Error ? profileError.message : "Không thể tải hồ sơ người dùng.");
        }
      }
    }

    const unsubscribeAuth = onAuthStateChanged(auth, async (nextUser) => {
      setFirebaseUser(nextUser);
      setError(null);

      if (refreshTimer) {
        window.clearInterval(refreshTimer);
        refreshTimer = undefined;
      }

      if (!nextUser) {
        setProfile(null);
        await clearClientSession().catch(() => undefined);
        setLoading(false);
        return;
      }

      try {
        setLoading(true);
        const token = await nextUser.getIdToken();
        const sessionProfile = await syncClientSession(token);
        setProfile(sessionProfile);
        await refreshProfile();
        refreshTimer = window.setInterval(() => {
          void refreshProfile();
        }, 15000);
      } catch (sessionError) {
        setProfile(null);
        setError(sessionError instanceof Error ? sessionError.message : "Không thể đồng bộ phiên đăng nhập.");
      } finally {
        if (!disposed) {
          setLoading(false);
        }
      }
    });

    return () => {
      disposed = true;
      unsubscribeAuth();
      if (refreshTimer) {
        window.clearInterval(refreshTimer);
      }
    };
  }, []);

  return <AuthContext.Provider value={{ firebaseUser, profile, loading, error }}>{children}</AuthContext.Provider>;
}
