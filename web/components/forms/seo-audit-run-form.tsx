"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { BrainCircuit, FileUp, Play, Sparkles } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { parseCategoryFile, parseChecklistFile, parseUrlFile } from "@/lib/audit-files";
import { createAuditRun, formatCategoriesInput } from "@/lib/audit-runs";
import { auditRunSchema, parseArticleUrls, parseCategories, type AuditRunValues } from "@/lib/validators";
import type { AuditCategory } from "@/types";

function countNonEmptyLines(input: string) {
  return input
    .split(/\r\n|\r|\n/g)
    .map((line) => line.trim())
    .filter(Boolean).length;
}

const providerDescriptions = {
  openai: "OpenAI Responses API, phù hợp output JSON ổn định và tốc độ cân bằng.",
  gemini: "Gemini generateContent, trả JSON có schema cho từng URL.",
  gemini_deep_research: "Gemini Deep Research qua Interactions API, chậm hơn và phù hợp khi cần nghiên cứu sâu."
} as const;

export function SeoAuditRunForm({
  websiteId,
  websiteName,
  websiteUrl,
  defaultArticleUrls,
  defaultCategories
}: {
  websiteId: string;
  websiteName: string;
  websiteUrl: string;
  defaultArticleUrls?: string[];
  defaultCategories?: AuditCategory[];
}) {
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const urlInputRef = useRef<HTMLInputElement>(null);
  const categoryInputRef = useRef<HTMLInputElement>(null);
  const checklistInputRef = useRef<HTMLInputElement>(null);
  const form = useForm<AuditRunValues>({
    resolver: zodResolver(auditRunSchema),
    defaultValues: {
      targetUrlsInput: defaultArticleUrls?.join("\n") ?? "",
      categoriesInput: defaultCategories?.length ? formatCategoriesInput(defaultCategories) : "",
      checklistText: "",
      aiProvider: "openai",
      aiModel: ""
    }
  });

  useEffect(() => {
    form.reset({
      targetUrlsInput: defaultArticleUrls?.join("\n") ?? "",
      categoriesInput: defaultCategories?.length ? formatCategoriesInput(defaultCategories) : "",
      checklistText: form.getValues("checklistText"),
      aiProvider: form.getValues("aiProvider"),
      aiModel: form.getValues("aiModel")
    });
  }, [defaultArticleUrls, defaultCategories, form]);

  const targetUrlsInput = form.watch("targetUrlsInput");
  const categoriesInput = form.watch("categoriesInput");
  const checklistText = form.watch("checklistText");
  const aiProvider = form.watch("aiProvider");
  const targetUrlCount = countNonEmptyLines(targetUrlsInput);
  const categoryCount = countNonEmptyLines(categoriesInput);
  const checklistLineCount = countNonEmptyLines(checklistText);

  async function importUrlFile(file: File) {
    const urls = await parseUrlFile(file);
    form.setValue("targetUrlsInput", urls.join("\n"), { shouldDirty: true, shouldValidate: true });
    toast.success(`Đã nạp ${urls.length} URL từ file.`);
  }

  async function importCategoryFile(file: File) {
    const categories = await parseCategoryFile(file);
    form.setValue("categoriesInput", formatCategoriesInput(categories), {
      shouldDirty: true,
      shouldValidate: true
    });
    toast.success(`Đã nạp ${categories.length} danh mục từ file.`);
  }

  async function importChecklist(file: File) {
    const text = await parseChecklistFile(file);
    form.setValue("checklistText", text, { shouldDirty: true, shouldValidate: true });
    toast.success("Đã nạp checklist audit.");
  }

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);

      // Parse on client first to surface errors before hitting the queue API.
      parseArticleUrls(values.targetUrlsInput);
      parseCategories(values.categoriesInput);

      const response = await createAuditRun({
        websiteId,
        websiteName,
        websiteUrl,
        targetUrlsInput: values.targetUrlsInput,
        categoriesInput: values.categoriesInput,
        checklistText: values.checklistText,
        aiProvider: values.aiProvider,
        aiModel: values.aiModel
      });

      toast.success("Audit run đã được đưa vào hàng đợi.");
      router.push(`/websites/${websiteId}/audit/runs/${response.data.publicId}`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tạo audit run.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="overflow-hidden">
      <CardHeader className="border-b border-border/70">
        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div className="space-y-2">
            <CardTitle className="flex items-center gap-2">
              <Sparkles className="size-5 text-primary" />
              Chạy SEO audit theo lô
            </CardTitle>
            <CardDescription>
              Tạo một đợt audit mới từ danh sách URL mục tiêu, danh mục chuẩn và checklist Onpage SEO. Hệ thống crawl bằng Jina/HTML, chạy AI từng dòng và cập nhật realtime.
            </CardDescription>
          </div>
          <div className="grid min-w-[240px] grid-cols-3 gap-3 text-center">
            <div className="rounded-xl border border-border/70 bg-background/70 px-3 py-4">
              <p className="text-2xl font-semibold">{targetUrlCount}</p>
              <p className="mt-1 text-xs uppercase tracking-[0.18em] text-muted-foreground">Target URLs</p>
            </div>
            <div className="rounded-xl border border-border/70 bg-background/70 px-3 py-4">
              <p className="text-2xl font-semibold">{categoryCount}</p>
              <p className="mt-1 text-xs uppercase tracking-[0.18em] text-muted-foreground">Categories</p>
            </div>
            <div className="rounded-xl border border-border/70 bg-background/70 px-3 py-4">
              <p className="text-2xl font-semibold">{checklistLineCount}</p>
              <p className="mt-1 text-xs uppercase tracking-[0.18em] text-muted-foreground">Checklist</p>
            </div>
          </div>
        </div>
      </CardHeader>
      <CardContent className="pt-6">
        <form className="flex flex-col gap-6" onSubmit={onSubmit}>
          <div className="grid gap-4 rounded-[22px] border border-border/70 bg-secondary/25 p-5 lg:grid-cols-[0.8fr_1.2fr]">
            <div className="flex items-start gap-3">
              <div className="flex size-11 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                <BrainCircuit className="size-5" />
              </div>
              <div>
                <p className="font-medium">AI provider cho audit run này</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  Provider được lưu theo từng run. Admin có thể chỉnh system prompt/user prompt ở trang Audit Prompts mà không ảnh hưởng kết quả cũ.
                </p>
              </div>
            </div>
            <div className="grid gap-4 md:grid-cols-[0.8fr_1fr]">
              <div className="flex flex-col gap-2">
                <Label htmlFor="aiProvider">Provider</Label>
                <Select
                  value={aiProvider}
                  onValueChange={(value) => form.setValue("aiProvider", value as AuditRunValues["aiProvider"], { shouldDirty: true })}
                >
                  <SelectTrigger id="aiProvider">
                    <SelectValue placeholder="Chọn provider" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="openai">OpenAI</SelectItem>
                    <SelectItem value="gemini">Gemini</SelectItem>
                    <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">{providerDescriptions[aiProvider]}</p>
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="aiModel">Model / Agent override</Label>
                <Input
                  id="aiModel"
                  placeholder={
                    aiProvider === "gemini_deep_research"
                      ? "deep-research-preview-04-2026"
                      : aiProvider === "gemini"
                        ? "gemini-2.5-pro"
                        : "gpt-5.5"
                  }
                  {...form.register("aiModel")}
                />
                <p className="text-xs text-muted-foreground">Để trống để dùng model mặc định trong file env backend.</p>
              </div>
            </div>
          </div>

          <div className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <div className="flex flex-col gap-2">
              <div className="flex items-center justify-between gap-3">
                <Label htmlFor="targetUrlsInput">Danh sách URL mục tiêu</Label>
                <Button size="sm" type="button" variant="outline" onClick={() => urlInputRef.current?.click()}>
                  <FileUp className="size-4" />
                  Nạp file URL
                </Button>
              </div>
              <Textarea
                id="targetUrlsInput"
                rows={14}
                placeholder={"https://example.com/bai-viet-1\nhttps://example.com/bai-viet-2"}
                {...form.register("targetUrlsInput")}
              />
              <input
                ref={urlInputRef}
                accept=".txt,.csv,.xlsx,.xls"
                className="hidden"
                type="file"
                onChange={async (event) => {
                  const file = event.target.files?.[0];
                  event.currentTarget.value = "";

                  if (!file) {
                    return;
                  }

                  try {
                    await importUrlFile(file);
                  } catch (error) {
                    toast.error(error instanceof Error ? error.message : "Không thể đọc file URL.");
                  }
                }}
              />
              <p className="text-sm text-muted-foreground">Hỗ trợ paste trực tiếp hoặc tải lên file `.txt`, `.csv`, `.xlsx` chứa danh sách URL.</p>
              {form.formState.errors.targetUrlsInput ? (
                <p className="text-sm text-destructive">{form.formState.errors.targetUrlsInput.message}</p>
              ) : null}
            </div>

            <div className="flex flex-col gap-2">
              <div className="flex items-center justify-between gap-3">
                <Label htmlFor="categoriesInput">Danh mục chuẩn</Label>
                <Button size="sm" type="button" variant="outline" onClick={() => categoryInputRef.current?.click()}>
                  <FileUp className="size-4" />
                  Nạp file danh mục
                </Button>
              </div>
              <Textarea
                id="categoriesInput"
                rows={14}
                placeholder={"Tin tức-https://example.com/tin-tuc\nSức khỏe-https://example.com/suc-khoe"}
                {...form.register("categoriesInput")}
              />
              <input
                ref={categoryInputRef}
                accept=".txt,.csv,.xlsx,.xls"
                className="hidden"
                type="file"
                onChange={async (event) => {
                  const file = event.target.files?.[0];
                  event.currentTarget.value = "";

                  if (!file) {
                    return;
                  }

                  try {
                    await importCategoryFile(file);
                  } catch (error) {
                    toast.error(error instanceof Error ? error.message : "Không thể đọc file danh mục.");
                  }
                }}
              />
              <p className="text-sm text-muted-foreground">Mỗi dòng có thể dùng `Tên danh mục URL`, tab, hoặc `Tên danh mục-URL`. Với file bảng tính, dùng hai cột `name` và `url`.</p>
              {form.formState.errors.categoriesInput ? (
                <p className="text-sm text-destructive">{form.formState.errors.categoriesInput.message}</p>
              ) : null}
            </div>
          </div>

          <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3">
              <Label htmlFor="checklistText">Checklist AuditSEO</Label>
              <Button size="sm" type="button" variant="outline" onClick={() => checklistInputRef.current?.click()}>
                <FileUp className="size-4" />
                Nạp checklist
              </Button>
            </div>
            <Textarea
              id="checklistText"
              rows={8}
              placeholder="Dán checklist audit onpage hoặc tải file từ máy. Nếu để trống, hệ thống sẽ dùng checklist mặc định."
              {...form.register("checklistText")}
            />
            <input
              ref={checklistInputRef}
              accept=".txt,.csv,.xlsx,.xls"
              className="hidden"
              type="file"
              onChange={async (event) => {
                const file = event.target.files?.[0];
                event.currentTarget.value = "";

                if (!file) {
                  return;
                }

                try {
                  await importChecklist(file);
                } catch (error) {
                  toast.error(error instanceof Error ? error.message : "Không thể đọc file checklist.");
                }
              }}
            />
            <p className="text-sm text-muted-foreground">Checklist này sẽ được đưa vào prompt để AI chấm điểm và đề xuất chỉnh sửa theo đúng chuẩn bạn cung cấp.</p>
          </div>

          <div className="flex flex-col gap-4 rounded-[22px] border border-border/70 bg-secondary/35 p-5">
            <p className="text-sm font-medium">Nguồn dữ liệu hiện tại</p>
            <div className="grid gap-3 text-sm text-muted-foreground md:grid-cols-3">
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-3">
                Website: <span className="font-medium text-foreground">{websiteName}</span>
              </div>
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-3">
                URL gốc: <span className="font-medium text-foreground">{websiteUrl}</span>
              </div>
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-3">
                Luồng xử lý: <span className="font-medium text-foreground">Fetch → AI Analyze → Realtime → Excel</span>
              </div>
            </div>
          </div>

          <div className="flex items-center justify-end gap-3">
            <Button
              type="button"
              variant="outline"
              onClick={() =>
                form.reset({
                  targetUrlsInput: defaultArticleUrls?.join("\n") ?? "",
                  categoriesInput: defaultCategories?.length ? formatCategoriesInput(defaultCategories) : "",
                  checklistText: "",
                  aiProvider: "openai",
                  aiModel: ""
                })
              }
            >
              Đặt lại
            </Button>
            <Button disabled={submitting} type="submit">
              <Play className="size-4" />
              {submitting ? "Đang tạo audit run..." : "Bắt đầu audit"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
