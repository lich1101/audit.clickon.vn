export type UserRole = "admin" | "user";

export type AppUser = {
  uid: string;
  email: string;
  displayName?: string;
  role: UserRole;
  realRole?: UserRole;
  isImpersonating?: boolean;
  balanceUsd: number;
  credits: number;
  captchaCredits: number;
  legacyCreditsPerUsd?: number;
  ownedDataSummary?: OwnedDataSummary;
  createdAt: string;
  updatedAt: string;
};

export type OwnedDataSummary = {
  websiteCount: number;
  auditRunCount: number;
  keywordCount: number;
  keywordRunCount: number;
  captchaTaskCount: number;
  creditTransactionCount: number;
  planRequestCount: number;
  productRequestCount: number;
  activeAuditRunCount: number;
  activeKeywordRankRunCount: number;
};

export type Product = {
  id: string;
  name: string;
  type: "captcha_pack" | "audit_credit";
  price: number;
  captchaCredits: number;
  balanceUsd: number;
  credits: number;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
};

export type ProductRequest = {
  id: number;
  firebaseUid: string;
  productId: string;
  productName: string;
  productType: "captcha_pack" | "audit_credit";
  price: number;
  captchaCredits: number;
  balanceUsd: number;
  credits: number;
  status: "pending" | "approved" | "rejected";
  note?: string | null;
  approvedBy?: string | null;
  approvedAt?: string | null;
  createdAt: string;
  updatedAt: string;
};

export type Plan = {
  id: string;
  name: string;
  price: number;
  balanceUsd: number;
  credits: number;
  captchaCredits: number;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
};

export type AuditRunStatus = "queued" | "processing" | "completed" | "partial" | "failed";
export type AuditRunItemStatus = "queued" | "fetching" | "analyzing" | "completed" | "failed";
export type AiProvider = "openai" | "deepseek" | "gemini" | "gemini_deep_research" | "perplexity";
export type AuditWorkflow = "standard" | "audit_deep_research";
export type AuditPipelineMode = "standard" | "fast";
export type AuditRunStartStep = 1 | 2 | 3;
export type AuditRunStopAfterStep = 1 | 2 | 3 | null;
export type DeepResearchResearchProvider = "perplexity" | "gemini_deep_research";
export type DeepResearchReasoningProvider = "openai" | "gemini";

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
  ownerEmail?: string | null;
  ownerDisplayName?: string | null;
  name: string;
  url: string;
  sameDayReauditGrantedUntil?: string | null;
  sameDayReauditGrantedBy?: string | null;
  todayRunCount?: number;
  dailyLimit?: number;
  canRunAuditToday?: boolean;
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
  metaDescription?: string | null;
  canonicalUrl?: string | null;
  headings?: {
    h1?: string[];
    h2?: string[];
    h3?: string[];
  };
  metrics?: Record<string, number | boolean | string | null>;
  contentExcerpt?: string | null;
  hasContentExcerpt?: boolean | null;
  contentSource?: string | null;
  contentError?: string | null;
  readerUrl?: string | null;
  primaryKeyword?: string | null;
  categoryName?: string | null;
  categoryUrl?: string | null;
  categoryMatchReason?: string | null;
  auditScore?: number | null;
  auditFindings?: string[];
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
  amountUsd: number;
  balanceBefore: number;
  balanceAfter: number;
  balanceBeforeUsd: number;
  balanceAfterUsd: number;
  reason: string;
  source: "admin" | "api" | "plan" | "audit" | "audit_reconcile" | "system";
  createdAt: string;
};

export type AiUsageReconciliationEventStatus = "undercharged" | "overcharged" | "aligned";

export type AiUsageReconciliationSummary = {
  scannedEventCount: number;
  affectedEventCount: number;
  underchargedEventCount: number;
  overchargedEventCount: number;
  alignedEventCount: number;
  affectedRunCount: number;
  chargedUsd: number;
  expectedUsd: number;
  usdDelta: number;
  chargedCredits: number;
  expectedCredits: number;
  creditDelta: number;
};

