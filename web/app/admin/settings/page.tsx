"use client";

import { Loader2, Save, ShieldCheck } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { AiModelSelect } from "@/components/forms/ai-model-select";
import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  checkAdminAuditSettingsConfiguration,
  fetchAdminAuditSettings,
  updateAdminAuditSettings,
  type AuditConfigurationCheckReport,
  type AuditSystemSettings
} from "@/lib/audit-settings";
import type {
  AiProvider,
  AuditWorkflow,
  DeepResearchReasoningProvider,
  DeepResearchResearchProvider,
  JsonFormatterProvider
} from "@/types";

const providerDescriptions = {
  openai: "OpenAI Responses API, phù hợp output JSON ổn định.",
  gemini: "Gemini generateContent với JSON schema.",
  gemini_deep_research: "Gemini Deep Research — chậm hơn, cần quyền project.",
  perplexity: "Perplexity Sonar/Deep Research cho research web, SERP và đối thủ."
} as const;

const deepResearchResearchProviderDescriptions: Record<DeepResearchResearchProvider, string> = {
  perplexity: "Provider research web gốc của flow 3A, phù hợp intent/SERP/đối thủ/freshness theo batch.",
  gemini_deep_research: "Agent Gemini Deep Research chạy nền, phù hợp research sâu nhưng chậm hơn và cần quyền project."
};

const deepResearchReasoningProviderDescriptions: Record<DeepResearchReasoningProvider, string> = {
  openai: "OpenAI reasoning cho output audit ổn định theo checklist Clickon.",
  gemini: "Gemini reasoning để chấm audit theo checklist, sau đó vẫn qua bước 3C để ép JSON cuối."
};

const formatterProviderDescriptions = {
  openai: "Dùng OpenAI để ép raw report về JSON.",
  gemini: "Dùng Gemini generateContent + JSON schema để ép raw report về JSON."
} as const;

const step3FlowModeDescriptions: Record<AuditWorkflow, string> = {
  standard: "Khóa toàn hệ thống ở bước 3 chuẩn. User chạy audit sẽ luôn dùng bước 2 cũ + bước 3 cũ.",
  audit_deep_research: "Khóa toàn hệ thống ở bước 3 Deep Research. User chạy audit sẽ luôn dùng bước 2 cũ + bước 3 mới 3A/3B/3C."
};

