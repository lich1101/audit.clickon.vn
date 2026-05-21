import { z } from "zod";

const trimmedString = z
  .string()
  .trim()
  .min(1, "Trường này là bắt buộc.");

export const loginSchema = z.object({
  email: z.string().email("Email không hợp lệ."),
  password: z.string().min(6, "Mật khẩu tối thiểu 6 ký tự.")
});

export const registerSchema = loginSchema.extend({
  displayName: trimmedString.min(2, "Tên hiển thị tối thiểu 2 ký tự.")
});

export const websiteSchema = z.object({
  name: trimmedString.min(2, "Tên website tối thiểu 2 ký tự."),
  url: z.string().trim().url("URL website không hợp lệ.")
});

export const createWebsiteSchema = websiteSchema.extend({
  articleUrlsInput: z.string().optional().default(""),
  categoriesInput: z.string().optional().default(""),
  checklistText: z.string().optional().default("")
});

export const auditFormSchema = z.object({
  articleUrlsInput: trimmedString,
  categoriesInput: trimmedString
});

export const auditRunSchema = z.object({
  targetUrlsInput: trimmedString,
  categoriesInput: trimmedString,
  checklistText: z.string().trim().optional().default("")
});

export const planSchema = z.object({
  name: trimmedString.min(2, "Tên gói cước tối thiểu 2 ký tự."),
  price: z.coerce.number().min(0, "Giá phải lớn hơn hoặc bằng 0."),
  credits: z.coerce.number().int().min(1, "Credit phải lớn hơn 0."),
  isActive: z.boolean()
});

export const creditMutationSchema = z.object({
  userId: trimmedString,
  amount: z.coerce.number().int().min(1, "Số credit phải lớn hơn 0."),
  reason: trimmedString.min(4, "Lý do tối thiểu 4 ký tự.")
});

export const billingRequestSchema = z.object({
  planId: trimmedString
});

export const settingsSchema = z.object({
  displayName: trimmedString.min(2, "Tên hiển thị tối thiểu 2 ký tự.")
});

export const sessionSchema = z.object({
  idToken: trimmedString
});

function isHttpUrl(value: string) {
  const url = value.trim();

  if (!url || /\s/u.test(url)) {
    return false;
  }

  try {
    const parsed = new URL(url);

    return ["http:", "https:"].includes(parsed.protocol) && parsed.hostname.length > 0;
  } catch {
    return false;
  }
}

export const parseArticleUrls = (input: string) => {
  const urls = input
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean);

  if (urls.length === 0) {
    throw new Error("Cần ít nhất một Article URL.");
  }

  urls.forEach((url, index) => {
    if (!isHttpUrl(url)) {
      throw new Error(`Article URL dòng ${index + 1} không hợp lệ: ${url}`);
    }
  });

  return urls;
};

export function formatCategoryLine(name: string, url: string) {
  return `\`${name.trim()}\` - \`${url.trim()}\``;
}

export function formatCategoriesInput(categories: Array<{ name: string; url: string }>) {
  return categories.map((category) => formatCategoryLine(category.name, category.url)).join("\n");
}

export const parseCategories = (input: string) => {
  const lines = input
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean);

  if (lines.length === 0) {
    throw new Error("Cần ít nhất một danh mục.");
  }

  return lines.map((line, index) => {
    const backtickMatch = line.match(/^`([^`]+)`\s*-\s*`([^`]+)`\s*$/u);

    if (backtickMatch) {
      const name = backtickMatch[1].trim();
      const url = backtickMatch[2].trim();

      if (!name) {
        throw new Error(`Tên danh mục trống ở dòng: ${line}`);
      }

      if (!isHttpUrl(url)) {
        throw new Error(`URL danh mục dòng ${index + 1} không hợp lệ: ${url}`);
      }

      return { name, url };
    }

    const tabParts = line.split("\t").map((part) => part.trim()).filter(Boolean);

    if (tabParts.length >= 2) {
      const url = tabParts[tabParts.length - 1];
      const name = tabParts.slice(0, -1).join(" ").trim();

      if (!name) {
        throw new Error(`Tên danh mục trống ở dòng: ${line}`);
      }

      if (!isHttpUrl(url)) {
        throw new Error(`URL danh mục dòng ${index + 1} không hợp lệ: ${url}`);
      }

      return { name, url };
    }

    const urlMatch = line.match(/(https?:\/\/\S+)$/i);

    if (!urlMatch?.[1]) {
      throw new Error(
        `Dòng danh mục không hợp lệ: ${line}. Dùng \`Tên danh mục\` - \`https://url-danh-muc\` mỗi dòng.`
      );
    }

    const url = urlMatch[1].trim();
    const name = line
      .slice(0, urlMatch.index)
      .replace(/[\t,\-–—:|]+$/u, "")
      .trim();

    if (!name) {
      throw new Error(`Tên danh mục trống ở dòng: ${line}`);
    }

    if (!isHttpUrl(url)) {
      throw new Error(`URL danh mục dòng ${index + 1} không hợp lệ: ${url}`);
    }

    return { name, url };
  });
};

export type LoginValues = z.infer<typeof loginSchema>;
export type RegisterValues = z.infer<typeof registerSchema>;
export type WebsiteValues = z.infer<typeof websiteSchema>;
export type CreateWebsiteValues = z.infer<typeof createWebsiteSchema>;
export type AuditFormValues = z.infer<typeof auditFormSchema>;
export type AuditRunValues = z.infer<typeof auditRunSchema>;
export type PlanValues = z.infer<typeof planSchema>;
export type CreditMutationValues = z.infer<typeof creditMutationSchema>;
export type SettingsValues = z.infer<typeof settingsSchema>;
