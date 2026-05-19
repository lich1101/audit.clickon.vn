"use client";

import { RotateCcw, Save } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { laravelRequest } from "@/lib/laravel";
import type { AuditPromptTemplate } from "@/types";

const variables = [
  { token: "{{target_urls_json}}", description: "Toàn bộ URL đã chọn trong run, dạng JSON array." },
  { token: "{{target_urls_text}}", description: "Toàn bộ URL đã chọn, mỗi URL một dòng." },
  { token: "{{categories_json}}", description: "Danh sách tên danh mục và URL danh mục được phép chọn." },
  { token: "{{keyword_category_results_json}}", description: "Kết quả batch bước 2: URL, keyword chính, danh mục, URL danh mục." },
  { token: "{{checklist}}", description: "Checklist AuditSEO người dùng nhập hoặc mặc định backend." }
];

const sampleVariables: Record<string, unknown> = {
  target_urls_json: [
    "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
    "https://hoctienganhtaiphilippines.vn/khoa-hoc-tieng-anh-thuong-mai-tai-philippines/",
    "https://hoctienganhtaiphilippines.vn/hoc-bong-philinter-philippines/"
  ],
  target_urls_text: [
    "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
    "https://hoctienganhtaiphilippines.vn/khoa-hoc-tieng-anh-thuong-mai-tai-philippines/",
    "https://hoctienganhtaiphilippines.vn/hoc-bong-philinter-philippines/"
  ].join("\n"),
  categories_json: [
    { name: "Trường Anh ngữ IMS", url: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/" },
    { name: "Khóa ESL – Tiếng Anh giao tiếp", url: "https://hoctienganhtaiphilippines.vn/khoa-hoc-esl-tieng-anh-can-ban-tai-philippines-5179/" },
    { name: "Trường Anh ngữ Philinter", url: "https://hoctienganhtaiphilippines.vn/truong-philinter/" },
    { name: "Học bổng du học Philippines 2026", url: "https://hoctienganhtaiphilippines.vn/hoc-bong-du-hoc-philippines/" }
  ],
  keyword_category_results_json: [
    {
      targetUrl: "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
      primaryKeyword: "ưu đãi du học Philippines tại IMS",
      categoryName: "Trường Anh ngữ IMS",
      categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/",
      categoryMatchReason: "Slug nói trực tiếp về ưu đãi tại IMS, phù hợp nhất với silo trường IMS."
    },
    {
      targetUrl: "https://hoctienganhtaiphilippines.vn/hoc-bong-philinter-philippines/",
      primaryKeyword: "học bổng Philinter Philippines",
      categoryName: "Trường Anh ngữ Philinter",
      categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-philinter/",
      categoryMatchReason: "Slug nói trực tiếp về học bổng Philinter."
    }
  ],
  checklist: "Điểm kỹ thuật SEO: 18/24 | Điểm nội dung: 4/6 | Hướng: Audit Content — xem checklist chuẩn Clickon (25 tiêu chí, tổng 30đ) trong deploy/checklist hoặc resources/audit/seo-checklist.txt"
};

function stringifyPromptValue(value: unknown) {
  if (typeof value === "string") {
    return value;
  }

  return JSON.stringify(value, null, 2);
}

function mergePrompt(prompt: string) {
  return Object.entries(sampleVariables).reduce(
    (current, [key, value]) => current.replaceAll(`{{${key}}}`, stringifyPromptValue(value)),
    prompt
  );
}

function normalizeTemplate(template: AuditPromptTemplate): AuditPromptTemplate {
  return {
    ...template,
    systemPrompt: template.systemPrompt ?? template.developerPrompt
  };
}

export default function AdminAuditPromptsPage() {
  const [templates, setTemplates] = useState<AuditPromptTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [savingStep, setSavingStep] = useState<string | null>(null);

  const stepHints = useMemo(
    () =>
      new Map([
        ["primary_keyword", "Legacy: không dùng trong batch flow hiện tại."],
        ["category_mapping", "Legacy: không dùng trong batch flow hiện tại."],
        ["keyword_category_mapping", "Bước 2 chạy một lần cho toàn bộ URL đã chọn: dùng URL + danh mục để trả keyword chính và danh mục cho từng dòng."],
        ["onpage_audit", "Bước 3 chạy một lần cho toàn bộ URL đã chọn: dùng kết quả bước 2 + checklist để trả điểm, đề xuất và định hướng từng dòng."]
      ]),
    []
  );

  async function loadTemplates() {
    const payload = await laravelRequest<{ data: AuditPromptTemplate[] }>("/api/admin/audit-prompt-templates");
    setTemplates(payload.data.map(normalizeTemplate));
  }

  useEffect(() => {
    void loadTemplates()
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải audit prompts."))
      .finally(() => setLoading(false));
  }, []);

  function updateTemplate(step: string, patch: Partial<AuditPromptTemplate>) {
    setTemplates((current) => current.map((template) => (template.step === step ? { ...template, ...patch } : template)));
  }

  async function saveTemplate(template: AuditPromptTemplate) {
    try {
      setSavingStep(template.step);
      const payload = await laravelRequest<{ data: AuditPromptTemplate }>(`/api/admin/audit-prompt-templates/${template.step}`, {
        method: "PUT",
        body: JSON.stringify({
          title: template.title,
          systemPrompt: template.systemPrompt,
          developerPrompt: template.developerPrompt,
          userPrompt: template.userPrompt,
          isActive: template.isActive
        })
      });
      updateTemplate(template.step, normalizeTemplate(payload.data));
      toast.success("Prompt đã được cập nhật.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu prompt.");
    } finally {
      setSavingStep(null);
    }
  }

  async function resetTemplate(step: string) {
    try {
      setSavingStep(step);
      const payload = await laravelRequest<{ data: AuditPromptTemplate }>(`/api/admin/audit-prompt-templates/${step}/reset`, {
        method: "POST",
        body: JSON.stringify({})
      });
      updateTemplate(step, normalizeTemplate(payload.data));
      toast.success("Prompt đã được reset về mặc định.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể reset prompt.");
    } finally {
      setSavingStep(null);
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải audit prompts..." description="Đang lấy cấu hình prompt từng bước từ Laravel API." />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Audit prompts"
        description="Cấu hình prompt admin cho batch AI: bước 2 gom tất cả URL đã chọn, bước 3 audit tất cả URL đã chọn."
        breadcrumbs={[{ label: "Admin", href: "/admin" }, { label: "Audit Prompts" }]}
      />

      <Card>
        <CardHeader>
          <CardTitle>Biến có thể dùng trong prompt</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-wrap gap-2">
            {variables.map((variable) => (
              <div key={variable.token} className="rounded-xl border border-border bg-secondary/60 px-3 py-2">
                <code className="text-sm">{variable.token}</code>
                <p className="mt-1 max-w-[280px] text-xs text-muted-foreground">{variable.description}</p>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {templates.length ? (
        <div className="grid gap-5">
          {templates.map((template) => (
            <Card key={template.step}>
              <CardHeader className="gap-2">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <div>
                    <CardTitle>{template.title}</CardTitle>
                    <p className="mt-2 text-sm text-muted-foreground">{stepHints.get(template.step)}</p>
                    <p className="mt-2 text-xs uppercase tracking-[0.18em] text-muted-foreground">
                      {template.step} · {template.isDefault ? "default" : "custom"}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      disabled={savingStep === template.step}
                      onClick={() => void resetTemplate(template.step)}
                    >
                      <RotateCcw className="size-4" />
                      Reset
                    </Button>
                    <Button type="button" disabled={savingStep === template.step} onClick={() => void saveTemplate(template)}>
                      <Save className="size-4" />
                      {savingStep === template.step ? "Đang lưu..." : "Lưu prompt"}
                    </Button>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="grid gap-5">
                <div className="grid gap-2">
                  <Label htmlFor={`${template.step}-title`}>Tên bước</Label>
                  <Input
                    id={`${template.step}-title`}
                    value={template.title}
                    onChange={(event) => updateTemplate(template.step, { title: event.target.value })}
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor={`${template.step}-developer`}>System prompt</Label>
                  <Textarea
                    id={`${template.step}-developer`}
                    className="min-h-48 font-mono text-sm"
                    value={template.systemPrompt}
                    onChange={(event) => updateTemplate(template.step, { systemPrompt: event.target.value, developerPrompt: event.target.value })}
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor={`${template.step}-user`}>User prompt template</Label>
                  <Textarea
                    id={`${template.step}-user`}
                    className="min-h-56 font-mono text-sm"
                    value={template.userPrompt}
                    onChange={(event) => updateTemplate(template.step, { userPrompt: event.target.value })}
                  />
                </div>
                <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4 lg:grid-cols-2">
                  <div>
                    <p className="text-sm font-medium">System prompt sau khi merge mẫu</p>
                    <pre className="mt-3 max-h-80 overflow-auto rounded-xl border border-border bg-background/80 p-4 text-xs leading-5">
                      {mergePrompt(template.systemPrompt)}
                    </pre>
                  </div>
                  <div>
                    <p className="text-sm font-medium">User prompt sau khi merge mẫu</p>
                    <pre className="mt-3 max-h-80 overflow-auto rounded-xl border border-border bg-background/80 p-4 text-xs leading-5">
                      {mergePrompt(template.userPrompt)}
                    </pre>
                  </div>
                </div>
                <label className="flex items-center gap-3 rounded-xl border border-border bg-secondary/40 px-4 py-3 text-sm">
                  <input
                    type="checkbox"
                    checked={template.isActive}
                    onChange={(event) => updateTemplate(template.step, { isActive: event.target.checked })}
                  />
                  Dùng prompt custom này khi chạy audit. Nếu tắt, backend dùng prompt mặc định.
                </label>
              </CardContent>
            </Card>
          ))}
        </div>
      ) : (
        <EmptyState title="Chưa có prompt template" description="Laravel API chưa trả về cấu hình prompt audit." />
      )}
    </div>
  );
}
