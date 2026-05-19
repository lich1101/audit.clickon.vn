"use client";

import type { AuditRun } from "@/types";

function sanitizeFilenamePart(input: string) {
  return input
    .toLowerCase()
    .replace(/[^a-z0-9]+/gi, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

export async function exportAuditRunToExcel(run: AuditRun) {
  const XLSX = await import("xlsx");
  const rows = (run.items ?? []).map((item) => ({
    "URL mục tiêu": item.targetUrl,
    "Từ khóa SEO chính": item.primaryKeyword ?? "",
    "Danh mục": item.categoryName ?? "",
    "URL danh mục": item.categoryUrl ?? "",
    "Lý do chọn danh mục": item.categoryMatchReason ?? "",
    "Điểm phân tích Audit": item.auditScore ?? "",
    "Đề xuất audit": item.auditRecommendations.join(" | "),
    "Định hướng chỉnh sửa nội dung theo Audit": item.contentRevisionDirection ?? "",
    "Nhận định audit": item.auditFindings.join(" | "),
    "Trạng thái": item.status,
    "Nguồn crawl": item.extractionSource ?? "",
    "Tiêu đề trang": item.pageTitle ?? "",
    "Meta description": item.metaDescription ?? "",
    "Canonical": item.canonicalUrl ?? ""
  }));

  const worksheet = XLSX.utils.json_to_sheet(rows);
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, "Audit Report");

  const filename = [
    "clickon-audit",
    sanitizeFilenamePart(run.websiteName || run.websiteId || "website"),
    sanitizeFilenamePart(run.publicId)
  ]
    .filter(Boolean)
    .join("-")
    .concat(".xlsx");

  XLSX.writeFile(workbook, filename);
}
