"use client";

import { onAuthStateChanged, type User } from "firebase/auth";
import { useEffect, useState } from "react";

import { auth, isFirebaseConfigured } from "@/lib/firebase";
import { listenToUser } from "@/lib/firestore";
import { clearClientSession, syncClientSession } from "@/lib/session-client";
import { AuthContext } from "@/hooks/use-auth";
import type { AppUser } from "@/types";

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

    let unsubscribeProfile: (() => void) | undefined;
    let disposed = false;

    const clearProfileListener = () => {
      unsubscribeProfile?.();
      unsubscribeProfile = undefined;
    };

    const unsubscribeAuth = onAuthStateChanged(auth, async (nextUser) => {
      clearProfileListener();
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
        const token = await nextUser.getIdToken(true);
        const sessionProfile = await syncClientSession(token);
        setProfile(sessionProfile);
        setLoading(false);

        if (disposed) {
          return;
        }

        unsubscribeProfile = listenToUser(
          nextUser.uid,
          (nextProfile) => {
            if (disposed) {
              return;
            }

            setProfile(nextProfile);
            setError(nextProfile ? null : "Không tìm thấy hồ sơ người dùng.");
            setLoading(false);
          },
          (snapshotError) => {
            if (disposed) {
              return;
            }

            setProfile((current) => current ?? sessionProfile);
            setError(null);
            setLoading(false);
          }
        );
      } catch (sessionError) {
        if (disposed) {
          return;
        }

        setProfile(null);
        setError(sessionError instanceof Error ? sessionError.message : "Không thể đồng bộ phiên đăng nhập.");
        setLoading(false);
      }
    });

    return () => {
      disposed = true;
      unsubscribeAuth();
      clearProfileListener();
    };
  }, []);

  return <AuthContext.Provider value={{ firebaseUser, profile, loading, error }}>{children}</AuthContext.Provider>;
}
