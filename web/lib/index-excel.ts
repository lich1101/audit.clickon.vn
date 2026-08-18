import * as XLSX from "xlsx";

import type { IndexProperty, IndexUrlRow } from "@/types";

export const XLSX_ACCEPT = ".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

export async function readUrlsFromXlsx(file: File): Promise<string> {
  const name = file.name.toLowerCase();
  if (!name.endsWith(".xlsx")) {
    throw new Error("Chỉ nhận file mẫu .xlsx. Tải mẫu rồi điền URL vào cột url.");
  }

  const buffer = await file.arrayBuffer();
  const workbook = XLSX.read(buffer, { type: "array" });
  const chunks: string[] = [];

  for (const sheetName of workbook.SheetNames) {
    const sheet = workbook.Sheets[sheetName];
    const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: "" }) as unknown[][];

    for (const row of rows) {
      for (const cell of row) {
        if (cell == null) {
          continue;
        }

        const text = String(cell).trim();
        if (!text || text.toLowerCase() === "url" || text.toLowerCase() === "ghi chú") {
          continue;
        }

        chunks.push(text);
      }
    }
  }

  return chunks.join("\n");
}

export function downloadIndexImportTemplate() {
  const workbook = XLSX.utils.book_new();
  const sheet = XLSX.utils.aoa_to_sheet([
    ["url"],
    ["https://example.com/bai-viet-1"],
    ["https://example.com/bai-viet-2"],
    ["https://example.com/san-pham-1"]
  ]);
  sheet["!cols"] = [{ wch: 80 }];
  XLSX.utils.book_append_sheet(workbook, sheet, "URLs");
  XLSX.writeFile(workbook, "mau-import-url-lap-chi-muc.xlsx");
}

function formatIndexTime(value?: string | null): string {
  if (!value) {
    return "";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", hour12: false });
}

function urlsFromRow(value?: string | null): string[] {
  const text = String(value ?? "").trim();
  if (!text) {
    return [];
  }

  const parts = text
    .split(/[\r\n\s,;]+/)
    .map((part) => part.trim())
    .filter((part) => /^https?:\/\//i.test(part));

  return parts.length > 0 ? parts : [text];
}

function sheetFromUrlRows(header: string[], rows: string[][]) {
  const sheet = XLSX.utils.aoa_to_sheet([header, ...rows]);
  sheet["!cols"] = header.map((_, index) => ({ wch: index === 0 ? 100 : 24 }));
  sheet["!rows"] = [{ hpt: 18 }, ...rows.map(() => ({ hpt: 18 }))];
  return sheet;
}

export function downloadIndexReport(property: IndexProperty, urls: IndexUrlRow[]) {
  const indexed = urls.filter((row) => row.status === "SENT");
  const pending = urls.filter((row) => row.status === "PENDING" || row.status === "SENDING");
  const failed = urls.filter((row) => row.status === "FAILED");

  const workbook = XLSX.utils.book_new();

  const indexedRows = indexed.flatMap((row) =>
    urlsFromRow(row.urlExact).map((url) => [url, formatIndexTime(row.sentAt)])
  );
  XLSX.utils.book_append_sheet(
    workbook,
    sheetFromUrlRows(["url", "thoi_gian_lap_chi_muc"], indexedRows),
    "Da lap chi muc"
  );

  const pendingRows = pending.flatMap((row) => urlsFromRow(row.urlExact).map((url) => [url]));
  XLSX.utils.book_append_sheet(workbook, sheetFromUrlRows(["url"], pendingRows), "Chua lap chi muc");

  if (failed.length) {
    const failedRows = failed.flatMap((row) =>
      urlsFromRow(row.urlExact).map((url) => [url, row.lastError ?? ""])
    );
    XLSX.utils.book_append_sheet(workbook, sheetFromUrlRows(["url", "loi"], failedRows), "Loi");
  }

  const fileName = `bao-cao-lap-chi-muc-${property.siteHost || property.code}.xlsx`;
  XLSX.writeFile(workbook, fileName);
}
