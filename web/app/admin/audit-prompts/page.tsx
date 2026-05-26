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
  { token: "{{website_url}}", description: "Domain/website gốc của run hiện tại." },
  { token: "{{url}}", description: "URL mục tiêu hiện tại khi flow chạy theo từng item." },
  { token: "{{target_urls_json}}", description: "Danh sách URL trong chunk AI hiện tại, dạng JSON array." },
  { token: "{{target_urls_text}}", description: "Danh sách URL trong chunk AI hiện tại, mỗi URL một dòng." },
  { token: "{{batch_pages_json}}", description: "Danh sách item trong chunk deep research step 3: targetUrl, page payload, article content và keyword/danh mục đã có từ bước 2." },
  { token: "{{categories_json}}", description: "Danh sách tên danh mục và URL danh mục được phép chọn." },
  { token: "{{category_contexts_json}}", description: "Ngữ cảnh danh mục đã crawl: title, meta, excerpt." },
  { token: "{{keyword_category_results_json}}", description: "Kết quả batch bước 2: URL, keyword chính, danh mục, URL danh mục." },
  { token: "{{research_items_json}}", description: "Kết quả research Perplexity cho từng URL trong chunk deep research." },
  { token: "{{category_json}}", description: "Danh mục hiện tại của đúng 1 URL: name, url, match reason." },
  { token: "{{page_json}}", description: "Payload crawl hiện tại: title, meta, heading, metrics, excerpt." },
  { token: "{{article_content}}", description: "Nội dung bài viết đã crawl được." },
  { token: "{{research_json}}", description: "JSON nghiên cứu SERP/đối thủ/intent/freshness từ Perplexity." },
  { token: "{{site_urls_json}}", description: "Danh sách URL cùng website để kiểm tra duplicate/cannibalization." },
  { token: "{{primary_keyword}}", description: "Keyword SEO chính đã xác định cho đúng 1 URL." },
  { token: "{{primary_keyword_seed}}", description: "Keyword đầu vào của step 3 mới, thường lấy từ kết quả bước 2." },
  { token: "{{category_name_seed}}", description: "Tên danh mục đầu vào của step 3 mới, thường lấy từ kết quả bước 2." },
  { token: "{{category_url_seed}}", description: "URL danh mục đầu vào của step 3 mới, thường lấy từ kết quả bước 2." },
  { token: "{{checklist}}", description: "Checklist AuditSEO người dùng nhập hoặc mặc định backend." },
  { token: "{{raw_ai_output}}", description: "Raw output từ bước 2 hoặc bước 3 cần ép về JSON ở bước .5." },
  { token: "{{partial_json}}", description: "JSON parse được một phần trước khi formatter vá schema cuối." },
  { token: "{{expected_schema_json}}", description: "Schema JSON backend yêu cầu cho bước formatter." }
];

