"use client";

import { RefreshCw, Save } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { fetchAdminKeywordRankProxyPool, refreshKeywordRankProxiesForRun } from "@/lib/keyword-rank-proxies";
import { fetchAdminKeywordRankSettings, updateAdminKeywordRankSettings } from "@/lib/keyword-rank-settings";
import { formatDate } from "@/lib/utils";

const HTTP_SOURCE = "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt";
const SOCKS5_SOURCE = "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5.txt";

export default function AdminKeywordRankSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [extensionInstallUrl, setExtensionInstallUrl] = useState("");
  const [proxyLoading, setProxyLoading] = useState(false);
  const [proxyFetchedAt, setProxyFetchedAt] = useState<string | null>(null);
  const [proxyHttpCount, setProxyHttpCount] = useState(0);
  const [proxySocks5Count, setProxySocks5Count] = useState(0);
  const [proxyTotalCount, setProxyTotalCount] = useState(0);
  const [proxyListText, setProxyListText] = useState("");

  async function loadProxyPool() {
    try {
      setProxyLoading(true);
      const pool = await fetchAdminKeywordRankProxyPool();
      setProxyFetchedAt(pool.fetchedAt);
      setProxyHttpCount(pool.httpCount);
      setProxySocks5Count(pool.socks5Count);
      setProxyTotalCount(pool.totalCount);
      setProxyListText(pool.proxies.join("\n"));
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tải danh sách proxy.");
    } finally {
      setProxyLoading(false);
    }
  }

  useEffect(() => {
    void Promise.all([
      fetchAdminKeywordRankSettings().then((data) => setExtensionInstallUrl(data.extensionInstallUrl ?? "")),
      loadProxyPool(),
    ])
      .catch(() => undefined)
      .finally(() => setLoading(false));
  }, []);

  async function handleRefreshProxySources() {
    try {
      setProxyLoading(true);
      const refreshed = await refreshKeywordRankProxiesForRun();
      toast.success(refreshed.message);
      await loadProxyPool();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tải proxy từ GitHub.");
    } finally {
      setProxyLoading(false);
    }
  }

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
        description="Cấu hình extension và xem pool proxy tự động khi user bấm Run."
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

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
          <div>
            <CardTitle>Pool proxy (chỉ admin xem)</CardTitle>
            <p className="mt-1 text-sm text-muted-foreground">
              User bấm Run → server tải mới từ GitHub. Lần fetch gần nhất:{" "}
              {proxyFetchedAt ? formatDate(proxyFetchedAt) : "chưa có"}
              {proxyTotalCount > 0
                ? ` · HTTP ${proxyHttpCount} · SOCKS5 ${proxySocks5Count} · Lưu ${proxyTotalCount} (mỗi Run dùng ~120 proxy xoay)`
                : ""}
            </p>
          </div>
          <div className="flex gap-2">
            <Button type="button" variant="outline" disabled={proxyLoading} onClick={() => void loadProxyPool()}>
              <RefreshCw className="size-4" />
              Xem pool đã lưu
            </Button>
            <Button type="button" variant="secondary" disabled={proxyLoading} onClick={() => void handleRefreshProxySources()}>
              <RefreshCw className="size-4" />
              {proxyLoading ? "Đang cào GitHub..." : "Cào mới từ GitHub"}
            </Button>
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-2 text-sm text-muted-foreground md:grid-cols-2">
            <p>
              HTTP:{" "}
              <a className="text-primary underline-offset-4 hover:underline" href={HTTP_SOURCE} target="_blank" rel="noreferrer">
                {HTTP_SOURCE}
              </a>
            </p>
            <p>
              SOCKS5:{" "}
              <a className="text-primary underline-offset-4 hover:underline" href={SOCKS5_SOURCE} target="_blank" rel="noreferrer">
                {SOCKS5_SOURCE}
              </a>
            </p>
          </div>
          <Textarea readOnly rows={16} value={proxyListText} className="font-mono text-xs" placeholder="Chưa có proxy — chờ user Run hoặc bấm Tải lại pool." />
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
