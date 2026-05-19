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
  { token: "{{url}}", description: "URL mục tiêu của dòng đang chạy." },
  { token: "{{page_json}}", description: "JSON đã crawl của bài viết: title, meta, headings, metrics, content excerpt." },
  { token: "{{article_content}}", description: "Text nội dung bài viết đã extract bằng Jina/HTML." },
  { token: "{{category_contexts_json}}", description: "Danh sách danh mục kèm nội dung danh mục đã crawl." },
  { token: "{{categories_json}}", description: "Danh sách tên danh mục và URL danh mục." },
  { token: "{{primary_keyword}}", description: "Từ khóa SEO chính đã tạo ở bước 2." },
  { token: "{{category_json}}", description: "Danh mục đã gán cho URL ở bước 2." },
  { token: "{{checklist}}", description: "Checklist AuditSEO người dùng nhập hoặc mặc định backend." }
];

const sampleVariables: Record<string, unknown> = {
  url: "https://hoctienganhtaiphilippines.vn/hoc-bong-philinter-philippines/",
  page_json: {
    url: "https://hoctienganhtaiphilippines.vn/hoc-bong-philinter-philippines/",
    title: "Học bổng Philinter Philippines",
    metaDescription: "Thông tin học bổng và ưu đãi khi học tiếng Anh tại Philinter Cebu.",
    headings: { h1: ["Học bổng Philinter Philippines"], h2: ["Điều kiện nhận học bổng", "Khóa học phù hợp"] },
    metrics: { wordCount: 1260, imageCount: 8, h1Count: 1 },
    source: "jina",
    contentExcerpt: "Nội dung bài viết đã crawl..."
  },
  article_content: "Nội dung bài viết đã crawl từ URL mục tiêu, bao gồm tiêu đề, đoạn mô tả và phần thân bài...",
  category_contexts_json: [
    {
      name: "Trường Anh ngữ Philinter",
      url: "https://hoctienganhtaiphilippines.vn/truong-philinter/",
      title: "Trường Anh ngữ Philinter Cebu",
      contentExcerpt: "Nội dung danh mục Philinter đã crawl..."
    },
    {
      name: "Học bổng du học Philippines 2026",
      url: "https://hoctienganhtaiphilippines.vn/hoc-bong-du-hoc-philippines/",
      title: "Học bổng du học Philippines 2026",
      contentExcerpt: "Danh sách ưu đãi và học bổng du học Philippines..."
    }
  ],
  categories_json: [
    { name: "Trường Anh ngữ Philinter", url: "https://hoctienganhtaiphilippines.vn/truong-philinter/" },
    { name: "Học bổng du học Philippines 2026", url: "https://hoctienganhtaiphilippines.vn/hoc-bong-du-hoc-philippines/" }
  ],
  primary_keyword: "học bổng Philinter Philippines",
  category_json: {
    categoryName: "Trường Anh ngữ Philinter",
    categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-philinter/",
    categoryMatchReason: "URL nói trực tiếp về học bổng của Philinter, phù hợp nhất với silo trường Philinter."
  },
  checklist: "Title chứa keyword chính; Meta description rõ intent; H1/H2 logic; Nội dung cập nhật năm 2026; Có FAQ và internal link."
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
        ["primary_keyword", "Chạy đầu tiên cho từng URL để chọn đúng một từ khóa SEO chính."],
        ["category_mapping", "Chạy sau khi đã có từ khóa chính để gán đúng một danh mục phù hợp nhất."],
        ["keyword_category_mapping", "Bước 2 chạy cho từng URL: đọc bài viết + danh mục đã crawl để trả keyword chính và danh mục phù hợp."],
        ["onpage_audit", "Chạy cuối cùng để chấm điểm, tạo findings, recommendations và định hướng sửa nội dung."]
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
        description="Cấu hình prompt admin cho từng bước AI khi xử lý từng dòng URL trong một audit run."
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
