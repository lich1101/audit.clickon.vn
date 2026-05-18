import { getApps, initializeApp, cert, applicationDefault } from "firebase-admin/app";
import { getAuth } from "firebase-admin/auth";
import { getFirestore } from "firebase-admin/firestore";

function getFirebaseAdminConfig() {
  const projectId = process.env.FIREBASE_PROJECT_ID;
  const clientEmail = process.env.FIREBASE_CLIENT_EMAIL;
  const privateKey = process.env.FIREBASE_PRIVATE_KEY?.replace(/\\n/g, "\n");

  if (projectId && clientEmail && privateKey) {
    return cert({
      projectId,
      clientEmail,
      privateKey
    });
  }

  return applicationDefault();
}

const app = getApps().length
  ? getApps()[0]
  : initializeApp({
      credential: getFirebaseAdminConfig(),
      projectId: process.env.FIREBASE_PROJECT_ID
    });

export const adminAuth = getAuth(app);
export const adminDb = getFirestore(app);
