import { parseArticleUrls, parseCategories } from "@/lib/validators";
import type { AuditCategory } from "@/types";

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

export async function parseUrlFile(file: File) {
  const lines = fileLooksLikeSpreadsheet(file)
    ? (await readSpreadsheetRows(file)).flatMap((row) => row.map((cell) => String(cell ?? "").trim()).filter(Boolean))
    : splitLines(await file.text());

  return parseArticleUrls(uniqueStrings(lines).join("\n"));
}

export async function parseCategoryFile(file: File): Promise<AuditCategory[]> {
  if (!fileLooksLikeSpreadsheet(file)) {
    return parseCategories(splitLines(await file.text()).join("\n"));
  }

  const rows = await readSpreadsheetRows(file);
  const categories: AuditCategory[] = [];

  for (const row of rows) {
    const cells = row.map((cell) => String(cell ?? "").trim()).filter(Boolean);

    if (cells.length === 0) {
      continue;
    }

    const [first = "", second = ""] = cells;

    if (first.toLowerCase() === "name" && second.toLowerCase() === "url") {
      continue;
    }

    if (cells.length >= 2) {
      categories.push(...parseCategories(`${first}-${second}`));
      continue;
    }

    categories.push(...parseCategories(first));
  }

  if (categories.length === 0) {
    throw new Error("File danh mục không có dữ liệu hợp lệ.");
  }

  return categories;
}

export async function parseChecklistFile(file: File) {
  const lines = fileLooksLikeSpreadsheet(file)
    ? (await readSpreadsheetRows(file)).flatMap((row) => row.map((cell) => String(cell ?? "").trim()).filter(Boolean))
    : splitLines(await file.text());

  return uniqueStrings(lines).join("\n");
}

