import { existsSync, readFileSync } from "node:fs";

import { getApps, initializeApp, cert, type App, type ServiceAccount } from "firebase-admin/app";
import { getAuth } from "firebase-admin/auth";
import { getFirestore } from "firebase-admin/firestore";

let cachedApp: App | null = null;

function normalizePrivateKey(value: string | undefined) {
  return value?.replace(/\\n/g, "\n");
}

function readServiceAccountFile() {
  const credentialsPath = process.env.FIREBASE_CREDENTIALS || process.env.GOOGLE_APPLICATION_CREDENTIALS;

  if (!credentialsPath) {
    return null;
  }

  if (!existsSync(credentialsPath)) {
    throw new Error(`Firebase credentials file not found at ${credentialsPath}.`);
  }

  const raw = JSON.parse(readFileSync(credentialsPath, "utf8")) as {
    projectId?: string;
    project_id?: string;
    clientEmail?: string;
    client_email?: string;
    privateKey?: string;
    private_key?: string;
  };

  return {
    projectId: raw.projectId ?? raw.project_id,
    clientEmail: raw.clientEmail ?? raw.client_email,
    privateKey: normalizePrivateKey(raw.privateKey ?? raw.private_key)
  };
}

function resolveServiceAccount(): ServiceAccount {
  const envAccount = {
    projectId: process.env.FIREBASE_PROJECT_ID,
    clientEmail: process.env.FIREBASE_CLIENT_EMAIL,
    privateKey: normalizePrivateKey(process.env.FIREBASE_PRIVATE_KEY)
  };

  if (envAccount.projectId && envAccount.clientEmail && envAccount.privateKey) {
    return envAccount;
  }

  const fileAccount = readServiceAccountFile();

  if (fileAccount?.projectId && fileAccount.clientEmail && fileAccount.privateKey) {
    return fileAccount;
  }

  throw new Error(
    "Firebase Admin is not configured. Set FIREBASE_PROJECT_ID/FIREBASE_CLIENT_EMAIL/FIREBASE_PRIVATE_KEY or mount FIREBASE_CREDENTIALS."
  );
}

export function getFirebaseAdminApp() {
  if (cachedApp) {
    return cachedApp;
  }

  cachedApp = getApps()[0] ??
    initializeApp({
      credential: cert(resolveServiceAccount()),
      projectId: process.env.FIREBASE_PROJECT_ID
    });

  return cachedApp;
}

export function getAdminAuth() {
  return getAuth(getFirebaseAdminApp());
}

export function getAdminDb() {
  return getFirestore(getFirebaseAdminApp());
}
