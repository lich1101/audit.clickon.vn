"use client";

import { Save } from "lucide-react";
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
import { fetchAdminAuditSettings, updateAdminAuditSettings, type AuditSystemSettings } from "@/lib/audit-settings";
import type { AiProvider } from "@/types";

const providerDescriptions = {
  openai: "OpenAI Responses API, phù hợp output JSON ổn định.",
  gemini: "Gemini generateContent với JSON schema.",
  gemini_deep_research: "Gemini Deep Research — chậm hơn, cần quyền project."
} as const;

export default function AdminAuditSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [modelPricing, setModelPricing] = useState<AuditSystemSettings["modelPricing"]>([]);
  const [settings, setSettings] = useState<AuditSystemSettings>({
    aiProvider: "openai",
    aiModel: null,
    maxParallelItems: 3
  });

  useEffect(() => {
    void fetchAdminAuditSettings()
      .then((data) => {
        setSettings(data);
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
      toast.success("Đã lưu cấu hình audit hệ thống.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu cấu hình.");
    } finally {
      setSaving(false);
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
          <CardTitle>Model AI mặc định</CardTitle>
          <CardDescription>Mọi audit run của người dùng sẽ dùng provider và model được cấu hình tại đây.</CardDescription>
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
          <CardTitle>Thông số batch legacy</CardTitle>
          <CardDescription>
            Batch URL-only hiện chạy một job cho toàn bộ URL đã chọn. Giá trị này chỉ giữ tương thích cho flow xử lý từng dòng cũ.
          </CardDescription>
        </CardHeader>
        <CardContent className="max-w-sm">
          <div className="flex flex-col gap-2">
            <Label htmlFor="max-parallel">Giới hạn song song legacy</Label>
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
            <p className="text-xs text-muted-foreground">Flow batch mới không nhân request AI theo số URL; mỗi run chỉ gọi AI theo từng bước batch.</p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Bảng giá credit theo model (token)</CardTitle>
          <CardDescription>
            Mỗi batch audit gọi 2 lần AI: bước 2 cho toàn bộ URL và bước 3 cho toàn bộ URL. Credit trừ theo token thực tế, tối thiểu mỗi lần gọi.
          </CardDescription>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b text-left text-muted-foreground">
                <th className="py-2 pr-4">Model</th>
                <th className="py-2 pr-4">Credit / 1K input</th>
                <th className="py-2 pr-4">Credit / 1K output</th>
                <th className="py-2">Tối thiểu / lần gọi</th>
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
                  <td className="py-3">{row.minCreditsPerCall}</td>
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
