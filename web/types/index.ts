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

export type AuditRunStatus = "queued" | "processing" | "completed" | "partial" | "failed";
export type AuditRunItemStatus = "queued" | "fetching" | "analyzing" | "completed" | "failed";
export type AiProvider = "openai" | "gemini" | "gemini_deep_research";

export type WebsiteActiveRunSummary = {
  publicId: string;
  status: AuditRunStatus;
  totalUrls: number;
  processedUrls: number;
  completedUrls: number;
  failedUrls: number;
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
  activeRun?: WebsiteActiveRunSummary | null;
};

export type AuditCategory = {
  name: string;
  url: string;
};

export type WebsiteAuditUrlResult = {
  targetUrl: string;
  status: AuditRunItemStatus;
  pageTitle?: string | null;
  primaryKeyword?: string | null;
  categoryName?: string | null;
  categoryUrl?: string | null;
  categoryMatchReason?: string | null;
  auditScore?: number | null;
  auditRecommendations: string[];
  contentRevisionDirection?: string | null;
  errorMessage?: string | null;
  aiProvider?: AiProvider;
  aiModel?: string | null;
  step2AiProvider?: AiProvider | null;
  step2AiModel?: string | null;
  step3AiProvider?: AiProvider | null;
  step3AiModel?: string | null;
  auditedAt?: string | null;
  updatedAt?: string | null;
};

export type WebsiteAudit = {
  id: string;
  websiteId: string;
  userId: string;
  articleUrls: string[];
  categories: AuditCategory[];
  checklistText?: string | null;
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
  source: "admin" | "api" | "plan" | "audit" | "system";
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

export type JsonFormatterProvider = "openai" | "gemini";

export type AuditPromptStep =
  | "keyword_category_mapping"
  | "keyword_category_json_formatter"
  | "onpage_audit"
  | "onpage_audit_json_formatter";

export type AuditPromptTemplate = {
  step: AuditPromptStep;
  title: string;
  systemPrompt: string;
  developerPrompt: string;
  userPrompt: string;
  isActive: boolean;
  isDefault: boolean;
  updatedAt?: string | null;
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
  extractionSource?:
    | "jina"
    | "html"
    | "url_only"
    | "url_only_batch"
    | "url_only_batch_step2_running"
    | "url_only_batch_step2_done"
    | "url_only_batch_step3_running"
    | string
    | null;
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
  categoryMatchReason?: string | null;
  auditScore?: number | null;
  auditFindings: string[];
  auditRecommendations: string[];
  contentRevisionDirection?: string | null;
  contentExcerpt?: string | null;
  promptSnapshots?: Record<
    string,
    {
      step?: string;
      provider?: string | null;
      model?: string | null;
      createdAt?: string | null;
      systemPromptPreview?: string | null;
      userPromptPreview?: string | null;
    }
  >;
  errorMessage?: string | null;
  completedAt?: string | null;
  createdAt: string;
  updatedAt: string;
};

export type AuditAiStepResponse = {
  step?: string;
  stepLabel?: string | null;
  status?: "parsed" | "parse_failed" | string | null;
  provider?: AiProvider | string | null;
  model?: string | null;
  interactionId?: string | null;
  parseError?: string | null;
  requestPath?: string | null;
  requestBytes?: number | null;
  requestOriginalBytes?: number | null;
  requestTruncated?: boolean;
  requestPreview?: string | null;
  requestCreatedAt?: string | null;
  rawTextPath?: string | null;
  rawTextBytes?: number | null;
  rawTextOriginalBytes?: number | null;
  rawTextTruncated?: boolean;
  rawTextPreview?: string | null;
  createdAt?: string | null;
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
  categoryContexts?: Array<{
    name?: string | null;
    url?: string | null;
    title?: string | null;
    source?: string | null;
    error?: string | null;
    contentExcerpt?: string | null;
  }>;
  checklistText?: string | null;
  aiProvider?: AiProvider;
  aiModel?: string | null;
  step2AiProvider?: AiProvider | null;
  step2AiModel?: string | null;
  step3AiProvider?: AiProvider | null;
  step3AiModel?: string | null;
  step2FormatterProvider?: JsonFormatterProvider | null;
  step2FormatterModel?: string | null;
  step3FormatterProvider?: JsonFormatterProvider | null;
  step3FormatterModel?: string | null;
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
  aiStepResponses?: Record<string, AuditAiStepResponse>;
  items?: AuditRunItem[];
};
