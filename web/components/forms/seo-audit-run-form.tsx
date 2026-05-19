"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { FileUp, Save, Sparkles } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { parseCategoryFile, parseChecklistFile } from "@/lib/audit-files";
import { formatCategoriesInput } from "@/lib/audit-runs";
import { saveWebsiteAudit } from "@/lib/firestore";
import { auditRunSchema, parseArticleUrls, parseCategories, type AuditRunValues } from "@/lib/validators";
import type { AuditCategory, WebsiteAudit } from "@/types";
import { AuditTargetUrlEditor, urlsToInput } from "@/components/forms/audit-target-url-editor";

function countNonEmptyLines(input: string) {
  return input
    .split(/\r\n|\r|\n/g)
    .map((line) => line.trim())
    .filter(Boolean).length;
}

export function SeoAuditRunForm({
  websiteId,
  userId,
  auditId,
  websiteName,
  websiteUrl,
  defaultArticleUrls,
  defaultCategories,
  defaultChecklistText,
  showSourceSummary = true,
  onSaved
}: {
  websiteId: string;
  userId: string;
  auditId?: string;
  websiteName: string;
  websiteUrl: string;
  defaultArticleUrls?: string[];
  defaultCategories?: AuditCategory[];
  defaultChecklistText?: string | null;
  showSourceSummary?: boolean;
  onSaved?: (audit: WebsiteAudit) => void;
}) {
  const [submitting, setSubmitting] = useState(false);
  const [urlList, setUrlList] = useState<string[]>(defaultArticleUrls ?? []);
  const [selectedUrls, setSelectedUrls] = useState<string[]>(defaultArticleUrls ?? []);
  const categoryInputRef = useRef<HTMLInputElement>(null);
  const checklistInputRef = useRef<HTMLInputElement>(null);
  const form = useForm<AuditRunValues>({
    resolver: zodResolver(auditRunSchema),
    defaultValues: {
      targetUrlsInput: defaultArticleUrls?.join("\n") ?? "",
      categoriesInput: defaultCategories?.length ? formatCategoriesInput(defaultCategories) : "",
      checklistText: defaultChecklistText ?? ""
    }
  });

  useEffect(() => {
    const urls = defaultArticleUrls ?? [];
    setUrlList(urls);
    setSelectedUrls(urls);
    form.reset({
      targetUrlsInput: urlsToInput(urls),
      categoriesInput: defaultCategories?.length ? formatCategoriesInput(defaultCategories) : "",
      checklistText: defaultChecklistText ?? ""
    });
  }, [defaultArticleUrls, defaultCategories, defaultChecklistText, form]);

  useEffect(() => {
    form.setValue("targetUrlsInput", urlsToInput(urlList), { shouldDirty: true, shouldValidate: true });
  }, [urlList, form]);

  const categoriesInput = form.watch("categoriesInput");
  const checklistText = form.watch("checklistText");
  const targetUrlCount = urlList.length;
  const categoryCount = countNonEmptyLines(categoriesInput);
  const checklistLineCount = countNonEmptyLines(checklistText);

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

      parseArticleUrls(values.targetUrlsInput);
      parseCategories(values.categoriesInput);

      const savedAudit = await saveWebsiteAudit({
        auditId,
        websiteId,
        userId,
        articleUrlsInput: values.targetUrlsInput,
        categoriesInput: values.categoriesInput,
        checklistText: values.checklistText
      });

      toast.success("Đã lưu cấu hình audit.");
      onSaved?.(savedAudit);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu cấu hình audit.");
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
              Cấu hình SEO audit
            </CardTitle>
            <CardDescription>
              Lưu URL mục tiêu, danh mục và checklist. Model AI do admin cấu hình trong phần quản trị hệ thống.
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
          <div className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <div className="flex flex-col gap-2">
              <Label>Danh sách URL mục tiêu</Label>
              <AuditTargetUrlEditor
                urls={urlList}
                onChange={setUrlList}
                selectedUrls={selectedUrls}
                onSelectedChange={setSelectedUrls}
              />
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
                placeholder={"`Học bổng du học Philippines 2026` - `https://hoctienganhtaiphilippines.vn/hoc-bong-du-hoc-philippines/`\n`Trường Anh ngữ Philinter` - `https://hoctienganhtaiphilippines.vn/truong-philinter/`"}
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
              <p className="text-sm text-muted-foreground">Mỗi dòng dùng `Tên danh mục` - `https://url-danh-muc`.</p>
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
          </div>

          {showSourceSummary ? (
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
                  Luồng: <span className="font-medium text-foreground">Lưu cấu hình → Run → Excel</span>
                </div>
              </div>
            </div>
          ) : null}

          <div className="flex items-center justify-end gap-3">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                const urls = defaultArticleUrls ?? [];
                setUrlList(urls);
                setSelectedUrls(urls);
                form.reset({
                  targetUrlsInput: urlsToInput(urls),
                  categoriesInput: defaultCategories?.length ? formatCategoriesInput(defaultCategories) : "",
                  checklistText: defaultChecklistText ?? ""
                });
              }}
            >
              Đặt lại
            </Button>
            <Button disabled={submitting} type="submit">
              <Save className="size-4" />
              {submitting ? "Đang lưu..." : "Lưu cấu hình"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
