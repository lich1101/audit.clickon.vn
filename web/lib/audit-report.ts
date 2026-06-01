"use client";

import {
  auditRunItemToWorkbenchRow,
  AUDIT_EXPORT_FIELD_DEFINITIONS,
  buildAuditExportRowForColumns,
  DEFAULT_AUDIT_EXPORT_COLUMN_KEYS,
  enrichWorkbenchRowForExport,
  type AuditWorkbenchRow,
  type AuditExportColumnKey,
} from "@/lib/audit-workbench-data";
import type { AuditRun, AuditRunItem } from "@/types";

function sanitizeFilenamePart(input: string) {
  return input
    .toLowerCase()
    .replace(/[^a-z0-9]+/gi, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

type ExportAuditWorkbenchInput = {
  websiteName?: string | null;
  websiteId?: string | null;
  runPublicId?: string | null;
  urls: string[];
  rowsByUrl: Record<string, AuditWorkbenchRow>;
  fullItemsByUrl?: Record<string, AuditRunItem | undefined>;
  selectedColumns?: AuditExportColumnKey[];
};

export async function exportAuditWorkbenchToExcel(input: ExportAuditWorkbenchInput) {
  const XLSX = await import("xlsx");
  const urls = input.urls.filter((url) => url.trim() !== "");
  const selectedColumns = (input.selectedColumns?.length ? input.selectedColumns : DEFAULT_AUDIT_EXPORT_COLUMN_KEYS)
    .filter((key, index, all) => all.indexOf(key) === index);

  if (urls.length === 0) {
    throw new Error("Không có URL nào để xuất Excel.");
  }

  if (selectedColumns.length === 0) {
    throw new Error("Chọn ít nhất một trường để xuất Excel.");
  }

  const rows = urls.map((url, index) => {
    const merged = input.rowsByUrl[url] ?? { targetUrl: url };
    const enriched = enrichWorkbenchRowForExport(merged, input.fullItemsByUrl?.[url]);
    return buildAuditExportRowForColumns(index, { ...enriched, targetUrl: url }, selectedColumns);
  });

  const selectedFieldDefinitions = AUDIT_EXPORT_FIELD_DEFINITIONS.filter((field) => selectedColumns.includes(field.key));
  const headers = selectedFieldDefinitions.map((field) => field.header);

  const worksheet = XLSX.utils.json_to_sheet(rows, {
    header: headers,
  });

  worksheet["!cols"] = selectedFieldDefinitions.map((field) => ({ wch: field.width }));

  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, "Audit Report");

  const filename = [
    "clickon-audit",
    sanitizeFilenamePart(input.websiteName || input.websiteId || "website"),
    input.runPublicId ? sanitizeFilenamePart(input.runPublicId) : "selected",
    `${urls.length}-urls`,
  ]
    .filter(Boolean)
    .join("-")
    .concat(".xlsx");

  XLSX.writeFile(workbook, filename);
}

export async function exportAuditRunToExcel(
  run: AuditRun,
  options?: {
    urls?: string[];
    rowsByUrl?: Record<string, AuditWorkbenchRow>;
    selectedColumns?: AuditExportColumnKey[];
  }
) {
  const items = [...(run.items ?? [])].sort((left, right) => left.position - right.position);
  const defaultUrls = items.map((item) => item.targetUrl);
  const urls = (options?.urls?.length ? options.urls : defaultUrls).filter((url) => url.trim() !== "");

  const rowsByUrl =
    options?.rowsByUrl ??
    Object.fromEntries(items.map((item) => [item.targetUrl, auditRunItemToWorkbenchRow(item)]));

  const fullItemsByUrl = Object.fromEntries(items.map((item) => [item.targetUrl, item]));

  await exportAuditWorkbenchToExcel({
    websiteName: run.websiteName,
    websiteId: run.websiteId,
    runPublicId: run.publicId,
    urls,
    rowsByUrl,
    fullItemsByUrl,
    selectedColumns: options?.selectedColumns,
  });
}