export type AiUsageReconciliationRun = {
  runPublicId: string;
  websiteName: string;
  websiteUrl: string;
  userUid: string;
  workflow: string;
  pipelineMode: string;
  eventCount: number;
  affectedEventCount: number;
  chargedUsd: number;
  expectedUsd: number;
  usdDelta: number;
  chargedCredits: number;
  expectedCredits: number;
  creditDelta: number;
  latestEventAt?: string | null;
  status: AiUsageReconciliationEventStatus;
};

export type AiUsageReconciliationEvent = {
  eventId: number;
  itemId: number;
  itemPublicId: string;
  position: number;
  targetUrl: string;
  runPublicId: string;
  userUid: string;
  websiteName: string;
  websiteUrl: string;
  workflow: string;
  pipelineMode: string;
  step: string;
  provider: string;
  model: string;
  inputTokens: number;
  outputTokens: number;
  totalTokens: number;
  citationTokens: number;
  reasoningTokens: number;
  searchQueries: number;
  providerReportedCostUsd?: number | null;
  chargedUsd: number;
  expectedUsd: number;
  usdDelta: number;
  chargedCredits: number;
  expectedCredits: number;
  creditDelta: number;
  status: AiUsageReconciliationEventStatus;
  pricingSource: string;
  isExact: boolean;
  createdAt?: string | null;
};

export type AiUsageReconciliationReport = {
  filters: {
    status: string;
    provider?: string | null;
    userUid?: string | null;
    runPublicId?: string | null;
    limit: number;
  };
  summary: AiUsageReconciliationSummary;
  runs: AiUsageReconciliationRun[];
  events: AiUsageReconciliationEvent[];
};

export type AiUsageReconciliationBackfillRow = {
  eventId: number;
  runPublicId: string;
  itemPublicId: string;
  applied: boolean;
  usdDelta?: number;
  creditDelta?: number;
  newUsdCharged?: number;
  newCreditsCharged?: number;
  error?: string;
  reason?: string;
};

export type AiUsageReconciliationBackfillResult = {
  summary: {
    candidateEventCount: number;
    appliedEventCount: number;
    appliedUsdDelta: number;
    appliedCreditDelta: number;
    failedEventCount: number;
  };
  results: AiUsageReconciliationBackfillRow[];
};

export type PlanRequest = {
  id: number;
  firebaseUid: string;
  planId: string;
  planName: string;
  price: number;
  balanceUsd: number;
  credits: number;
  captchaCredits: number;
  status: "pending" | "approved" | "rejected";
  note?: string | null;
  approvedBy?: string | null;
  approvedAt?: string | null;
  createdAt: string;
  updatedAt: string;
};

export type KeywordRankStatus = "queued" | "found" | "not_found" | "blocked" | "error" | "stopped";

export type KeywordRankKeyword = {
  id: string;
  websiteId: string;
  keyword: string;
  latestStatus?: KeywordRankStatus | null;
  latestRank?: number | null;
  latestPage?: number | null;
  latestUrl?: string | null;
  latestTitle?: string | null;
  latestError?: string | null;
  latestCheckedAt?: string | null;
  updatedAt?: string | null;
};

export type KeywordRankRunItem = {
  keywordId?: string | null;
  keyword: string;
  status: KeywordRankStatus;
  rank?: number | null;
  page?: number | null;
  matchedUrl?: string | null;
  title?: string | null;
  error?: string | null;
  checkedAt?: string | null;
};

export type KeywordRankRun = {
  publicId: string;
  websiteId: string;
  targetDomain: string;
  status: "queued" | "processing" | "completed" | "partial" | "failed" | "stopped";
  captchaEnabled: boolean;
  totalKeywords: number;
  processedKeywords: number;
  completedKeywords: number;
  failedKeywords: number;
  captchaSolveAttempts: number;
  captchaSolveSuccesses: number;
  lastError?: string | null;
  startedAt?: string | null;
  completedAt?: string | null;
  items: KeywordRankRunItem[];
};

