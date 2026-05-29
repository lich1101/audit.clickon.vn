import type { AuditRunItem, AuditRunItemStatus, WebsiteAuditUrlResult } from "@/types";

export type AuditWorkbenchRow = {
  targetUrl?: string;
  status?: AuditRunItemStatus;
  extractionSource?: string | null;
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
  auditFindings?: string[];
  auditRecommendations?: string[];
  contentRevisionDirection?: string | null;
  contentExcerpt?: string | null;
  errorMessage?: string | null;
  stageHint?: string | null;
};

function preferFilledString(...values: Array<string | null | undefined>) {
  for (const value of values) {
    if (typeof value === "string" && value.trim()) {
      return value;
    }
  }

  return null;
}

function preferStringArray(...values: Array<string[] | null | undefined>) {
  for (const value of values) {
    if (Array.isArray(value) && value.length > 0) {
      return value;
    }
  }

  return [];
}

export function stageLabelForSource(source?: string | null) {
  if (source === "url_only_batch_step1_running") {
    return "Bước 1: lấy nội dung";
  }

  if (source === "url_only_batch_step1_done") {
    return "Chờ bước 2";
  }

  if (source === "url_only_batch_step1_only_completed") {
    return "Hoàn tất bước 1";
  }

  if (source === "url_only_batch_step2_running") {
    return "Bước 2: keyword + danh mục";
  }

  if (source === "url_only_batch_step2_done") {
    return "Chờ bước 3";
  }

  if (source === "url_only_batch_step2_only_completed") {
    return "Hoàn tất bước 2";
  }

  if (source === "url_only_batch_step3_running") {
    return "Bước 3: audit onpage";
  }

  if (source === "audit_deep_research_running" || source === "audit_deep_research") {
    return "Bước 3: deep research";
  }

  return source ?? "";
}

export function mergeAuditWorkbenchRow(
  targetUrl: string,
  persisted?: WebsiteAuditUrlResult | null,
  current?: AuditRunItem | null
): AuditWorkbenchRow {
  const errorMessage = current
    ? current.status === "failed"
      ? preferFilledString(current.errorMessage, persisted?.errorMessage)
      : preferFilledString(current.errorMessage)
    : preferFilledString(persisted?.errorMessage);

  return {
    targetUrl,
    status: current?.status ?? persisted?.status,
    extractionSource: current?.extractionSource ?? null,
    contentSource: preferFilledString(current?.contentSource, persisted?.contentSource),
    contentError: preferFilledString(current?.contentError, persisted?.contentError),
    readerUrl: preferFilledString(current?.readerUrl, persisted?.readerUrl),
    pageTitle: preferFilledString(current?.pageTitle, persisted?.pageTitle),
    metaDescription: preferFilledString(current?.metaDescription, persisted?.metaDescription),
    canonicalUrl: preferFilledString(current?.canonicalUrl, persisted?.canonicalUrl),
    headings: current?.headings && Object.keys(current.headings).length > 0 ? current.headings : persisted?.headings,
    metrics: current?.metrics && Object.keys(current.metrics).length > 0 ? current.metrics : persisted?.metrics,
    primaryKeyword: preferFilledString(current?.primaryKeyword, persisted?.primaryKeyword),
    categoryName: preferFilledString(current?.categoryName, persisted?.categoryName),
    categoryUrl: preferFilledString(current?.categoryUrl, persisted?.categoryUrl),
    categoryMatchReason: preferFilledString(current?.categoryMatchReason, persisted?.categoryMatchReason),
    auditScore: current?.auditScore ?? persisted?.auditScore ?? null,
    auditFindings: preferStringArray(current?.auditFindings, persisted?.auditFindings),
    auditRecommendations: preferStringArray(current?.auditRecommendations, persisted?.auditRecommendations),
    contentRevisionDirection: preferFilledString(current?.contentRevisionDirection, persisted?.contentRevisionDirection),
    contentExcerpt: preferFilledString(current?.contentExcerpt, persisted?.contentExcerpt),
    errorMessage,
  };
}

