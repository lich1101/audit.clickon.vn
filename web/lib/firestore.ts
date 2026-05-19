"use client";

import {
  collection,
  doc,
  getDoc,
  getDocs,
  limit,
  onSnapshot,
  orderBy,
  query,
  serverTimestamp,
  setDoc,
  updateDoc,
  where,
  type DocumentData,
  type QueryConstraint
} from "firebase/firestore";

import { db } from "@/lib/firebase";
import { parseArticleUrls, parseCategories, websiteSchema, type WebsiteValues } from "@/lib/validators";
import type { AppUser, AuditRun, AuditRunItem, CreditLog, Plan, Website, WebsiteAudit } from "@/types";

function serializeTimestamp(value: unknown) {
  if (!value) {
    return new Date().toISOString();
  }

  if (typeof value === "string") {
    return value;
  }

  if (typeof value === "object" && value !== null && "toDate" in value && typeof value.toDate === "function") {
    return value.toDate().toISOString();
  }

  return new Date(String(value)).toISOString();
}

export function mapUser(docId: string, data: DocumentData): AppUser {
  return {
    uid: docId,
    email: data.email ?? "",
    displayName: data.displayName ?? undefined,
    role: data.role === "admin" ? "admin" : "user",
    credits: Number(data.credits ?? 0),
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapPlan(docId: string, data: DocumentData): Plan {
  return {
    id: docId,
    name: data.name ?? "",
    price: Number(data.price ?? 0),
    credits: Number(data.credits ?? 0),
    isActive: Boolean(data.isActive),
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapWebsite(docId: string, data: DocumentData): Website {
  return {
    id: docId,
    userId: data.userId ?? "",
    name: data.name ?? "",
    url: data.url ?? "",
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapAudit(docId: string, data: DocumentData): WebsiteAudit {
  return {
    id: docId,
    websiteId: data.websiteId ?? "",
    userId: data.userId ?? "",
    articleUrls: Array.isArray(data.articleUrls) ? data.articleUrls : [],
    categories: Array.isArray(data.categories) ? data.categories : [],
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

export function mapCreditLog(docId: string, data: DocumentData): CreditLog {
  return {
    id: docId,
    userId: data.userId ?? "",
    type: data.type === "subtract" ? "subtract" : "add",
    amount: Number(data.amount ?? 0),
    balanceBefore: Number(data.balanceBefore ?? 0),
    balanceAfter: Number(data.balanceAfter ?? 0),
    reason: data.reason ?? "",
    source: ["admin", "api", "plan", "system"].includes(data.source) ? data.source : "system",
    createdAt: serializeTimestamp(data.createdAt)
  };
}

export function mapAuditRun(docId: string, data: DocumentData): AuditRun {
  return {
    publicId: data.publicId ?? docId,
    databaseId: typeof data.databaseId === "number" ? data.databaseId : undefined,
    websiteId: data.websiteId ?? "",
    websiteName: data.websiteName ?? null,
    websiteUrl: data.websiteUrl ?? null,
    userId: data.userId ?? "",
    userEmail: data.userEmail ?? null,
    targetUrls: Array.isArray(data.targetUrls) ? data.targetUrls : [],
    categories: Array.isArray(data.categories) ? data.categories : [],
    categoryContexts: Array.isArray(data.categoryContexts) ? data.categoryContexts : [],
    checklistText: data.checklistText ?? null,
    aiProvider: ["openai", "gemini", "gemini_deep_research"].includes(data.aiProvider) ? data.aiProvider : "openai",
    aiModel: data.aiModel ?? null,
    status: ["queued", "processing", "completed", "partial", "failed"].includes(data.status) ? data.status : "queued",
    totalUrls: Number(data.totalUrls ?? 0),
    processedUrls: Number(data.processedUrls ?? 0),
    completedUrls: Number(data.completedUrls ?? 0),
    failedUrls: Number(data.failedUrls ?? 0),
    startedAt: data.startedAt ? serializeTimestamp(data.startedAt) : null,
    completedAt: data.completedAt ? serializeTimestamp(data.completedAt) : null,
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt),
    lastError: data.lastError ?? null
  };
}

export function mapAuditRunItem(docId: string, data: DocumentData): AuditRunItem {
  return {
    publicId: data.publicId ?? docId,
    auditRunId: data.auditRunId ?? "",
    websiteId: data.websiteId ?? "",
    userId: data.userId ?? "",
    position: Number(data.position ?? 0),
    targetUrl: data.targetUrl ?? "",
    status: ["queued", "fetching", "analyzing", "completed", "failed"].includes(data.status) ? data.status : "queued",
    extractionSource: data.extractionSource ?? null,
    pageTitle: data.pageTitle ?? null,
    metaDescription: data.metaDescription ?? null,
    canonicalUrl: data.canonicalUrl ?? null,
    headings: typeof data.headings === "object" && data.headings ? data.headings : {},
    metrics: typeof data.metrics === "object" && data.metrics ? data.metrics : {},
    primaryKeyword: data.primaryKeyword ?? null,
    categoryName: data.categoryName ?? null,
    categoryUrl: data.categoryUrl ?? null,
    categoryMatchReason: data.categoryMatchReason ?? null,
    auditScore: typeof data.auditScore === "number" ? data.auditScore : (data.auditScore ? Number(data.auditScore) : null),
    auditFindings: Array.isArray(data.auditFindings) ? data.auditFindings : [],
    auditRecommendations: Array.isArray(data.auditRecommendations) ? data.auditRecommendations : [],
    contentRevisionDirection: data.contentRevisionDirection ?? null,
    contentExcerpt: data.contentExcerpt ?? null,
    promptSnapshots: typeof data.promptSnapshots === "object" && data.promptSnapshots ? data.promptSnapshots : {},
    errorMessage: data.errorMessage ?? null,
    completedAt: data.completedAt ? serializeTimestamp(data.completedAt) : null,
    createdAt: serializeTimestamp(data.createdAt),
    updatedAt: serializeTimestamp(data.updatedAt)
  };
}

function onCollectionSnapshot<T>(
  path: string,
  constraints: QueryConstraint[],
  mapper: (id: string, data: DocumentData) => T,
  callback: (data: T[]) => void,
  onError?: (error: Error) => void
) {
  const target = query(collection(db, path), ...constraints);
  return onSnapshot(
    target,
    (snapshot) => callback(snapshot.docs.map((entry) => mapper(entry.id, entry.data()))),
    (error) => onError?.(error)
  );
}

export function listenToUser(uid: string, callback: (user: AppUser | null) => void, onError?: (error: Error) => void) {
  return onSnapshot(
    doc(db, "users", uid),
    (snapshot) => callback(snapshot.exists() ? mapUser(snapshot.id, snapshot.data()) : null),
    (error) => onError?.(error)
  );
}

export function listenToWebsites(userId: string, callback: (websites: Website[]) => void, onError?: (error: Error) => void) {
  return onCollectionSnapshot(
    "websites",
    [where("userId", "==", userId), orderBy("createdAt", "desc")],
    mapWebsite,
    callback,
    onError
  );
}

export function listenToPlans(callback: (plans: Plan[]) => void, onError?: (error: Error) => void, activeOnly = true) {
  const constraints = [orderBy("createdAt", "desc")] as QueryConstraint[];

  if (activeOnly) {
    constraints.unshift(where("isActive", "==", true));
  }

  return onCollectionSnapshot("plans", constraints, mapPlan, callback, onError);
}

export function listenToAllUsers(callback: (users: AppUser[]) => void, onError?: (error: Error) => void) {
  return onCollectionSnapshot("users", [orderBy("createdAt", "desc")], mapUser, callback, onError);
}

export function listenToAllPlans(callback: (plans: Plan[]) => void, onError?: (error: Error) => void) {
  return onCollectionSnapshot("plans", [orderBy("createdAt", "desc")], mapPlan, callback, onError);
}

export function listenToCreditLogs(
  userId: string,
  callback: (logs: CreditLog[]) => void,
  onError?: (error: Error) => void,
  take = 20
) {
  return onCollectionSnapshot(
    "creditLogs",
    [where("userId", "==", userId), orderBy("createdAt", "desc"), limit(take)],
    mapCreditLog,
    callback,
    onError
  );
}

export function listenToAllCreditLogs(callback: (logs: CreditLog[]) => void, onError?: (error: Error) => void, take = 100) {
  return onCollectionSnapshot("creditLogs", [orderBy("createdAt", "desc"), limit(take)], mapCreditLog, callback, onError);
}

export function listenToAuditRunsByWebsite(
  websiteId: string,
  callback: (runs: AuditRun[]) => void,
  onError?: (error: Error) => void
) {
  return onCollectionSnapshot(
    "auditRuns",
    [where("websiteId", "==", websiteId)],
    mapAuditRun,
    (runs) =>
      callback(
        [...runs].sort((left, right) => new Date(right.createdAt).getTime() - new Date(left.createdAt).getTime())
      ),
    onError
  );
}

export function listenToAuditRun(
  publicId: string,
  callback: (run: AuditRun | null) => void,
  onError?: (error: Error) => void
) {
  return onSnapshot(
    doc(db, "auditRuns", publicId),
    (snapshot) => callback(snapshot.exists() ? mapAuditRun(snapshot.id, snapshot.data()) : null),
    (error) => onError?.(error)
  );
}

export function listenToAuditRunItems(
  publicId: string,
  callback: (items: AuditRunItem[]) => void,
  onError?: (error: Error) => void
) {
  return onCollectionSnapshot(
    "auditRunItems",
    [where("auditRunId", "==", publicId)],
    mapAuditRunItem,
    (items) => callback([...items].sort((left, right) => left.position - right.position)),
    onError
  );
}

export async function createOrUpdateUserProfile(input: {
  uid: string;
  email: string;
  displayName?: string;
  role?: "admin" | "user";
}) {
  const userRef = doc(db, "users", input.uid);
  const current = await getDoc(userRef);
  const currentData = current.exists() ? current.data() : null;

  const payload = {
    uid: input.uid,
    email: input.email,
    displayName: input.displayName ?? "",
    role: input.role ?? (currentData?.role === "admin" ? "admin" : "user"),
    credits: currentData ? Number(currentData.credits ?? 0) : 0,
    createdAt: currentData ? currentData.createdAt : serverTimestamp(),
    updatedAt: serverTimestamp()
  };

  await setDoc(userRef, payload, { merge: true });
}

export async function createWebsite(userId: string, values: WebsiteValues) {
  const parsed = websiteSchema.parse(values);
  const websiteRef = doc(collection(db, "websites"));

  await setDoc(websiteRef, {
    userId,
    name: parsed.name,
    url: parsed.url,
    createdAt: serverTimestamp(),
    updatedAt: serverTimestamp()
  });

  return websiteRef.id;
}

export async function getWebsiteById(id: string) {
  const snapshot = await getDoc(doc(db, "websites", id));
  return snapshot.exists() ? mapWebsite(snapshot.id, snapshot.data()) : null;
}

export async function getPlanById(id: string) {
  const snapshot = await getDoc(doc(db, "plans", id));
  return snapshot.exists() ? mapPlan(snapshot.id, snapshot.data()) : null;
}

export async function saveWebsiteAudit(input: {
  auditId?: string;
  websiteId: string;
  userId: string;
  articleUrlsInput: string;
  categoriesInput: string;
}) {
  const articleUrls = parseArticleUrls(input.articleUrlsInput);
  const categories = parseCategories(input.categoriesInput);
  const auditRef = input.auditId ? doc(db, "websiteAudits", input.auditId) : doc(collection(db, "websiteAudits"));
  const current = input.auditId ? await getDoc(auditRef) : null;

  await setDoc(
    auditRef,
    {
      websiteId: input.websiteId,
      userId: input.userId,
      articleUrls,
      categories,
      createdAt: current?.exists() ? current.data().createdAt : serverTimestamp(),
      updatedAt: serverTimestamp()
    },
    { merge: true }
  );

  return auditRef.id;
}

export async function getAuditByWebsiteId(websiteId: string) {
  const snapshots = await getDocs(query(collection(db, "websiteAudits"), where("websiteId", "==", websiteId), limit(1)));

  if (snapshots.empty) {
    return null;
  }

  const first = snapshots.docs[0];
  return mapAudit(first.id, first.data());
}

export async function updatePlan(id: string, data: Partial<Plan>) {
  const ref = doc(db, "plans", id);
  await updateDoc(ref, {
    ...data,
    updatedAt: serverTimestamp()
  });
}

export async function createPlan(data: Pick<Plan, "name" | "price" | "credits" | "isActive">) {
  const ref = doc(collection(db, "plans"));
  await setDoc(ref, {
    ...data,
    createdAt: serverTimestamp(),
    updatedAt: serverTimestamp()
  });
  return ref.id;
}
