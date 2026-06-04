import type { AuditRunItem, AuditRunItemStatus, WebsiteAuditUrlResult } from "@/types";

export type AuditExportColumnKey =
  | "stt"
  | "targetUrl"
  | "pageTitle"
  | "metaDescription"
  | "contentSource"
  | "contentExcerpt"
  | "contentError"
  | "status"
  | "stage"
  | "primaryKeyword"
  | "categoryName"
  | "categoryUrl"
  | "categoryMatchReason"
  | "auditScore"
  | "auditRecommendations"
  | "contentRevisionDirection"
  | "auditFindings"
  | "errorMessage"
  | "canonicalUrl"
  | "h1"
  | "h2"
  | "h3";

export type AuditExportColumnDefinition = {
  key: AuditExportColumnKey;
  header: string;
  width: number;
  defaultSelected: boolean;
};

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
  const useCurrentStep1Fields = Boolean(current);
  const useCurrentDerivedFields = Boolean(current);
  const errorMessage = current
    ? current.status === "failed"
      ? preferFilledString(current.errorMessage, persisted?.errorMessage)
      : preferFilledString(current.errorMessage)
      : preferFilledString(persisted?.errorMessage);

  return {
    targetUrl,
    status: current?.status ?? persisted?.status,
    extractionSource: current?.extractionSource ?? null,
    contentSource: useCurrentStep1Fields
      ? preferFilledString(current?.contentSource, persisted?.contentSource)
      : preferFilledString(persisted?.contentSource, current?.contentSource),
    contentError: useCurrentStep1Fields
      ? preferFilledString(current?.contentError, persisted?.contentError)
      : preferFilledString(persisted?.contentError, current?.contentError),
    readerUrl: preferFilledString(current?.readerUrl, persisted?.readerUrl),
    pageTitle: useCurrentStep1Fields
      ? preferFilledString(current?.pageTitle, persisted?.pageTitle)
      : preferFilledString(persisted?.pageTitle, current?.pageTitle),
    metaDescription: useCurrentStep1Fields
      ? preferFilledString(current?.metaDescription, persisted?.metaDescription)
      : preferFilledString(persisted?.metaDescription, current?.metaDescription),
    canonicalUrl: useCurrentStep1Fields
      ? preferFilledString(current?.canonicalUrl, persisted?.canonicalUrl)
      : preferFilledString(persisted?.canonicalUrl, current?.canonicalUrl),
    headings: useCurrentStep1Fields
      ? (current?.headings && Object.keys(current.headings).length > 0 ? current.headings : persisted?.headings)
      : (persisted?.headings && Object.keys(persisted.headings).length > 0 ? persisted.headings : current?.headings),
    metrics: useCurrentStep1Fields
      ? (current?.metrics && Object.keys(current.metrics).length > 0 ? current.metrics : persisted?.metrics)
      : (persisted?.metrics && Object.keys(persisted.metrics).length > 0 ? persisted.metrics : current?.metrics),
    primaryKeyword: useCurrentDerivedFields
      ? preferFilledString(current?.primaryKeyword)
      : preferFilledString(current?.primaryKeyword, persisted?.primaryKeyword),
    categoryName: useCurrentDerivedFields
      ? preferFilledString(current?.categoryName)
      : preferFilledString(current?.categoryName, persisted?.categoryName),
    categoryUrl: useCurrentDerivedFields
      ? preferFilledString(current?.categoryUrl)
      : preferFilledString(current?.categoryUrl, persisted?.categoryUrl),
    categoryMatchReason: useCurrentDerivedFields
      ? preferFilledString(current?.categoryMatchReason)
      : preferFilledString(current?.categoryMatchReason, persisted?.categoryMatchReason),
    auditScore: useCurrentDerivedFields ? (current?.auditScore ?? null) : (current?.auditScore ?? persisted?.auditScore ?? null),
    auditFindings: useCurrentDerivedFields ? preferStringArray(current?.auditFindings) : preferStringArray(current?.auditFindings, persisted?.auditFindings),
    auditRecommendations: useCurrentDerivedFields ? preferStringArray(current?.auditRecommendations) : preferStringArray(current?.auditRecommendations, persisted?.auditRecommendations),
    contentRevisionDirection: useCurrentDerivedFields
      ? preferFilledString(current?.contentRevisionDirection)
      : preferFilledString(current?.contentRevisionDirection, persisted?.contentRevisionDirection),
    contentExcerpt: useCurrentStep1Fields
      ? preferFilledString(current?.contentExcerpt, persisted?.contentExcerpt)
      : preferFilledString(persisted?.contentExcerpt, current?.contentExcerpt),
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

export const AUDIT_EXPORT_FIELD_DEFINITIONS: AuditExportColumnDefinition[] = [
  { key: "stt", header: "STT", width: 6, defaultSelected: true },
  { key: "targetUrl", header: "URL mục tiêu", width: 42, defaultSelected: true },
  { key: "pageTitle", header: "B1: Tiêu đề trang", width: 34, defaultSelected: true },
  { key: "metaDescription", header: "B1: Meta description", width: 34, defaultSelected: true },
  { key: "contentSource", header: "B1: Nguồn crawl", width: 12, defaultSelected: true },
  { key: "contentExcerpt", header: "B1: Nội dung", width: 48, defaultSelected: true },
  { key: "contentError", header: "B1: Lỗi crawl", width: 28, defaultSelected: true },
  { key: "status", header: "Trạng thái", width: 12, defaultSelected: true },
  { key: "stage", header: "Giai đoạn", width: 24, defaultSelected: true },
  { key: "primaryKeyword", header: "Từ khóa chính", width: 28, defaultSelected: true },
  { key: "categoryName", header: "Danh mục", width: 24, defaultSelected: true },
  { key: "categoryUrl", header: "URL danh mục", width: 34, defaultSelected: false },
  { key: "categoryMatchReason", header: "Lý do chọn danh mục", width: 28, defaultSelected: false },
  { key: "auditScore", header: "Điểm audit", width: 10, defaultSelected: true },
  { key: "auditRecommendations", header: "Đề xuất audit", width: 42, defaultSelected: true },
  { key: "contentRevisionDirection", header: "Định hướng chỉnh sửa nội dung", width: 42, defaultSelected: true },
  { key: "auditFindings", header: "Nhận định audit", width: 42, defaultSelected: false },
  { key: "errorMessage", header: "Lỗi run", width: 28, defaultSelected: true },
  { key: "canonicalUrl", header: "Canonical", width: 34, defaultSelected: false },
  { key: "h1", header: "H1", width: 28, defaultSelected: false },
  { key: "h2", header: "H2", width: 28, defaultSelected: false },
  { key: "h3", header: "H3", width: 28, defaultSelected: false },
];

export const DEFAULT_AUDIT_EXPORT_COLUMN_KEYS = AUDIT_EXPORT_FIELD_DEFINITIONS
  .filter((field) => field.defaultSelected)
  .map((field) => field.key);

export function buildAuditExportRowForColumns(
  index: number,
  row: AuditWorkbenchRow,
  selectedKeys: AuditExportColumnKey[]
) {
  const fullRow = buildAuditExportRow(index, row);
  const headerMap: Record<AuditExportColumnKey, keyof typeof fullRow> = {
    stt: "STT",
    targetUrl: "URL mục tiêu",
    pageTitle: "B1: Tiêu đề trang",
    metaDescription: "B1: Meta description",
    contentSource: "B1: Nguồn crawl",
    contentExcerpt: "B1: Nội dung",
    contentError: "B1: Lỗi crawl",
    status: "Trạng thái",
    stage: "Giai đoạn",
    primaryKeyword: "Từ khóa chính",
    categoryName: "Danh mục",
    categoryUrl: "URL danh mục",
    categoryMatchReason: "Lý do chọn danh mục",
    auditScore: "Điểm audit",
    auditRecommendations: "Đề xuất audit",
    contentRevisionDirection: "Định hướng chỉnh sửa nội dung",
    auditFindings: "Nhận định audit",
    errorMessage: "Lỗi run",
    canonicalUrl: "Canonical",
    h1: "H1",
    h2: "H2",
    h3: "H3",
  };

  return selectedKeys.reduce<Record<string, string | number>>((result, key) => {
    const header = headerMap[key];
    result[header] = fullRow[header];
    return result;
  }, {});
}