export type KeywordRankPreferences = {
  delayMin: number;
  delayMax: number;
  autoCaptcha: boolean;
  googleHost: string;
  hl: string;
  gl: string;
  updatedAt?: string | null;
};

export type KeywordRankProxyPolicy = {
  enabled: boolean;
  useGithubHttp: boolean;
  useGithubSocks5: boolean;
  manualCount: number;
};

export type KeywordRankBoard = {
  website: Pick<Website, "id" | "name" | "url" | "userId">;
  targetDomain: string;
  keywords: KeywordRankKeyword[];
  latestRun?: KeywordRankRun | null;
  captchaCredits: number;
  serpPages: number;
  preferences: KeywordRankPreferences;
  proxyPolicy: KeywordRankProxyPolicy;
  extension: {
    bridgeMessageVersion: number;
    required: boolean;
    installUrl?: string;
  };
};

export type CaptchaSolveTask = {
  id: string;
  status: "processing" | "ready" | "failed";
  solutionToken?: string | null;
  costUsd?: number | null;
  charged: boolean;
  errorMessage?: string | null;
  captchaCredits: number;
};

export type JsonFormatterProvider = "openai" | "deepseek" | "gemini";

export type AuditPromptStep =
  | "keyword_category_mapping"
  | "keyword_category_json_formatter"
  | "onpage_audit"
  | "onpage_audit_json_formatter"
  | "fast_audit_combined"
  | "fast_audit_json_formatter"
  | "deep_research_research"
  | "deep_research_audit"
  | "deep_research_json_formatter";

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
  balanceUsd: number;
  credits: number;
};

export type SessionUser = Pick<AppUser, "uid" | "email" | "role" | "realRole" | "isImpersonating" | "balanceUsd" | "credits" | "captchaCredits" | "displayName">;

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
    | "url_only_batch_step1_running"
    | "url_only_batch_step1_done"
    | "url_only_batch_step1_only_completed"
    | "url_only_batch_step2_running"
    | "url_only_batch_step2_done"
    | "url_only_batch_step2_only_completed"
    | "url_only_batch_step3_running"
    | string
    | null;
  contentSource?: string | null;
  contentError?: string | null;
  readerUrl?: string | null;
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
  hasContentExcerpt?: boolean | null;
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
  status?: "parsed" | "parse_failed" | "needs_json_formatter" | string | null;
  provider?: AiProvider | string | null;
  model?: string | null;
  interactionId?: string | null;
  remoteStatus?: string | null;
  interactionStartedAt?: string | null;
  lastPollAt?: string | null;
  staleDetectedAt?: string | null;
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

export type AuditAiStepError = {
  stepKey: string;
  stepLabel: string;
  status?: string | null;
  errorMessage?: string | null;
  parseError?: string | null;
  positionFrom?: number | null;
  positionTo?: number | null;
  provider?: string | null;
  model?: string | null;
  createdAt?: string | null;
};

export type AuditRunUsageStepSummary = {
  key: string;
  label: string;
  eventCount: number;
  providers: string[];
  models: string[];
  rawSteps: string[];
  inputTokens: number;
  outputTokens: number;
  totalTokens: number;
  citationTokens: number;
  reasoningTokens: number;
  searchQueries: number;
  creditsCharged: number;
  usdCharged: number;
  providerReportedCostUsd?: number | null;
  estimatedCostUsd?: number | null;
};

export type AuditRunUsageSummary = {
  costVisibility: "reported" | "partial" | "tokens_only";
  estimateVisibility: "estimated" | "partial" | "none";
  totals: {
    eventCount: number;
    inputTokens: number;
    outputTokens: number;
    totalTokens: number;
    citationTokens: number;
    reasoningTokens: number;
    searchQueries: number;
    creditsCharged: number;
    usdCharged: number;
    providerReportedCostUsd?: number | null;
    estimatedCostUsd?: number | null;
  };
  byStep: AuditRunUsageStepSummary[];
};

