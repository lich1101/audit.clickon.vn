"use client";

import { onAuthStateChanged, type User } from "firebase/auth";
import { useCallback, useEffect, useState } from "react";

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

  const refreshProfile = useCallback(async () => {
    if (!auth.currentUser) {
      setProfile(null);
      return null;
    }

    const nextProfile = await fetchMe();
    setProfile((current) => (isSameProfile(current, nextProfile) ? current : nextProfile));

    return nextProfile;
  }, []);

  useEffect(() => {
    if (!isFirebaseConfigured) {
      setError("Firebase chưa được cấu hình.");
      setLoading(false);
      return;
    }

    let disposed = false;

    const unsubscribeAuth = onAuthStateChanged(auth, async (nextUser) => {
      setFirebaseUser(nextUser);
      setError(null);

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
        setProfile((current) => (isSameProfile(current, sessionProfile) ? current : sessionProfile));
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
    };
  }, []);

  return <AuthContext.Provider value={{ firebaseUser, profile, loading, error, refreshProfile }}>{children}</AuthContext.Provider>;
}
