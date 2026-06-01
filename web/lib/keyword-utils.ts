function normalizeKeywordForCompare(keyword: string): string {
  return keyword
    .trim()
    .replace(/\s+/g, " ")
    .toLocaleLowerCase("vi-VN");
}

function normalizeKeywordDisplay(keyword: string): string {
  return keyword.trim().replace(/\s+/g, " ");
}

/**
 * Loại bỏ keyword trùng (sau trim + gộp khoảng trắng, không phân biệt hoa thường).
 * Giữ bản ghi xuất hiện đầu tiên.
 */
export function dedupeKeywords(keywords: string[]): string[] {
  const seen = new Set<string>();
  const result: string[] = [];

  for (const raw of keywords) {
    const display = normalizeKeywordDisplay(raw);

    if (!display) {
      continue;
    }

    const key = normalizeKeywordForCompare(display);

    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    result.push(display);
  }

  return result;
}

export function dedupeKeywordsFromText(text: string): string[] {
  return dedupeKeywords(text.split(/\r?\n/g));
}

export function countDuplicateKeywords(keywords: string[]): number {
  const lines = keywords.map((line) => normalizeKeywordDisplay(line)).filter(Boolean);

  return Math.max(0, lines.length - dedupeKeywords(keywords).length);
}
