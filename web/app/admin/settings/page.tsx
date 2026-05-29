"use client";

import { Loader2, Save, ShieldCheck } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { GeminiPdfUpload } from "@/components/admin/gemini-pdf-upload";
import { AiModelSelect } from "@/components/forms/ai-model-select";
import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  checkAdminAuditSettingsConfiguration,
  fetchAdminAuditSettings,
  updateAdminAuditSettings,
  type ModelPricingRow,
  type AuditConfigurationCheckReport,
  type AuditSystemSettings,
  type GeminiPdfAttachment
} from "@/lib/audit-settings";
import type {
  AiProvider,
  AuditWorkflow,
  DeepResearchReasoningProvider,
  DeepResearchResearchProvider,
  JsonFormatterProvider
} from "@/types";

export default function AdminAuditSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [checking, setChecking] = useState(false);
  const [numericDrafts, setNumericDrafts] = useState<Record<string, string>>({});
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
    minValidUrlsAfterStep1: 50,
    deepResearchBatchSize: 5,
    deepResearchResearchProvider: "perplexity",
    deepResearchResearchModel: "sonar-deep-research",
    deepResearchReasoningProvider: "openai",
    deepResearchReasoningModel: "gpt-5.5",
    deepResearchFormatterProvider: "openai",
    deepResearchFormatterModel: "gpt-5.5"
  });

  const isStandardStep3 = settings.step3FlowMode === "standard";

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
        setNumericDrafts({});
      })
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải cấu hình audit."))
      .finally(() => setLoading(false));
  }, []);

  async function handleSave() {
    try {
      setSaving(true);
      const saved = await updateAdminAuditSettings({
        ...settings,
        modelPricing
      });
      setSettings(saved);
      setModelPricing(saved.modelPricing ?? []);
      setNumericDrafts({});
      setCheckReport(null);
      toast.success("Đã lưu cấu hình.");
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
      toast.success(report.ready ? "Sẵn sàng chạy." : "Còn mục cần xử lý.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể kiểm tra cấu hình.");
    } finally {
      setChecking(false);
    }
  }

  function updateModelPricingRow(index: number, updates: Partial<ModelPricingRow>) {
    setModelPricing((current) =>
      (current ?? []).map((row, rowIndex) => (rowIndex === index ? { ...row, ...updates } : row))
    );
  }

  function readNumericDraft(key: string, value: number | null | undefined) {
    return numericDrafts[key] ?? (value == null ? "" : String(value));
  }

  function writeNumericDraft(key: string, value: string) {
    setNumericDrafts((current) => ({
      ...current,
      [key]: value
    }));
  }

  function commitIntegerDraft(key: string, rawValue: string, onValid: (value: number) => void, max: number) {
    writeNumericDraft(key, rawValue);

    if (rawValue.trim() === "") {
      return;
    }

    const parsed = Number(rawValue);

    if (!Number.isFinite(parsed)) {
      return;
    }

    onValid(Math.min(max, Math.max(0, Math.trunc(parsed))));
  }

  function normalizeIntegerDraft(key: string, currentValue: number, onValid: (value: number) => void, min: number, max: number) {
    const rawValue = numericDrafts[key];

    if (rawValue == null) {
      return;
    }

    const parsed = Number(rawValue);
    const normalized = Number.isFinite(parsed)
      ? Math.min(max, Math.max(min, Math.trunc(parsed)))
      : currentValue;

    onValid(normalized);
    writeNumericDraft(key, String(normalized));
  }

  function commitDecimalDraft(key: string, rawValue: string, onValid: (value: number) => void) {
    writeNumericDraft(key, rawValue);

    if (rawValue.trim() === "") {
      return;
    }

    const parsed = Number(rawValue);

    if (!Number.isFinite(parsed)) {
      return;
    }

    onValid(Math.max(0, parsed));
  }

  function normalizeDecimalDraft(key: string, currentValue: number, onValid: (value: number) => void) {
    const rawValue = numericDrafts[key];

    if (rawValue == null) {
      return;
    }

    const parsed = Number(rawValue);
    const normalized = Number.isFinite(parsed) ? Math.max(0, parsed) : currentValue;

    onValid(normalized);
    writeNumericDraft(key, String(normalized));
  }

  function commitNullableDecimalDraft(key: string, rawValue: string, onValid: (value: number | null) => void) {
    writeNumericDraft(key, rawValue);

    if (rawValue.trim() === "") {
      onValid(null);
      return;
    }

    const parsed = Number(rawValue);

    if (!Number.isFinite(parsed)) {
      return;
    }

    onValid(Math.max(0, parsed));
  }

  function normalizeNullableDecimalDraft(key: string, currentValue: number | null | undefined, onValid: (value: number | null) => void) {
    const rawValue = numericDrafts[key];

    if (rawValue == null) {
      return;
    }

    if (rawValue.trim() === "") {
      onValid(null);
      writeNumericDraft(key, "");
      return;
    }

    const parsed = Number(rawValue);
    const normalized = Number.isFinite(parsed) ? Math.max(0, parsed) : currentValue ?? null;

    onValid(normalized);
    writeNumericDraft(key, normalized == null ? "" : String(normalized));
  }

  function updateGeminiPdfAttachment(slot: string, attachment: GeminiPdfAttachment | null) {
    setSettings((current) => {
      const next = { ...(current.geminiPdfAttachments ?? {}) };

      if (attachment) {
        next[slot] = attachment;
      } else {
        delete next[slot];
      }

      return {
        ...current,
        geminiPdfAttachments: next
      };
    });
  }

  if (loading) {
    return <LoadingState title="Đang tải..." description="" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Audit Settings"
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Audit Settings" }
        ]}
      />

      <Card>
        <CardHeader>
          <CardTitle>Bước 3</CardTitle>
        </CardHeader>
        <CardContent className="grid max-w-md gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="step3-flow-mode">Flow</Label>
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
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="standard">Chuẩn</SelectItem>
                <SelectItem value="audit_deep_research">Deep Research</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      <Card className={checkReport ? (checkReport.ready ? "border-emerald-500/40" : "border-destructive/40") : undefined}>
        <CardHeader>
          <CardTitle>Kiểm tra</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <Button type="button" variant="outline" disabled={checking} onClick={handleCheckConfiguration}>
            {checking ? <Loader2 className="size-4 animate-spin" /> : <ShieldCheck className="size-4" />}
            {checking ? "Đang kiểm tra..." : "Kiểm tra"}
          </Button>

          {checkReport ? (
            <div className="space-y-3 rounded-xl border border-border/70 p-4 text-sm">
              <p className={checkReport.ready ? "text-emerald-600" : "text-destructive"}>
                {checkReport.ready ? "OK" : "Cần xử lý"} · OK {checkReport.summary.ok} · Warning {checkReport.summary.warning} · Error{" "}
                {checkReport.summary.error}
              </p>
              {checkReport.groups.map((group) =>
                group.items
                  .filter((item) => item.status !== "ok")
                  .map((item, index) => (
                    <p key={`${group.id}-${index}`} className={item.status === "error" ? "text-destructive" : "text-amber-600"}>
                      {item.label}: {item.message}
                    </p>
                  ))
              )}
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Mặc định</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 lg:grid-cols-2">
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
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="openai">OpenAI</SelectItem>
                <SelectItem value="gemini">Gemini</SelectItem>
                <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <AiModelSelect
            key={settings.aiProvider}
            provider={settings.aiProvider}
            value={settings.aiModel ?? ""}
            onChange={(model) => setSettings((current) => ({ ...current, aiModel: model || null }))}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Bước 2</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-6 lg:grid-cols-2">
          <div className="grid gap-4">
            <div className="flex flex-col gap-2">
              <Label htmlFor="step2-ai-provider">Provider</Label>
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
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                  <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <AiModelSelect
              key={`step2-main-${settings.step2AiProvider}`}
              id="step2-ai-model"
              label="Model"
              provider={settings.step2AiProvider}
              value={settings.step2AiModel ?? ""}
              onChange={(model) => setSettings((current) => ({ ...current, step2AiModel: model || null }))}
            />
            <GeminiPdfUpload
              slot="step2_ai"
              label="PDF"
              provider={settings.step2AiProvider}
              attachment={settings.geminiPdfAttachments?.step2_ai ?? null}
              onChange={(attachment) => updateGeminiPdfAttachment("step2_ai", attachment)}
            />
          </div>

          <div className="grid gap-4">
            <p className="text-sm font-medium">Bước 2.5</p>
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
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openai">OpenAI</SelectItem>
                  <SelectItem value="gemini">Gemini</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <AiModelSelect
              key={`step2-${settings.step2FormatterProvider}`}
              provider={settings.step2FormatterProvider}
              value={settings.step2FormatterModel ?? ""}
              onChange={(model) => setSettings((current) => ({ ...current, step2FormatterModel: model || null }))}
            />
            <GeminiPdfUpload
              slot="step2_formatter"
              label="PDF"
              provider={settings.step2FormatterProvider}
              attachment={settings.geminiPdfAttachments?.step2_formatter ?? null}
              onChange={(attachment) => updateGeminiPdfAttachment("step2_formatter", attachment)}
            />
          </div>
        </CardContent>
      </Card>

      {isStandardStep3 ? (
        <Card>
          <CardHeader>
            <CardTitle>Bước 3 — Chuẩn</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-6 lg:grid-cols-2">
            <div className="grid gap-4">
              <div className="flex flex-col gap-2">
                <Label htmlFor="step3-ai-provider">Provider</Label>
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
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="openai">OpenAI</SelectItem>
                    <SelectItem value="gemini">Gemini</SelectItem>
                    <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <AiModelSelect
                key={`step3-main-${settings.step3AiProvider}`}
                id="step3-ai-model"
                label="Model"
                provider={settings.step3AiProvider}
                value={settings.step3AiModel ?? ""}
                onChange={(model) => setSettings((current) => ({ ...current, step3AiModel: model || null }))}
              />
              <GeminiPdfUpload
                slot="step3_ai"
                label="PDF"
                provider={settings.step3AiProvider}
                attachment={settings.geminiPdfAttachments?.step3_ai ?? null}
                onChange={(attachment) => updateGeminiPdfAttachment("step3_ai", attachment)}
              />
            </div>

            <div className="grid gap-4">
              <p className="text-sm font-medium">Bước 3.5</p>
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
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="openai">OpenAI</SelectItem>
                    <SelectItem value="gemini">Gemini</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <AiModelSelect
                key={`step3-${settings.step3FormatterProvider}`}
                provider={settings.step3FormatterProvider}
                value={settings.step3FormatterModel ?? ""}
                onChange={(model) => setSettings((current) => ({ ...current, step3FormatterModel: model || null }))}
              />
              <GeminiPdfUpload
                slot="step3_formatter"
                label="PDF"
                provider={settings.step3FormatterProvider}
                attachment={settings.geminiPdfAttachments?.step3_formatter ?? null}
                onChange={(attachment) => updateGeminiPdfAttachment("step3_formatter", attachment)}
              />
            </div>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Bước 3 — Deep Research</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-6 lg:grid-cols-3">
            <div className="grid gap-4">
              <p className="text-sm font-medium">3A Research</p>
              <div className="flex flex-col gap-2">
                <Label htmlFor="deep-research-research-provider">Provider</Label>
                <Select
                  value={settings.deepResearchResearchProvider}
                  onValueChange={(value) =>
                    setSettings((current) => ({
                      ...current,
                      deepResearchResearchProvider: value as DeepResearchResearchProvider,
                      deepResearchResearchModel:
                        value === "gemini_deep_research" ? "deep-research-pro-preview-12-2025" : "sonar-deep-research"
                    }))
                  }
                >
                  <SelectTrigger id="deep-research-research-provider">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="perplexity">Perplexity</SelectItem>
                    <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <AiModelSelect
                key={`deep-research-research-${settings.deepResearchResearchProvider}`}
                id="deep-research-research-model"
                label="Model"
                provider={settings.deepResearchResearchProvider}
                value={settings.deepResearchResearchModel ?? ""}
                onChange={(model) =>
                  setSettings((current) => ({
                    ...current,
                    deepResearchResearchModel: model || null
                  }))
                }
                allowCustomInput
              />
            </div>

            <div className="grid gap-4">
              <p className="text-sm font-medium">3B Reasoning</p>
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
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="openai">OpenAI</SelectItem>
                    <SelectItem value="gemini">Gemini</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <AiModelSelect
                key={`deep-research-reasoning-${settings.deepResearchReasoningProvider}`}
                id="deep-research-reasoning-model"
                label="Model"
                provider={settings.deepResearchReasoningProvider}
                value={settings.deepResearchReasoningModel ?? ""}
                onChange={(model) =>
                  setSettings((current) => ({
                    ...current,
                    deepResearchReasoningModel: model || null
                  }))
                }
                allowCustomInput
              />
            </div>

            <div className="grid gap-4">
              <p className="text-sm font-medium">3C Formatter</p>
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
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="openai">OpenAI</SelectItem>
                    <SelectItem value="gemini">Gemini</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <AiModelSelect
                key={`deep-research-formatter-${settings.deepResearchFormatterProvider}`}
                id="deep-research-formatter-model"
                label="Model"
                provider={settings.deepResearchFormatterProvider}
                value={settings.deepResearchFormatterModel ?? ""}
                onChange={(model) =>
                  setSettings((current) => ({
                    ...current,
                    deepResearchFormatterModel: model || null
                  }))
                }
                allowCustomInput
              />
            </div>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Batch</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div className="flex flex-col gap-2">
            <Label htmlFor="step2-batch-size">Bước 2</Label>
            <Input
              id="step2-batch-size"
              type="number"
              min={1}
              max={300}
              value={readNumericDraft("step2BatchSize", settings.step2BatchSize)}
              onChange={(event) =>
                commitIntegerDraft("step2BatchSize", event.target.value, (value) =>
                  setSettings((current) => ({
                    ...current,
                    step2BatchSize: value
                  })), 300)
              }
              onBlur={() =>
                normalizeIntegerDraft("step2BatchSize", settings.step2BatchSize, (value) =>
                  setSettings((current) => ({
                    ...current,
                    step2BatchSize: value
                  })), 1, 300)
              }
            />
          </div>

          {isStandardStep3 ? (
            <div className="flex flex-col gap-2">
              <Label htmlFor="step3-batch-size">Bước 3</Label>
              <Input
                id="step3-batch-size"
                type="number"
                min={1}
                max={300}
                value={readNumericDraft("step3BatchSize", settings.step3BatchSize)}
                onChange={(event) =>
                  commitIntegerDraft("step3BatchSize", event.target.value, (value) =>
                    setSettings((current) => ({
                      ...current,
                      step3BatchSize: value
                    })), 300)
                }
                onBlur={() =>
                  normalizeIntegerDraft("step3BatchSize", settings.step3BatchSize, (value) =>
                    setSettings((current) => ({
                      ...current,
                      step3BatchSize: value
                    })), 1, 300)
                }
              />
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              <Label htmlFor="deep-research-batch-size">Bước 3 DR</Label>
              <Input
                id="deep-research-batch-size"
                type="number"
                min={1}
                max={100}
                value={readNumericDraft("deepResearchBatchSize", settings.deepResearchBatchSize)}
                onChange={(event) =>
                  commitIntegerDraft("deepResearchBatchSize", event.target.value, (value) =>
                    setSettings((current) => ({
                      ...current,
                      deepResearchBatchSize: value
                    })), 100)
                }
                onBlur={() =>
                  normalizeIntegerDraft("deepResearchBatchSize", settings.deepResearchBatchSize, (value) =>
                    setSettings((current) => ({
                      ...current,
                      deepResearchBatchSize: value
                    })), 1, 100)
                }
              />
            </div>
          )}

          <div className="flex flex-col gap-2">
            <Label htmlFor="min-valid-urls-after-step1">Tối thiểu URL hợp lệ sau B1</Label>
            <Input
              id="min-valid-urls-after-step1"
              type="number"
              min={1}
              max={300}
              value={readNumericDraft("minValidUrlsAfterStep1", settings.minValidUrlsAfterStep1)}
              onChange={(event) =>
                commitIntegerDraft("minValidUrlsAfterStep1", event.target.value, (value) =>
                  setSettings((current) => ({
                    ...current,
                    minValidUrlsAfterStep1: value
                  })), 300)
              }
              onBlur={() =>
                normalizeIntegerDraft("minValidUrlsAfterStep1", settings.minValidUrlsAfterStep1, (value) =>
                  setSettings((current) => ({
                    ...current,
                    minValidUrlsAfterStep1: value
                  })), 1, 300)
              }
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="max-parallel">Song song</Label>
            <Input
              id="max-parallel"
              type="number"
              min={1}
              max={10}
              value={readNumericDraft("maxParallelItems", settings.maxParallelItems)}
              onChange={(event) =>
                commitIntegerDraft("maxParallelItems", event.target.value, (value) =>
                  setSettings((current) => ({
                    ...current,
                    maxParallelItems: value
                  })), 10)
              }
              onBlur={() =>
                normalizeIntegerDraft("maxParallelItems", settings.maxParallelItems, (value) =>
                  setSettings((current) => ({
                    ...current,
                    maxParallelItems: value
                  })), 1, 10)
              }
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Giá credit</CardTitle>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <table className="w-full min-w-[1200px] text-sm">
            <thead>
              <tr className="border-b text-left">
                <th className="py-2 pr-4">Model</th>
                <th className="py-2 pr-4">Credit / 1K in</th>
                <th className="py-2 pr-4">Credit / 1K out</th>
                <th className="py-2 pr-4">USD / 1M in</th>
                <th className="py-2 pr-4">USD / 1M out</th>
                <th className="py-2 pr-4">USD / 1M reasoning</th>
                <th className="py-2 pr-4">USD / 1M citation</th>
                <th className="py-2 pr-4">USD / 1K search</th>
                <th className="py-2 pr-4">Min / call</th>
              </tr>
            </thead>
            <tbody>
              {(modelPricing ?? []).map((row, index) => (
                <tr key={`${row.provider}-${row.model}`} className="border-b border-border/60">
                  <td className="py-3 pr-4 font-medium">{row.label}</td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="0.0001"
                      value={readNumericDraft(`modelPricing.${index}.creditsPer1kInput`, row.creditsPer1kInput)}
                      onChange={(event) =>
                        commitDecimalDraft(`modelPricing.${index}.creditsPer1kInput`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            creditsPer1kInput: value
                          }))
                      }
                      onBlur={() =>
                        normalizeDecimalDraft(`modelPricing.${index}.creditsPer1kInput`, row.creditsPer1kInput, (value) =>
                          updateModelPricingRow(index, {
                            creditsPer1kInput: value
                          }))
                      }
                    />
                  </td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="0.0001"
                      value={readNumericDraft(`modelPricing.${index}.creditsPer1kOutput`, row.creditsPer1kOutput)}
                      onChange={(event) =>
                        commitDecimalDraft(`modelPricing.${index}.creditsPer1kOutput`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            creditsPer1kOutput: value
                          }))
                      }
                      onBlur={() =>
                        normalizeDecimalDraft(`modelPricing.${index}.creditsPer1kOutput`, row.creditsPer1kOutput, (value) =>
                          updateModelPricingRow(index, {
                            creditsPer1kOutput: value
                          }))
                      }
                    />
                  </td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="0.000001"
                      value={readNumericDraft(`modelPricing.${index}.usdPer1MInput`, row.usdPer1MInput ?? null)}
                      onChange={(event) =>
                        commitNullableDecimalDraft(`modelPricing.${index}.usdPer1MInput`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MInput: value
                          }))
                      }
                      onBlur={() =>
                        normalizeNullableDecimalDraft(`modelPricing.${index}.usdPer1MInput`, row.usdPer1MInput ?? null, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MInput: value
                          }))
                      }
                    />
                  </td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="0.000001"
                      value={readNumericDraft(`modelPricing.${index}.usdPer1MOutput`, row.usdPer1MOutput ?? null)}
                      onChange={(event) =>
                        commitNullableDecimalDraft(`modelPricing.${index}.usdPer1MOutput`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MOutput: value
                          }))
                      }
                      onBlur={() =>
                        normalizeNullableDecimalDraft(`modelPricing.${index}.usdPer1MOutput`, row.usdPer1MOutput ?? null, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MOutput: value
                          }))
                      }
                    />
                  </td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="0.000001"
                      value={readNumericDraft(`modelPricing.${index}.usdPer1MReasoning`, row.usdPer1MReasoning ?? null)}
                      onChange={(event) =>
                        commitNullableDecimalDraft(`modelPricing.${index}.usdPer1MReasoning`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MReasoning: value
                          }))
                      }
                      onBlur={() =>
                        normalizeNullableDecimalDraft(`modelPricing.${index}.usdPer1MReasoning`, row.usdPer1MReasoning ?? null, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MReasoning: value
                          }))
                      }
                    />
                  </td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="0.000001"
                      value={readNumericDraft(`modelPricing.${index}.usdPer1MCitation`, row.usdPer1MCitation ?? null)}
                      onChange={(event) =>
                        commitNullableDecimalDraft(`modelPricing.${index}.usdPer1MCitation`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MCitation: value
                          }))
                      }
                      onBlur={() =>
                        normalizeNullableDecimalDraft(`modelPricing.${index}.usdPer1MCitation`, row.usdPer1MCitation ?? null, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1MCitation: value
                          }))
                      }
                    />
                  </td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="0.000001"
                      value={readNumericDraft(`modelPricing.${index}.usdPer1kSearchQueries`, row.usdPer1kSearchQueries ?? null)}
                      onChange={(event) =>
                        commitNullableDecimalDraft(`modelPricing.${index}.usdPer1kSearchQueries`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1kSearchQueries: value
                          }))
                      }
                      onBlur={() =>
                        normalizeNullableDecimalDraft(`modelPricing.${index}.usdPer1kSearchQueries`, row.usdPer1kSearchQueries ?? null, (value) =>
                          updateModelPricingRow(index, {
                            usdPer1kSearchQueries: value
                          }))
                      }
                    />
                  </td>
                  <td className="py-3 pr-4">
                    <Input
                      type="number"
                      min={0}
                      step="1"
                      value={readNumericDraft(`modelPricing.${index}.minCreditsPerCall`, row.minCreditsPerCall)}
                      onChange={(event) =>
                        commitIntegerDraft(`modelPricing.${index}.minCreditsPerCall`, event.target.value, (value) =>
                          updateModelPricingRow(index, {
                            minCreditsPerCall: value
                          }), 999999)
                      }
                      onBlur={() =>
                        normalizeIntegerDraft(`modelPricing.${index}.minCreditsPerCall`, row.minCreditsPerCall, (value) =>
                          updateModelPricingRow(index, {
                            minCreditsPerCall: value
                          }), 0, 999999)
                      }
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button disabled={saving} onClick={handleSave}>
          <Save className="size-4" />
          {saving ? "Đang lưu..." : "Lưu"}
        </Button>
      </div>
    </div>
  );
}
