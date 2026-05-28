import { parseArticleUrls, parseCategories, formatCategoryLine } from "@/lib/validators";
import type { AuditCategory } from "@/types";

function normalizeHeaderToken(value: string) {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function splitLines(input: string) {
  return input
    .split(/\r\n|\r|\n/g)
    .map((line) => line.trim())
    .filter(Boolean);
}

function uniqueStrings(values: string[]) {
  return Array.from(new Set(values.map((value) => value.trim()).filter(Boolean)));
}

async function readSpreadsheetRows(file: File) {
  const XLSX = await import("xlsx");
  const workbook = XLSX.read(await file.arrayBuffer(), { type: "array" });
  const firstSheetName = workbook.SheetNames[0];

  if (!firstSheetName) {
    return [] as string[][];
  }

  return XLSX.utils.sheet_to_json<string[]>(workbook.Sheets[firstSheetName], {
    header: 1,
    blankrows: false,
    raw: false
  });
}

function fileLooksLikeSpreadsheet(file: File) {
  const lowerName = file.name.toLowerCase();
  return lowerName.endsWith(".xlsx") || lowerName.endsWith(".xls") || file.type.includes("sheet");
}

function isUrlHeader(value: string) {
  return new Set([
    "url",
    "target url",
    "target_url",
    "article url",
    "article_url",
    "url bai viet",
    "url muc tieu",
  ]).has(normalizeHeaderToken(value));
}

function isCategoryNameHeader(value: string) {
  return new Set([
    "name",
    "category name",
    "category_name",
    "ten danh muc",
    "danh muc",
  ]).has(normalizeHeaderToken(value));
}

function isCategoryUrlHeader(value: string) {
  return new Set([
    "url",
    "category url",
    "category_url",
    "url danh muc",
  ]).has(normalizeHeaderToken(value));
}

function isChecklistHeader(value: string) {
  return new Set([
    "checklist",
    "checklist item",
    "checklist_item",
    "noi dung checklist",
    "checklist seo",
  ]).has(normalizeHeaderToken(value));
}

export async function parseUrlFile(file: File) {
  const lines = fileLooksLikeSpreadsheet(file)
    ? (await readSpreadsheetRows(file)).flatMap((row) => {
        const firstCell = String(row.find((cell) => String(cell ?? "").trim()) ?? "").trim();

        if (!firstCell || isUrlHeader(firstCell)) {
          return [];
        }

        return [firstCell];
      })
    : splitLines(await file.text());

  return parseArticleUrls(uniqueStrings(lines).join("\n"));
}

export async function parseCategoryFile(file: File): Promise<AuditCategory[]> {
  if (!fileLooksLikeSpreadsheet(file)) {
    return parseCategories(splitLines(await file.text()).join("\n"));
  }

  const rows = await readSpreadsheetRows(file);
  const categories: AuditCategory[] = [];
  let invalidRowCount = 0;

  for (const row of rows) {
    const first = String(row[0] ?? "").trim();
    const second = String(row[1] ?? "").trim();

    if (!first && !second) {
      continue;
    }

    if (isCategoryNameHeader(first) && isCategoryUrlHeader(second)) {
      continue;
    }

    if (!first || !second) {
      invalidRowCount += 1;
      continue;
    }

    categories.push(...parseCategories(formatCategoryLine(first, second)));
  }

  if (categories.length === 0) {
    throw new Error("File danh mục Excel phải có 2 cột: Tên danh mục và URL danh mục.");
  }

  if (invalidRowCount > 0) {
    throw new Error("File danh mục Excel có dòng thiếu Tên danh mục hoặc URL danh mục.");
  }

  return categories;
}

export async function parseChecklistFile(file: File) {
  const lines = fileLooksLikeSpreadsheet(file)
    ? (await readSpreadsheetRows(file)).flatMap((row) => {
        const firstCell = String(row.find((cell) => String(cell ?? "").trim()) ?? "").trim();

        if (!firstCell || isChecklistHeader(firstCell)) {
          return [];
        }

        return [firstCell];
      })
    : splitLines(await file.text());

  return uniqueStrings(lines).join("\n");
}

async function writeTemplateFile(filename: string, rows: string[][]) {
  const XLSX = await import("xlsx");
  const workbook = XLSX.utils.book_new();
  const worksheet = XLSX.utils.aoa_to_sheet(rows);

  XLSX.utils.book_append_sheet(workbook, worksheet, "Template");
  XLSX.writeFile(workbook, filename);
}

export async function downloadUrlTemplateFile() {
  await writeTemplateFile("mau-url-bai-viet-audit.xlsx", [
    ["URL bài viết"],
    ["https://example.com/bai-viet-1"],
    ["https://example.com/bai-viet-2"],
    ["https://example.com/bai-viet-3"],
  ]);
}

export async function downloadCategoryTemplateFile() {
  await writeTemplateFile("mau-danh-muc-audit.xlsx", [
    ["Tên danh mục", "URL danh mục"],
    ["Danh mục 1", "https://example.com/danh-muc-1"],
    ["Danh mục 2", "https://example.com/danh-muc-2"],
    ["Danh mục 3", "https://example.com/danh-muc-3"],
  ]);
}

export async function downloadChecklistTemplateFile() {
  await writeTemplateFile("mau-checklist-audit.xlsx", [
    ["Nội dung checklist"],
    ["STT 1 - Title có chứa keyword chính"],
    ["STT 2 - Meta description rõ ràng và đúng intent"],
    ["STT 3 - Có internal link liên quan"],
  ]);
}