export default function AdminAuditSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [checking, setChecking] = useState(false);
  const [modelPricing, setModelPricing] = useState<AuditSystemSettings["modelPricing"]>([]);
  const [checkReport, setCheckReport] = useState<AuditConfigurationCheckReport | null>(null);
  const [settings, setSettings] = useState<AuditSystemSettings>({
    aiProvider: "openai",
    aiModel: null,
    step2AiProvider: "openai",
    step2AiModel: null,
    step3AiProvider: "openai",
    step3AiModel: null,
    step2FormatterProvider: "gemini",
    step2FormatterModel: "gemini-2.5-flash",
    step3FormatterProvider: "gemini",
    step3FormatterModel: "gemini-2.5-flash",
    step3FlowMode: "standard",
    maxParallelItems: 3,
    step2BatchSize: 60,
    step3BatchSize: 30,
    deepResearchBatchSize: 5,
    deepResearchResearchProvider: "perplexity",
    deepResearchResearchModel: "sonar-deep-research",
    deepResearchReasoningProvider: "openai",
    deepResearchReasoningModel: "gpt-5.5",
    deepResearchFormatterProvider: "openai",
    deepResearchFormatterModel: "gpt-5.5"
  });

  useEffect(() => {
    void fetchAdminAuditSettings()
      .then((data) => {
        setSettings({
          ...data,
          step2AiProvider: data.step2AiProvider ?? data.aiProvider,
          step3AiProvider: data.step3AiProvider ?? data.aiProvider,
          step3FlowMode: data.step3FlowMode ?? "standard",
          deepResearchResearchProvider: data.deepResearchResearchProvider ?? "perplexity",
          deepResearchResearchModel: data.deepResearchResearchModel ?? "sonar-deep-research",
          deepResearchReasoningProvider: data.deepResearchReasoningProvider ?? "openai",
          deepResearchReasoningModel: data.deepResearchReasoningModel ?? "gpt-5.5",
          deepResearchFormatterProvider: data.deepResearchFormatterProvider ?? "openai",
          deepResearchFormatterModel: data.deepResearchFormatterModel ?? "gpt-5.5"
        });
        setModelPricing(data.modelPricing ?? []);
      })
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải cấu hình audit."))
      .finally(() => setLoading(false));
  }, []);

  async function handleSave() {
    try {
      setSaving(true);
      const saved = await updateAdminAuditSettings(settings);
      setSettings(saved);
      setCheckReport(null);
      toast.success("Đã lưu cấu hình audit hệ thống.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu cấu hình.");
    } finally {
      setSaving(false);
    }
  }

  async function handleCheckConfiguration() {
    try {
      setChecking(true);
      const report = await checkAdminAuditSettingsConfiguration(settings);
      setCheckReport(report);
      toast.success(report.ready ? "Cấu hình hiện tại đã sẵn sàng chạy." : "Đã kiểm tra cấu hình. Vẫn còn mục cần xử lý.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể kiểm tra cấu hình.");
    } finally {
      setChecking(false);
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải cấu hình audit..." description="Lấy provider, model và cấu hình batch từ hệ thống." />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Audit Settings"
        description="Chỉ admin được cấu hình provider/model AI và thông số vận hành batch audit. Người dùng không thể thay đổi các giá trị này."
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Audit Settings" }
        ]}
      />

      <Card>
        <CardHeader>
          <CardTitle>Chế độ bước 3 toàn hệ thống</CardTitle>
          <CardDescription>
            Admin chọn cứng bước 3 dùng flow chuẩn hay flow Deep Research. Người dùng ở màn hình audit chỉ chạy theo cấu hình này, không được tự chọn nữa.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-5 lg:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="step3-flow-mode">Flow bước 3</Label>
            <Select
              value={settings.step3FlowMode}
              onValueChange={(value) =>
                setSettings((current) => ({
                  ...current,
                  step3FlowMode: value as AuditWorkflow
                }))
              }
            >
              <SelectTrigger id="step3-flow-mode">
                <SelectValue placeholder="Chọn flow bước 3" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="standard">Bước 3 chuẩn</SelectItem>
                <SelectItem value="audit_deep_research">Bước 3 Deep Research</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{step3FlowModeDescriptions[settings.step3FlowMode]}</p>
          </div>
        </CardContent>
      </Card>

      <Card className={checkReport ? (checkReport.ready ? "border-emerald-500/40" : "border-destructive/40") : undefined}>
        <CardHeader>
          <CardTitle>Kiểm tra cấu hình runtime</CardTitle>
          <CardDescription>
            Kiểm tra mode bước 3 hiện tại có đủ prompt, model và API key để chạy hay chưa. Nút này không gọi AI thật, chỉ rà soát cấu hình đang lưu và env runtime của backend.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-center gap-3">
            <Button type="button" variant="outline" disabled={checking} onClick={handleCheckConfiguration}>
              {checking ? <Loader2 className="size-4 animate-spin" /> : <ShieldCheck className="size-4" />}
              {checking ? "Đang kiểm tra..." : "Kiểm tra cấu hình"}
            </Button>
            {checkReport ? (
              <p className={checkReport.ready ? "text-sm text-emerald-600" : "text-sm text-destructive"}>
                {checkReport.ready ? "Cấu hình hiện tại đã sẵn sàng chạy." : "Cấu hình hiện tại còn thiếu hoặc có cảnh báo cần xem lại."}
              </p>
            ) : (
              <p className="text-sm text-muted-foreground">Nút này kiểm tra ngay bộ cấu hình đang nằm trên form hiện tại, không cần lưu trước để biết còn thiếu gì.</p>
            )}
          </div>

          {checkReport ? (
            <div className="space-y-4 rounded-2xl border border-border/70 bg-secondary/20 p-4">
              <div className="flex flex-wrap items-center gap-3 text-sm">
                <span className="font-medium">
                  Mode bước 3: {checkReport.step3FlowMode === "audit_deep_research" ? "Deep Research" : "Chuẩn"}
                </span>
                <span className="text-muted-foreground">OK: {checkReport.summary.ok}</span>
                <span className="text-amber-600">Warning: {checkReport.summary.warning}</span>
                <span className="text-destructive">Error: {checkReport.summary.error}</span>
              </div>

              <div className="grid gap-3 lg:grid-cols-2">
                {checkReport.groups.map((group) => (
                  <div key={group.id} className="rounded-2xl border border-border/70 bg-background/80 p-4">
                    <div className="flex items-center justify-between gap-3">
                      <p className="font-medium">{group.title}</p>
                      <span
                        className={
                          group.status === "error"
                            ? "text-sm font-medium text-destructive"
                            : group.status === "warning"
                              ? "text-sm font-medium text-amber-600"
                              : "text-sm font-medium text-emerald-600"
                        }
                      >
                        {group.status === "error" ? "Lỗi" : group.status === "warning" ? "Cảnh báo" : "Ổn"}
                      </span>
                    </div>
                    <div className="mt-3 space-y-2">
                      {group.items.map((item, index) => (
                        <div key={`${group.id}-${index}`} className="rounded-xl border border-border/60 px-3 py-2 text-sm">
                          <p
                            className={
                              item.status === "error"
                                ? "font-medium text-destructive"
                                : item.status === "warning"
                                  ? "font-medium text-amber-600"
                                  : "font-medium text-emerald-600"
                            }
                          >
                            {item.label}
                          </p>
                          <p className="mt-1 text-muted-foreground">{item.message}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Provider và model mặc định</CardTitle>
          <CardDescription>Provider dùng chung cho bước 2 và 3. Model ở đây là fallback nếu model riêng từng bước để trống.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-5 lg:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="admin-ai-provider">Provider</Label>
            <Select
              value={settings.aiProvider}
              onValueChange={(value) =>
                setSettings((current) => ({
                  ...current,
                  aiProvider: value as AiProvider,
                  aiModel: null
                }))
              }
            >
              <SelectTrigger id="admin-ai-provider">
                <SelectValue placeholder="Chọn provider" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="openai">OpenAI</SelectItem>
                <SelectItem value="gemini">Gemini</SelectItem>
                <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{providerDescriptions[settings.aiProvider]}</p>
          </div>

          <AiModelSelect
            key={settings.aiProvider}
            provider={settings.aiProvider}
            value={settings.aiModel ?? ""}
            onChange={(model) => setSettings((current) => ({ ...current, aiModel: model || null }))}
            description="Danh sách model lấy từ API provider (admin)."
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Model chính riêng cho bước 2 và bước 3</CardTitle>
          <CardDescription>
            Mỗi bước có provider và model riêng. Bước 2 dùng cho mọi run. Bước 3 trong card này chỉ được dùng khi chế độ bước 3 toàn hệ thống đang là bước 3 chuẩn.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 lg:grid-cols-2">
          <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4">
            <div>
              <p className="font-medium">Bước 2: keyword + danh mục</p>
              <p className="mt-1 text-xs text-muted-foreground">Dùng để phân tích danh sách URL, chọn keyword chính và danh mục phù hợp.</p>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="step2-ai-provider">Provider bước 2</Label>
              <Select
                value={settings.step2AiProvider}
                onValueChange={(value) =>
                  setSettings((current) => ({
                    ...current,
                    step2AiProvider: value as AiProvider,
                    step2AiModel: null
                  }))
                }
              >
                <SelectTrigger id="step2-ai-provider">
                  <SelectValue placeholder="Chọn provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                  <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">{providerDescriptions[settings.step2AiProvider]}</p>
            </div>
            <AiModelSelect
              key={`step2-main-${settings.step2AiProvider}`}
              id="step2-ai-model"
              label="Model bước 2"
              provider={settings.step2AiProvider}
              value={settings.step2AiModel ?? ""}
              onChange={(model) => setSettings((current) => ({ ...current, step2AiModel: model || null }))}
              description="Model riêng cho bước 2."
            />
          </div>

          <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4">
            <div>
              <p className="font-medium">Bước 3: audit onpage</p>
              <p className="mt-1 text-xs text-muted-foreground">Dùng để chấm điểm, đề xuất audit và định hướng chỉnh sửa nội dung cho flow chuẩn.</p>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="step3-ai-provider">Provider bước 3</Label>
              <Select
                value={settings.step3AiProvider}
                onValueChange={(value) =>
                  setSettings((current) => ({
                    ...current,
                    step3AiProvider: value as AiProvider,
                    step3AiModel: null
                  }))
                }
              >
                <SelectTrigger id="step3-ai-provider">
                  <SelectValue placeholder="Chọn provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                  <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">{providerDescriptions[settings.step3AiProvider]}</p>
            </div>
            <AiModelSelect
              key={`step3-main-${settings.step3AiProvider}`}
              id="step3-ai-model"
              label="Model bước 3"
              provider={settings.step3AiProvider}
              value={settings.step3AiModel ?? ""}
              onChange={(model) => setSettings((current) => ({ ...current, step3AiModel: model || null }))}
              description="Model riêng cho bước 3."
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Model ép JSON riêng cho bước 2.5 và 3.5</CardTitle>
          <CardDescription>
            Khi bước 2/3 trả raw text hoặc báo cáo Markdown, hệ thống gọi model formatter để chuyển về JSON đúng schema. Formatter chỉ dùng OpenAI/Gemini thường, không dùng Deep Research.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 lg:grid-cols-2">
          <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4">
            <div>
              <p className="font-medium">Bước 2.5: keyword + danh mục JSON</p>
              <p className="mt-1 text-xs text-muted-foreground">Chạy khi raw output bước 2 không parse được JSON hoặc thiếu items.</p>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="step2-formatter-provider">Provider</Label>
              <Select
                value={settings.step2FormatterProvider}
                onValueChange={(value) =>
                  setSettings((current) => ({
                    ...current,
                    step2FormatterProvider: value as JsonFormatterProvider,
                    step2FormatterModel: value === "gemini" ? "gemini-2.5-flash" : null
                  }))
                }
              >
                <SelectTrigger id="step2-formatter-provider">
                  <SelectValue placeholder="Chọn formatter provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">{formatterProviderDescriptions[settings.step2FormatterProvider]}</p>
            </div>
            <AiModelSelect
              key={`step2-${settings.step2FormatterProvider}`}
              provider={settings.step2FormatterProvider}
              value={settings.step2FormatterModel ?? ""}
              onChange={(model) => setSettings((current) => ({ ...current, step2FormatterModel: model || null }))}
              description="Model riêng cho bước 2.5."
            />
          </div>

          <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4">
            <div>
              <p className="font-medium">Bước 3.5: audit JSON</p>
              <p className="mt-1 text-xs text-muted-foreground">Chạy khi raw output bước 3 không parse được JSON hoặc thiếu items.</p>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="step3-formatter-provider">Provider</Label>
              <Select
                value={settings.step3FormatterProvider}
                onValueChange={(value) =>
                  setSettings((current) => ({
                    ...current,
                    step3FormatterProvider: value as JsonFormatterProvider,
                    step3FormatterModel: value === "gemini" ? "gemini-2.5-flash" : null
                  }))
                }
              >
                <SelectTrigger id="step3-formatter-provider">
                  <SelectValue placeholder="Chọn formatter provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">{formatterProviderDescriptions[settings.step3FormatterProvider]}</p>
            </div>
            <AiModelSelect
              key={`step3-${settings.step3FormatterProvider}`}
              provider={settings.step3FormatterProvider}
              value={settings.step3FormatterModel ?? ""}
              onChange={(model) => setSettings((current) => ({ ...current, step3FormatterModel: model || null }))}
              description="Model riêng cho bước 3.5."
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Flow audit_deep_research: giữ bước 2 cũ, thay bước 3</CardTitle>
          <CardDescription>
            Card này chỉ áp dụng khi chế độ bước 3 toàn hệ thống đang bật Deep Research. Flow này vẫn chạy bước 2 như chuẩn cũ để lấy keyword + danh mục. Sau đó bước 3 được thay bằng 3A research, 3B reasoning audit, 3C JSON formatter; admin được chọn provider/model thật cho từng bước.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 lg:grid-cols-3">
          <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4">
            <div>
              <p className="font-medium">Bước 3A: research</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Nghiên cứu intent, SERP, đối thủ, freshness, keyword demand và cannibalization cho cả chunk, sau khi đã có keyword/danh mục từ bước 2.
              </p>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="deep-research-research-provider">Provider</Label>
              <Select
                value={settings.deepResearchResearchProvider}
                onValueChange={(value) =>
                  setSettings((current) => ({
                    ...current,
                    deepResearchResearchProvider: value as DeepResearchResearchProvider,
                    deepResearchResearchModel: value === "gemini_deep_research" ? "deep-research-pro-preview-12-2025" : "sonar-deep-research"
                  }))
                }
              >
                <SelectTrigger id="deep-research-research-provider">
                  <SelectValue placeholder="Chọn provider research" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="perplexity">Perplexity</SelectItem>
                  <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">{deepResearchResearchProviderDescriptions[settings.deepResearchResearchProvider]}</p>
            </div>
            <AiModelSelect
              key={`deep-research-research-${settings.deepResearchResearchProvider}`}
              id="deep-research-research-model"
              label="Model bước 3A"
              provider={settings.deepResearchResearchProvider}
              value={settings.deepResearchResearchModel ?? ""}
              onChange={(model) =>
                setSettings((current) => ({
                  ...current,
                  deepResearchResearchModel: model || null
                }))
              }
              allowCustomInput
              description="Chọn từ danh sách hoặc nhập model research chính xác cho bước 3A."
            />
          </div>

          <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4">
            <div>
              <p className="font-medium">Bước 3B: reasoning audit</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Dùng dữ liệu crawl + keyword/danh mục bước 2 + research bước 3A để chấm điểm đúng checklist Clickon cho toàn bộ chunk.
              </p>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="deep-research-reasoning-provider">Provider</Label>
              <Select
                value={settings.deepResearchReasoningProvider}
                onValueChange={(value) =>
                  setSettings((current) => ({
                    ...current,
                    deepResearchReasoningProvider: value as DeepResearchReasoningProvider,
                    deepResearchReasoningModel: value === "gemini" ? "gemini-2.5-pro" : "gpt-5.5"
                  }))
                }
              >
                <SelectTrigger id="deep-research-reasoning-provider">
                  <SelectValue placeholder="Chọn provider reasoning" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">{deepResearchReasoningProviderDescriptions[settings.deepResearchReasoningProvider]}</p>
            </div>
            <AiModelSelect
              key={`deep-research-reasoning-${settings.deepResearchReasoningProvider}`}
              id="deep-research-reasoning-model"
              label="Model bước 3B"
              provider={settings.deepResearchReasoningProvider}
              value={settings.deepResearchReasoningModel ?? ""}
              onChange={(model) =>
                setSettings((current) => ({
                  ...current,
                  deepResearchReasoningModel: model || null
                }))
              }
              allowCustomInput
              description="Chọn từ danh sách hoặc nhập model reasoning chính xác dùng cho bước 3B."
            />
          </div>

          <div className="grid gap-4 rounded-2xl border border-border bg-secondary/30 p-4">
            <div>
              <p className="font-medium">Bước 3C: JSON formatter</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Chuẩn hóa raw output reasoning về JSON cuối hợp lệ, đủ item cho toàn bộ chunk của bước 3 mới.
              </p>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="deep-research-formatter-provider">Provider</Label>
              <Select
                value={settings.deepResearchFormatterProvider}
                onValueChange={(value) =>
                  setSettings((current) => ({
                    ...current,
                    deepResearchFormatterProvider: value as JsonFormatterProvider,
                    deepResearchFormatterModel: value === "gemini" ? "gemini-2.5-flash" : "gpt-5.5"
                  }))
                }
              >
                <SelectTrigger id="deep-research-formatter-provider">
                  <SelectValue placeholder="Chọn formatter provider" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">{formatterProviderDescriptions[settings.deepResearchFormatterProvider]}</p>
            </div>
            <AiModelSelect
              key={`deep-research-formatter-${settings.deepResearchFormatterProvider}`}
              id="deep-research-formatter-model"
              label="Model bước 3C"
              provider={settings.deepResearchFormatterProvider}
              value={settings.deepResearchFormatterModel ?? ""}
              onChange={(model) =>
                setSettings((current) => ({
                  ...current,
                  deepResearchFormatterModel: model || null
                }))
              }
              allowCustomInput
              description="Chọn từ danh sách hoặc nhập model formatter chính xác cho bước 3C."
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Kích thước batch và giới hạn đồng thời</CardTitle>
          <CardDescription>
            Hệ thống tự chia batch theo số URL đã chọn. Trường đồng thời chỉ là giới hạn tối đa số batch được phép chạy cùng lúc, không phải tổng số batch. Bước 3: URL / batch chỉ áp dụng cho bước 3 chuẩn; Deep Research: URL / batch chỉ áp dụng khi bật bước 3 Deep Research.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-5 lg:grid-cols-2 xl:grid-cols-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="step2-batch-size">Bước 2: URL / batch</Label>
            <Input
              id="step2-batch-size"
              type="number"
              min={1}
              max={300}
              value={settings.step2BatchSize}
              onChange={(event) =>
                setSettings((current) => ({
                  ...current,
                  step2BatchSize: Math.max(1, Math.min(300, Number(event.target.value) || 60))
                }))
              }
            />
            <p className="text-xs text-muted-foreground">Mặc định 60 URL/lần gọi AI cho keyword và danh mục.</p>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="step3-batch-size">Bước 3: URL / batch</Label>
            <Input
              id="step3-batch-size"
              type="number"
              min={1}
              max={300}
              value={settings.step3BatchSize}
              onChange={(event) =>
                setSettings((current) => ({
                  ...current,
                  step3BatchSize: Math.max(1, Math.min(300, Number(event.target.value) || 30))
                }))
              }
            />
            <p className="text-xs text-muted-foreground">Mặc định 30 URL/lần gọi AI để giảm rủi ro JSON quá dài.</p>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="deep-research-batch-size">Deep Research: URL / batch</Label>
            <Input
              id="deep-research-batch-size"
              type="number"
              min={1}
              max={100}
              value={settings.deepResearchBatchSize}
              onChange={(event) =>
                setSettings((current) => ({
                  ...current,
                  deepResearchBatchSize: Math.max(1, Math.min(100, Number(event.target.value) || 5))
                }))
              }
            />
            <p className="text-xs text-muted-foreground">Chỉ áp dụng cho step 3 mới của flow audit_deep_research, nên thường nên để nhỏ hơn bước 3 chuẩn. Mặc định 5 URL/lần.</p>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="max-parallel">Giới hạn batch chạy đồng thời</Label>
            <Input
              id="max-parallel"
              type="number"
              min={1}
              max={10}
              value={settings.maxParallelItems}
              onChange={(event) =>
                setSettings((current) => ({
                  ...current,
                  maxParallelItems: Math.max(1, Math.min(10, Number(event.target.value) || 1))
                }))
              }
            />
            <p className="text-xs text-muted-foreground">
              Ví dụ 215 URL, bước 2/60 tạo 4 batch; nếu giới hạn là 1 thì chạy lần lượt, nếu là 2 thì tối đa 2 batch cùng lúc. Thực tế còn phụ thuộc queue worker Docker.
            </p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Bảng giá credit theo model (token)</CardTitle>
          <CardDescription>
            Mỗi chunk AI trừ credit theo token thực tế mà provider trả về. Số call ước tính = ceil(URL/batch bước 2) + ceil(URL/batch bước 3).
          </CardDescription>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b text-left text-muted-foreground">
                <th className="py-2 pr-4">Model</th>
                <th className="py-2 pr-4">Credit / 1K input</th>
                <th className="py-2 pr-4">Credit / 1K output</th>
              </tr>
            </thead>
            <tbody>
              {(modelPricing ?? []).map((row) => (
                <tr key={`${row.provider}-${row.model}`} className="border-b border-border/60">
                  <td className="py-3 pr-4">
                    <p className="font-medium">{row.label}</p>
                    <p className="text-xs text-muted-foreground">{row.provider} · {row.model}</p>
                  </td>
                  <td className="py-3 pr-4">{row.creditsPer1kInput}</td>
                  <td className="py-3 pr-4">{row.creditsPer1kOutput}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button disabled={saving} onClick={handleSave}>
          <Save className="size-4" />
          {saving ? "Đang lưu..." : "Lưu cấu hình"}
        </Button>
      </div>
    </div>
  );
}