const sampleVariables: Record<string, unknown> = {
  website_url: "https://hoctienganhtaiphilippines.vn/",
  url: "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
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
  batch_pages_json: [
    {
      targetUrl: "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
      page: {
        title: "Ưu đãi du học Philippines tại IMS 2026",
        metaDescription: "Cập nhật ưu đãi mới nhất khi học tiếng Anh tại trường IMS Philippines năm 2026.",
        headings: {
          h1: ["Ưu đãi du học Philippines tại IMS 2026"],
          h2: ["Học phí IMS", "Ưu đãi ký túc xá", "Có nên đăng ký IMS không?"]
        },
        metrics: {
          wordCount: 1340,
          imageCount: 3
        }
      },
      articleContent: "Bài viết giới thiệu ưu đãi IMS, học phí, ký túc xá, lịch khai giảng, CTA liên hệ và FAQ cuối bài.",
      primaryKeyword: "ưu đãi du học Philippines tại IMS",
      categoryName: "Trường Anh ngữ IMS",
      categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/",
      primaryKeywordSeed: "ưu đãi du học Philippines tại IMS",
      categoryNameSeed: "Trường Anh ngữ IMS",
      categoryUrlSeed: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/"
    },
    {
      targetUrl: "https://hoctienganhtaiphilippines.vn/hoc-bong-philinter-philippines/",
      page: {
        title: "Học bổng Philinter Philippines 2026",
        metaDescription: "Điều kiện và mức học bổng mới nhất tại Philinter năm 2026.",
        headings: {
          h1: ["Học bổng Philinter Philippines 2026"],
          h2: ["Điều kiện apply", "Chi phí sau học bổng"]
        },
        metrics: {
          wordCount: 1180,
          imageCount: 2
        }
      },
      articleContent: "Bài viết tổng hợp học bổng, điều kiện và timeline apply tại Philinter.",
      primaryKeyword: "học bổng Philinter Philippines",
      categoryName: "Trường Anh ngữ Philinter",
      categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-philinter/",
      primaryKeywordSeed: "học bổng Philinter Philippines",
      categoryNameSeed: "Trường Anh ngữ Philinter",
      categoryUrlSeed: "https://hoctienganhtaiphilippines.vn/truong-philinter/"
    }
  ],
  categories_json: [
    { name: "Trường Anh ngữ IMS", url: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/" },
    { name: "Khóa ESL – Tiếng Anh giao tiếp", url: "https://hoctienganhtaiphilippines.vn/khoa-hoc-esl-tieng-anh-can-ban-tai-philippines-5179/" },
    { name: "Trường Anh ngữ Philinter", url: "https://hoctienganhtaiphilippines.vn/truong-philinter/" },
    { name: "Học bổng du học Philippines 2026", url: "https://hoctienganhtaiphilippines.vn/hoc-bong-du-hoc-philippines/" }
  ],
  category_contexts_json: [
    {
      name: "Trường Anh ngữ IMS",
      url: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/",
      title: "Trường Anh ngữ IMS",
      source: "html",
      contentExcerpt: "Tổng hợp thông tin về IMS, học phí, ký túc xá, ưu đãi và lịch khai giảng."
    }
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
  category_json: {
    categoryName: "Trường Anh ngữ IMS",
    categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/",
    categoryMatchReason: "Slug và nội dung tập trung vào ưu đãi tại IMS."
  },
  page_json: {
    url: "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
    title: "Ưu đãi du học Philippines tại IMS 2026",
    metaDescription: "Cập nhật ưu đãi mới nhất khi học tiếng Anh tại trường IMS Philippines năm 2026.",
    headings: {
      h1: ["Ưu đãi du học Philippines tại IMS 2026"],
      h2: ["Học phí IMS", "Ưu đãi ký túc xá", "Có nên đăng ký IMS không?"]
    },
    metrics: {
      wordCount: 1340,
      imageCount: 3,
      internalLinkCount: 2,
      metaDescriptionLength: 128
    }
  },
  article_content: "Bài viết giới thiệu ưu đãi IMS, học phí, ký túc xá, lịch khai giảng, CTA liên hệ và FAQ cuối bài.",
  research_json: {
    primaryKeyword: "ưu đãi du học Philippines tại IMS",
    categoryName: "Trường Anh ngữ IMS",
    categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/",
    categoryMatchReason: "SERP và nội dung đều xoay quanh ưu đãi IMS.",
    searchIntent: "Người tìm kiếm muốn so sánh ưu đãi, học phí và điều kiện đăng ký tại IMS trước khi chốt tư vấn.",
    competitorInsights: ["Đối thủ top 3 đều có bảng học phí cập nhật và CTA tư vấn ngay."],
    freshnessInsights: ["SERP ưu tiên bài có năm hiện tại trong title và thông tin khai giảng mới."],
    keywordDemandEvidence: "Có nhiều kết quả SERP transactional và bài so sánh trường, cho thấy nhu cầu thực.",
    contentGapInsights: ["Bài đang thiếu bảng so sánh ưu đãi trước/sau khi apply."],
    recommendedAngles: ["Thêm bảng chi phí thực nhận sau ưu đãi", "Bổ sung FAQ điều kiện nhập học"],
    sources: [{ title: "IMS Cebu", url: "https://ims.example.com", date: "2026-01-15", snippet: "Official tuition and promo page." }]
  },
  research_items_json: [
    {
      targetUrl: "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
      primaryKeyword: "ưu đãi du học Philippines tại IMS",
      categoryName: "Trường Anh ngữ IMS",
      categoryUrl: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/",
      categoryMatchReason: "SERP và nội dung đều xoay quanh ưu đãi IMS.",
      searchIntent: "Người tìm kiếm muốn so sánh ưu đãi, học phí và điều kiện đăng ký tại IMS trước khi chốt tư vấn.",
      competitorInsights: ["Đối thủ top 3 đều có bảng học phí cập nhật và CTA tư vấn ngay."],
      freshnessInsights: ["SERP ưu tiên bài có năm hiện tại trong title và thông tin khai giảng mới."],
      keywordDemandEvidence: "Có nhiều kết quả SERP transactional và bài so sánh trường, cho thấy nhu cầu thực.",
      contentGapInsights: ["Bài đang thiếu bảng so sánh ưu đãi trước/sau khi apply."],
      recommendedAngles: ["Thêm bảng chi phí thực nhận sau ưu đãi", "Bổ sung FAQ điều kiện nhập học"],
      sources: [{ title: "IMS Cebu", url: "https://ims.example.com", date: "2026-01-15", snippet: "Official tuition and promo page." }]
    }
  ],
  site_urls_json: [
    "https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/",
    "https://hoctienganhtaiphilippines.vn/hoc-phi-ims-philippines/"
  ],
  primary_keyword: "ưu đãi du học Philippines tại IMS",
  primary_keyword_seed: "ưu đãi du học Philippines tại IMS",
  category_name_seed: "Trường Anh ngữ IMS",
  category_url_seed: "https://hoctienganhtaiphilippines.vn/truong-anh-ngu-ims/",
  checklist: "Điểm kỹ thuật SEO: 18/24 | Điểm nội dung: 4/6 | Hướng: Audit Content — xem checklist chuẩn Clickon (25 tiêu chí, tổng 30đ) trong deploy/checklist hoặc resources/audit/seo-checklist.txt",
  raw_ai_output: "# Báo cáo mẫu\n\nURL: https://hoctienganhtaiphilippines.vn/uu-dai-du-hoc-philippines-tai-ims/\nTừ khóa chính: ưu đãi du học Philippines tại IMS\nDanh mục: Trường Anh ngữ IMS\nĐiểm audit: 72/100\nĐề xuất: cập nhật title, bổ sung FAQ, thêm internal link.",
  partial_json: {
    auditScore: 72,
    auditFindings: ["Điểm kỹ thuật SEO: 17/24", "Điểm nội dung: 4/6", "STT 7: Keyword chưa rõ trong H2", "STT 23: Thiếu freshness 2026"],
    auditRecommendations: ["Viết lại title", "Thêm FAQ", "Bổ sung internal link", "Cập nhật số liệu 2026"],
    contentRevisionDirection: "Audit Content. Bài viết khá đúng intent nhưng còn thiếu tối ưu kỹ thuật. Cần cập nhật title, freshness và internal link. Ưu tiên bổ sung dữ liệu năm hiện tại."
  },
  expected_schema_json: {
    items: [
      {
        targetUrl: "string",
        primaryKeyword: "string",
        categoryName: "string",
        categoryUrl: "string",
        categoryMatchReason: "string",
        auditScore: 0,
        auditFindings: ["string"],
        auditRecommendations: ["string"],
        contentRevisionDirection: "string"
      }
    ]
  }
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
        ["keyword_category_mapping", "Bước 2 chạy theo chunk: dùng URL + danh mục để trả keyword chính và danh mục cho từng dòng trong chunk."],
        ["keyword_category_json_formatter", "Bước 2.5 chạy khi output bước 2 không phải JSON hợp lệ: chuyển raw text/report thành JSON đúng schema."],
        ["onpage_audit", "Bước 3 chạy theo chunk: dùng kết quả bước 2 + checklist để trả điểm, đề xuất và định hướng từng dòng trong chunk."],
        ["onpage_audit_json_formatter", "Bước 3.5 chạy khi output bước 3 không phải JSON hợp lệ: chuyển raw text/report thành JSON đúng schema."],
        ["deep_research_research", "Flow audit_deep_research bước 3A chạy theo chunk: sau khi bước 2 cũ đã trả keyword/danh mục, Perplexity nghiên cứu thêm SERP, đối thủ, intent, freshness cho từng URL trong batch."],
        ["deep_research_audit", "Flow audit_deep_research bước 3B chạy theo chunk: GPT reasoning audit đúng checklist Clickon cho toàn bộ batch, dùng dữ liệu bước 2 + research từng URL."],
        ["deep_research_json_formatter", "Flow audit_deep_research bước 3C chạy theo chunk: ép raw output reasoning về JSON cuối hợp lệ cho đủ toàn bộ items trong batch."]
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
        description="Cấu hình prompt admin cho chunk AI: bước 2/bước 3 chuẩn giữ nguyên, còn flow audit_deep_research sẽ dùng prompt riêng để thay thế bước 3 sau khi bước 2 cũ đã chạy xong."
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