export type AuditRun = {
  publicId: string;
  databaseId?: number;
  websiteId: string;
  websiteName?: string | null;
  websiteUrl?: string | null;
  workflow?: AuditWorkflow;
  pipelineMode?: AuditPipelineMode;
  callbackUrl?: string | null;
  startFromStep?: AuditRunStartStep | null;
  stopAfterStep?: AuditRunStopAfterStep;
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
  fastAiProvider?: AiProvider | null;
  fastAiModel?: string | null;
  fastFormatterProvider?: JsonFormatterProvider | null;
  fastFormatterModel?: string | null;
  deepResearchResearchProvider?: DeepResearchResearchProvider | null;
  deepResearchResearchModel?: string | null;
  deepResearchReasoningProvider?: DeepResearchReasoningProvider | null;
  deepResearchReasoningModel?: string | null;
  deepResearchFormatterProvider?: JsonFormatterProvider | null;
  deepResearchFormatterModel?: string | null;
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
  aiStepErrors?: AuditAiStepError[];
  usageSummary?: AuditRunUsageSummary | null;
  aiStepResponses?: Record<string, AuditAiStepResponse>;
  items?: AuditRunItem[];
};

export type IndexProperty = {
  id: number;
  code: string;
  name: string;
  siteUrl: string;
  siteOrigin: string;
  siteHost: string;
  gscProperty: string;
  isOwned: boolean;
  enabled: boolean;
  permissionLevel?: string | null;
  dailyPublishQuota: number;
  dailyInspectQuota: number;
  pendingCount: number;
  sentCount: number;
  failedCount: number;
  sendingCount: number;
  totalUrls: number;
  createdAt?: string;
  updatedAt?: string;
};

export type IndexUrlRow = {
  id: number;
  urlExact: string;
  status: string;
  priority: number;
  lastError?: string | null;
  httpStatus?: number | null;
  inspectVerdict?: string | null;
  sentAt?: string | null;
  createdAt?: string | null;
  siteName?: string | null;
  siteHost?: string | null;
  siteOrigin?: string | null;
};

export type IndexUrlView = "indexed" | "pending" | "failed" | "quota_today";

export type IndexUrlList = {
  view: IndexUrlView;
  title: string;
  total: number;
  page: number;
  perPage: number | "all";
  lastPage: number;
  urls: IndexUrlRow[];
};

export type IndexPropertyStats = {
  pending: number;
  sent: number;
  failed: number;
  sending: number;
  total: number;
};

export type IndexPropertyDetail = {
  property: IndexProperty;
  stats: IndexPropertyStats;
  urls: IndexUrlRow[];
};

export type IndexPreviewGroup = {
  siteOrigin: string;
  siteHost: string;
  urls: string[];
};

export type IndexPreview = {
  count: number;
  groups: IndexPreviewGroup[];
};

export type IndexQuotaBucket = {
  used: number;
  limit: number;
  remaining: number;
};

export type IndexQuotaStatus = {
  dayPt: string;
  gcpProjectKey: string;
  dryRun: boolean;
  publish: IndexQuotaBucket;
  inspect: IndexQuotaBucket;
};

export type IndexImportResult = {
  ok: boolean;
  message?: string;
  rejected?: boolean;
  inserted?: number;
  duplicates?: number;
  skippedOtherSite?: number;
  siteOrigin?: string;
  propertyId?: number;
  propertyCode?: string;
  linkCount?: number;
};

export type IndexSettings = {
  configured: boolean;
  serviceAccountEmail?: string | null;
  projectId?: string | null;
  dryRun: boolean;
  updatedAt?: string | null;
  gscSiteCount?: number | null;
};

export type IndexGscSite = {
  siteUrl: string;
  permissionLevel?: string | null;
};

export type IndexSettingsTestResult = {
  ok: boolean;
  serviceAccountEmail?: string;
  projectId?: string;
  siteCount?: number;
  sites?: IndexGscSite[];
  message?: string;
};
