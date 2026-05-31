"use client";

import { Save } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { fetchAdminKeywordRankSettings, updateAdminKeywordRankSettings } from "@/lib/keyword-rank-settings";

export default function AdminKeywordRankSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [extensionInstallUrl, setExtensionInstallUrl] = useState("");

  useEffect(() => {
    void fetchAdminKeywordRankSettings()
      .then((data) => setExtensionInstallUrl(data.extensionInstallUrl ?? ""))
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải cấu hình keyword rank."))
      .finally(() => setLoading(false));
  }, []);

  async function handleSave() {
    try {
      setSaving(true);
      const saved = await updateAdminKeywordRankSettings({ extensionInstallUrl });
      setExtensionInstallUrl(saved.extensionInstallUrl ?? "");
      toast.success("Đã lưu cấu hình keyword rank.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu cấu hình.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải..." description="" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Keyword Rank Settings"
        description="Cấu hình extension bắt buộc cho check thứ hạng từ khoá."
        breadcrumbs={[{ label: "Admin", href: "/admin" }, { label: "Keyword Rank Settings" }]}
      />

      <Card className="max-w-3xl">
        <CardHeader>
          <CardTitle>Extension Chrome</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="extension-install-url">Link cài đặt extension</Label>
            <Input
              id="extension-install-url"
              placeholder="https://chromewebstore.google.com/detail/..."
              value={extensionInstallUrl}
              onChange={(event) => setExtensionInstallUrl(event.target.value)}
            />
            <p className="text-sm text-muted-foreground">
              User chưa cài extension sẽ thấy banner yêu cầu cài đặt. Bấm vào banner sẽ mở link này. Nút Run bị vô hiệu hoá cho đến khi extension được phát hiện.
            </p>
          </div>
          <div className="rounded-xl border border-border bg-background/70 p-4 text-sm text-muted-foreground">
            Quét Google cố định tối thiểu 10 trang SERP theo thứ tự. Dừng ngay khi tìm thấy domain khớp.
          </div>
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button disabled={saving} onClick={() => void handleSave()}>
          <Save className="size-4" />
          {saving ? "Đang lưu..." : "Lưu"}
        </Button>
      </div>
    </div>
  );
}
