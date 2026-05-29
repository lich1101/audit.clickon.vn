"use client";

import {
  AUDIT_EXPORT_COLUMNS,
  auditRunItemToWorkbenchRow,
  buildAuditExportRow,
  enrichWorkbenchRowForExport,
  type AuditWorkbenchRow,
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
};

export async function exportAuditWorkbenchToExcel(input: ExportAuditWorkbenchInput) {
  const XLSX = await import("xlsx");
  const urls = input.urls.filter((url) => url.trim() !== "");

  if (urls.length === 0) {
    throw new Error("Không có URL nào để xuất Excel.");
  }

  const rows = urls.map((url, index) => {
    const merged = input.rowsByUrl[url] ?? { targetUrl: url };
    const enriched = enrichWorkbenchRowForExport(merged, input.fullItemsByUrl?.[url]);
    return buildAuditExportRow(index, { ...enriched, targetUrl: url });
  });

  const worksheet = XLSX.utils.json_to_sheet(rows, {
    header: [...AUDIT_EXPORT_COLUMNS],
  });

  worksheet["!cols"] = [
    { wch: 6 },
    { wch: 42 },
    { wch: 34 },
    { wch: 34 },
    { wch: 12 },
    { wch: 48 },
    { wch: 28 },
    { wch: 12 },
    { wch: 24 },
    { wch: 28 },
    { wch: 24 },
    { wch: 34 },
    { wch: 28 },
    { wch: 10 },
    { wch: 42 },
    { wch: 42 },
    { wch: 42 },
    { wch: 28 },
    { wch: 34 },
    { wch: 28 },
    { wch: 28 },
    { wch: 28 },
  ];

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
  });
}