export function enrichWorkbenchRowForExport(row: AuditWorkbenchRow, fullItem?: AuditRunItem | null): AuditWorkbenchRow {
  if (!fullItem) {
    return row;
  }

  return {
    ...row,
    status: fullItem.status ?? row.status,
    extractionSource: fullItem.extractionSource ?? row.extractionSource,
    contentSource: preferFilledString(fullItem.contentSource, row.contentSource),
    contentError: preferFilledString(fullItem.contentError, row.contentError),
    readerUrl: preferFilledString(fullItem.readerUrl, row.readerUrl),
    pageTitle: preferFilledString(fullItem.pageTitle, row.pageTitle),
    metaDescription: preferFilledString(fullItem.metaDescription, row.metaDescription),
    canonicalUrl: preferFilledString(fullItem.canonicalUrl, row.canonicalUrl),
    headings: fullItem.headings && Object.keys(fullItem.headings).length > 0 ? fullItem.headings : row.headings,
    metrics: fullItem.metrics && Object.keys(fullItem.metrics).length > 0 ? fullItem.metrics : row.metrics,
    primaryKeyword: preferFilledString(fullItem.primaryKeyword, row.primaryKeyword),
    categoryName: preferFilledString(fullItem.categoryName, row.categoryName),
    categoryUrl: preferFilledString(fullItem.categoryUrl, row.categoryUrl),
    categoryMatchReason: preferFilledString(fullItem.categoryMatchReason, row.categoryMatchReason),
    auditScore: fullItem.auditScore ?? row.auditScore ?? null,
    auditFindings: preferStringArray(fullItem.auditFindings, row.auditFindings),
    auditRecommendations: preferStringArray(fullItem.auditRecommendations, row.auditRecommendations),
    contentRevisionDirection: preferFilledString(fullItem.contentRevisionDirection, row.contentRevisionDirection),
    contentExcerpt: preferFilledString(fullItem.contentExcerpt, row.contentExcerpt),
    errorMessage: preferFilledString(fullItem.errorMessage, row.errorMessage),
  };
}

export function auditRunItemToWorkbenchRow(item: AuditRunItem): AuditWorkbenchRow {
  return {
    targetUrl: item.targetUrl,
    status: item.status,
    extractionSource: item.extractionSource ?? null,
    contentSource: item.contentSource ?? null,
    contentError: item.contentError ?? null,
    readerUrl: item.readerUrl ?? null,
    pageTitle: item.pageTitle ?? null,
    metaDescription: item.metaDescription ?? null,
    canonicalUrl: item.canonicalUrl ?? null,
    headings: item.headings ?? {},
    metrics: item.metrics ?? {},
    primaryKeyword: item.primaryKeyword ?? null,
    categoryName: item.categoryName ?? null,
    categoryUrl: item.categoryUrl ?? null,
    categoryMatchReason: item.categoryMatchReason ?? null,
    auditScore: item.auditScore ?? null,
    auditFindings: Array.isArray(item.auditFindings) ? item.auditFindings : [],
    auditRecommendations: Array.isArray(item.auditRecommendations) ? item.auditRecommendations : [],
    contentRevisionDirection: item.contentRevisionDirection ?? null,
    contentExcerpt: item.contentExcerpt ?? null,
    errorMessage: item.errorMessage ?? null,
  };
}

function formatStringList(values?: string[] | null, separator = "\n") {
  return (values ?? [])
    .map((value) => value.trim())
    .filter(Boolean)
    .join(separator);
}

function formatHeadings(values?: string[] | null) {
  return (values ?? []).map((value) => value.trim()).filter(Boolean).join("\n");
}

export function buildAuditExportRow(index: number, row: AuditWorkbenchRow) {
  return {
    STT: index + 1,
    "URL mục tiêu": row.targetUrl ?? "",
    "B1: Tiêu đề trang": row.pageTitle ?? "",
    "B1: Meta description": row.metaDescription ?? "",
    "B1: Nguồn crawl": row.contentSource ?? "",
    "B1: Nội dung": row.contentExcerpt ?? "",
    "B1: Lỗi crawl": row.contentError ?? "",
    "Trạng thái": row.status ?? "",
    "Giai đoạn": stageLabelForSource(row.extractionSource),
    "Từ khóa chính": row.primaryKeyword ?? "",
    "Danh mục": row.categoryName ?? "",
    "URL danh mục": row.categoryUrl ?? "",
    "Lý do chọn danh mục": row.categoryMatchReason ?? "",
    "Điểm audit": typeof row.auditScore === "number" ? row.auditScore : "",
    "Đề xuất audit": formatStringList(row.auditRecommendations),
    "Định hướng chỉnh sửa nội dung": row.contentRevisionDirection ?? "",
    "Nhận định audit": formatStringList(row.auditFindings),
    "Lỗi run": row.errorMessage ?? "",
    Canonical: row.canonicalUrl ?? "",
    H1: formatHeadings(row.headings?.h1),
    H2: formatHeadings(row.headings?.h2),
    H3: formatHeadings(row.headings?.h3),
  };
}

export const AUDIT_EXPORT_COLUMNS = [
  "STT",
  "URL mục tiêu",
  "B1: Tiêu đề trang",
  "B1: Meta description",
  "B1: Nguồn crawl",
  "B1: Nội dung",
  "B1: Lỗi crawl",
  "Trạng thái",
  "Giai đoạn",
  "Từ khóa chính",
  "Danh mục",
  "URL danh mục",
  "Lý do chọn danh mục",
  "Điểm audit",
  "Đề xuất audit",
  "Định hướng chỉnh sửa nội dung",
  "Nhận định audit",
  "Lỗi run",
  "Canonical",
  "H1",
  "H2",
  "H3",
] as const;
