"use client";

import { onAuthStateChanged, type User } from "firebase/auth";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { auth, isFirebaseConfigured } from "@/lib/firebase";
import { createOrUpdateUserProfile, listenToUser } from "@/lib/firestore";
import { AuthContext } from "@/hooks/use-auth";
import type { AppUser } from "@/types";

async function syncSession(idToken: string) {
  await fetch("/api/auth/session", {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({ idToken })
  });
}

async function clearSession() {
  await fetch("/api/auth/logout", {
    method: "POST"
  });
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [firebaseUser, setFirebaseUser] = useState<User | null>(null);
  const [profile, setProfile] = useState<AppUser | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!isFirebaseConfigured) {
      setLoading(false);
      return;
    }

    let unsubscribeProfile: (() => void) | undefined;

    const unsubscribeAuth = onAuthStateChanged(auth, async (nextUser) => {
      setFirebaseUser(nextUser);

      if (!nextUser) {
        setProfile(null);
        await clearSession().catch(() => undefined);
        setLoading(false);
        return;
      }

      try {
        await createOrUpdateUserProfile({
          uid: nextUser.uid,
          email: nextUser.email ?? "",
          displayName: nextUser.displayName ?? undefined
        });
        const token = await nextUser.getIdToken();
        await syncSession(token);

        unsubscribeProfile = listenToUser(
          nextUser.uid,
          (nextProfile) => {
            setProfile(nextProfile);
            setLoading(false);
          },
          (error) => {
            toast.error(error.message);
            setLoading(false);
          }
        );
      } catch (error) {
        setLoading(false);
        toast.error(error instanceof Error ? error.message : "Không thể đồng bộ phiên đăng nhập.");
      }
    });

    return () => {
      unsubscribeAuth();
      unsubscribeProfile?.();
    };
  }, []);

  return <AuthContext.Provider value={{ firebaseUser, profile, loading }}>{children}</AuthContext.Provider>;
}
