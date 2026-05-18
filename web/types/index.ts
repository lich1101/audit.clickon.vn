export type UserRole = "admin" | "user";

export type AppUser = {
  uid: string;
  email: string;
  displayName?: string;
  role: UserRole;
  credits: number;
  createdAt: string;
  updatedAt: string;
};

export type Plan = {
  id: string;
  name: string;
  price: number;
  credits: number;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
};

export type Website = {
  id: string;
  userId: string;
  name: string;
  url: string;
  createdAt: string;
  updatedAt: string;
};

export type AuditCategory = {
  name: string;
  url: string;
};

export type AuditRunStatus = "queued" | "processing" | "completed" | "partial" | "failed";
export type AuditRunItemStatus = "queued" | "fetching" | "analyzing" | "completed" | "failed";

export type WebsiteAudit = {
  id: string;
  websiteId: string;
  userId: string;
  articleUrls: string[];
  categories: AuditCategory[];
  createdAt: string;
  updatedAt: string;
};

export type CreditLog = {
  id: string;
  userId: string;
  type: "add" | "subtract";
  amount: number;
  balanceBefore: number;
  balanceAfter: number;
  reason: string;
  source: "admin" | "api" | "plan" | "system";
  createdAt: string;
};

export type PlanRequest = {
  id: number;
  firebaseUid: string;
  planId: string;
  planName: string;
  price: number;
  credits: number;
  status: "pending" | "approved" | "rejected";
  note?: string | null;
  approvedBy?: string | null;
  approvedAt?: string | null;
  createdAt: string;
  updatedAt: string;
};

export type CreditBalanceResponse = {
  userId: string;
  credits: number;
};

export type SessionUser = Pick<AppUser, "uid" | "email" | "role" | "credits" | "displayName">;

export type AuditRunItem = {
  publicId: string;
  auditRunId: string;
  websiteId: string;
  userId: string;
  position: number;
  targetUrl: string;
  status: AuditRunItemStatus;
  pageTitle?: string | null;
  metaDescription?: string | null;
  canonicalUrl?: string | null;
  headings?: {
    h1?: string[];
    h2?: string[];
    h3?: string[];
  };
  metrics?: Record<string, number | boolean | string | null>;
  primaryKeyword?: string | null;
  categoryName?: string | null;
  categoryUrl?: string | null;
  auditScore?: number | null;
  auditFindings: string[];
  auditRecommendations: string[];
  contentRevisionDirection?: string | null;
  contentExcerpt?: string | null;
  errorMessage?: string | null;
  completedAt?: string | null;
  createdAt: string;
  updatedAt: string;
};

export type AuditRun = {
  publicId: string;
  databaseId?: number;
  websiteId: string;
  websiteName?: string | null;
  websiteUrl?: string | null;
  userId: string;
  userEmail?: string | null;
  targetUrls: string[];
  categories: AuditCategory[];
  checklistText?: string | null;
  status: AuditRunStatus;
  totalUrls: number;
  processedUrls: number;
  completedUrls: number;
  failedUrls: number;
  startedAt?: string | null;
  completedAt?: string | null;
  createdAt: string;
  updatedAt: string;
  lastError?: string | null;
  items?: AuditRunItem[];
};
