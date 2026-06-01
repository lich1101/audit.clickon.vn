"use client";

import { RefreshCw, Save } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  fetchAdminKeywordRankProxySettings,
  refreshAdminKeywordRankGithubPool,
  updateAdminKeywordRankProxySettings,
} from "@/lib/keyword-rank-proxies";
import { fetchAdminKeywordRankSettings, updateAdminKeywordRankSettings } from "@/lib/keyword-rank-settings";
import { formatDate } from "@/lib/utils";

const HTTP_SOURCE = "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt";
const SOCKS5_SOURCE = "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5.txt";

export default function AdminKeywordRankSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [proxySaving, setProxySaving] = useState(false);
  const [extensionInstallUrl, setExtensionInstallUrl] = useState("");

  const [proxyEnabled, setProxyEnabled] = useState(false);
  const [useGithubHttp, setUseGithubHttp] = useState(true);
  const [useGithubSocks5, setUseGithubSocks5] = useState(true);
  const [refreshGithubOnRun, setRefreshGithubOnRun] = useState(true);
  const [runSampleSize, setRunSampleSize] = useState(120);
  const [manualProxiesText, setManualProxiesText] = useState("");
  const [proxyLoading, setProxyLoading] = useState(false);
  const [proxyFetchedAt, setProxyFetchedAt] = useState<string | null>(null);
  const [proxyHttpCount, setProxyHttpCount] = useState(0);
  const [proxySocks5Count, setProxySocks5Count] = useState(0);
  const [proxyTotalCount, setProxyTotalCount] = useState(0);
  const [poolPreviewText, setPoolPreviewText] = useState("");

  async function loadProxySettings() {
    setProxyLoading(true);
    try {
      const data = await fetchAdminKeywordRankProxySettings();
      const { config, pool } = data;
      setProxyEnabled(config.enabled);
      setUseGithubHttp(config.useGithubHttp);
      setUseGithubSocks5(config.useGithubSocks5);
      setRefreshGithubOnRun(config.refreshGithubOnRun);
      setRunSampleSize(config.runSampleSize);
      setManualProxiesText(config.manualProxiesText || config.manualProxies.join("\n"));
      setProxyFetchedAt(pool.fetchedAt);
      setProxyHttpCount(pool.httpCount);
      setProxySocks5Count(pool.socks5Count);
      setProxyTotalCount(pool.totalCount);
      setPoolPreviewText(pool.proxies.slice(0, 200).join("\n"));
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tải cấu hình proxy.");
    } finally {
      setProxyLoading(false);
    }
  }

  useEffect(() => {
    void Promise.all([
      fetchAdminKeywordRankSettings().then((data) => setExtensionInstallUrl(data.extensionInstallUrl ?? "")),
      loadProxySettings(),
    ])
      .catch(() => undefined)
      .finally(() => setLoading(false));
  }, []);

  async function handleSaveExtension() {
    try {
      setSaving(true);
      const saved = await updateAdminKeywordRankSettings({ extensionInstallUrl });
      setExtensionInstallUrl(saved.extensionInstallUrl ?? "");
      toast.success("Đã lưu cấu hình extension.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu cấu hình.");
    } finally {
      setSaving(false);
    }
  }

  async function handleSaveProxyConfig() {
    if (proxyEnabled && !useGithubHttp && !useGithubSocks5 && !manualProxiesText.trim()) {
      toast.error("Bật proxy cần ít nhất một nguồn GitHub hoặc proxy thủ công.");
      return;
    }

    try {
      setProxySaving(true);
      const { data: saved } = await updateAdminKeywordRankProxySettings({
        enabled: proxyEnabled,
        useGithubHttp,
        useGithubSocks5,
        refreshGithubOnRun,
        runSampleSize,
        manualProxiesText,
      });
      setProxyEnabled(saved.enabled);
      setManualProxiesText(saved.manualProxiesText ?? saved.manualProxies.join("\n"));
      toast.success("Đã lưu cấu hình proxy (chỉ admin). User không thể sửa.");
      await loadProxySettings();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu cấu hình proxy.");
    } finally {
      setProxySaving(false);
    }
  }

  async function handleRefreshGithubPool() {
    if (!useGithubHttp && !useGithubSocks5) {
      toast.error("Bật ít nhất một nguồn GitHub (HTTP hoặc SOCKS5) trước khi cào.");
      return;
    }

    try {
      setProxyLoading(true);
      const refreshed = await refreshAdminKeywordRankGithubPool();
      toast.success(refreshed.message);
      await loadProxySettings();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể cào proxy từ GitHub.");
    } finally {
      setProxyLoading(false);
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải..." description="" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Keyword Rank Settings"
        description="Admin cấu hình extension và toàn bộ chính sách proxy check rank. User chỉ bấm Run — không nhập proxy."
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
              User chưa cài extension sẽ thấy banner yêu cầu cài đặt. Nút Run bị vô hiệu hoá cho đến khi extension được phát hiện.
            </p>
          </div>
          <div className="rounded-xl border border-border bg-background/70 p-4 text-sm text-muted-foreground">
            Quét Google cố định tối thiểu 10 trang SERP theo thứ tự. Dừng ngay khi tìm thấy domain khớp.
          </div>
          <div className="flex justify-end">
            <Button disabled={saving} onClick={() => void handleSaveExtension()}>
              <Save className="size-4" />
              {saving ? "Đang lưu..." : "Lưu extension"}
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Cấu hình proxy (chỉ admin)</CardTitle>
          <p className="text-sm text-muted-foreground">
            User không thấy và không sửa được proxy. Khi bật, mỗi lần Run extension nhận danh sách do server cấp theo cấu hình dưới đây và chỉ
            xoay proxy cho traffic Google SERP/captcha, không áp vào toàn bộ web app Clickon.
          </p>
        </CardHeader>
        <CardContent className="space-y-6">
          <label className="flex items-start gap-3 rounded-2xl border border-border bg-background/70 p-4 text-sm">
            <Checkbox checked={proxyEnabled} onChange={(event) => setProxyEnabled(event.target.checked)} />
              <span>
                <span className="block font-medium">Bật proxy khi user chạy check rank</span>
                <span className="mt-1 block text-muted-foreground">
                Tắt: Run dùng IP trình duyệt của user (dễ bị Google 429 nếu list lớn). Bật: extension xoay proxy trước mỗi keyword nhưng chỉ cho
                request Google/captcha.
              </span>
            </span>
          </label>

          <div className="grid gap-4 md:grid-cols-2">
            <label className="flex items-start gap-3 rounded-xl border border-border p-3 text-sm">
              <Checkbox checked={useGithubHttp} onChange={(event) => setUseGithubHttp(event.target.checked)} disabled={!proxyEnabled} />
              <span>Dùng pool GitHub HTTP</span>
            </label>
            <label className="flex items-start gap-3 rounded-xl border border-border p-3 text-sm">
              <Checkbox checked={useGithubSocks5} onChange={(event) => setUseGithubSocks5(event.target.checked)} disabled={!proxyEnabled} />
              <span>Dùng pool GitHub SOCKS5</span>
            </label>
            <label className="flex items-start gap-3 rounded-xl border border-border p-3 text-sm md:col-span-2">
              <Checkbox
                checked={refreshGithubOnRun}
                onChange={(event) => setRefreshGithubOnRun(event.target.checked)}
                disabled={!proxyEnabled || (!useGithubHttp && !useGithubSocks5)}
              />
              <span>
                Tải mới GitHub mỗi lần user bấm Run (nếu tắt: chỉ dùng pool đã cào sẵn + proxy thủ công)
              </span>
            </label>
          </div>

          <div className="grid gap-2 max-w-xs">
            <Label htmlFor="run-sample-size">Số proxy gửi extension mỗi Run</Label>
            <Input
              id="run-sample-size"
              type="number"
              min={10}
              max={300}
              disabled={!proxyEnabled}
              value={runSampleSize}
              onChange={(event) => setRunSampleSize(Math.max(10, Math.min(300, Number(event.target.value) || 120)))}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="manual-proxies">Proxy thủ công (mỗi dòng một proxy)</Label>
            <Textarea
              id="manual-proxies"
              rows={10}
              disabled={!proxyEnabled}
              className="font-mono text-xs"
              placeholder={"http://1.2.3.4:8080\nsocks5://5.6.7.8:1080\nuser:pass@host:3128"}
              value={manualProxiesText}
              onChange={(event) => setManualProxiesText(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Luôn được gộp vào pool Run khi bật proxy. Hỗ trợ http/https/socks4/socks5 hoặc dạng host:port (mặc định http).
            </p>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button type="button" disabled={proxySaving} onClick={() => void handleSaveProxyConfig()}>
              <Save className="size-4" />
              {proxySaving ? "Đang lưu proxy..." : "Lưu cấu hình proxy"}
            </Button>
            <Button type="button" variant="secondary" disabled={proxyLoading} onClick={() => void handleRefreshGithubPool()}>
              <RefreshCw className="size-4" />
              {proxyLoading ? "Đang cào..." : "Cào GitHub vào pool"}
            </Button>
            <Button type="button" variant="outline" disabled={proxyLoading} onClick={() => void loadProxySettings()}>
              <RefreshCw className="size-4" />
              Tải lại
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Pool GitHub đã lưu (xem trước)</CardTitle>
          <p className="text-sm text-muted-foreground">
            Lần cào gần nhất: {proxyFetchedAt ? formatDate(proxyFetchedAt) : "chưa có"}
            {proxyTotalCount > 0 ? ` · HTTP ${proxyHttpCount} · SOCKS5 ${proxySocks5Count} · ${proxyTotalCount} proxy` : ""}
          </p>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-2 text-sm text-muted-foreground md:grid-cols-2">
            <p>
              HTTP:{" "}
              <a className="text-primary underline-offset-4 hover:underline" href={HTTP_SOURCE} target="_blank" rel="noreferrer">
                TheSpeedX http.txt
              </a>
            </p>
            <p>
              SOCKS5:{" "}
              <a className="text-primary underline-offset-4 hover:underline" href={SOCKS5_SOURCE} target="_blank" rel="noreferrer">
                TheSpeedX socks5.txt
              </a>
            </p>
          </div>
          <Textarea
            readOnly
            rows={12}
            value={poolPreviewText}
            className="font-mono text-xs"
            placeholder="Chưa có pool — bấm Cào GitHub hoặc đợi user Run (nếu bật tải mới mỗi Run)."
          />
          {proxyTotalCount > 200 ? (
            <p className="text-xs text-muted-foreground">Hiển thị 200/{proxyTotalCount} dòng đầu.</p>
          ) : null}
        </CardContent>
      </Card>
    </div>
  );
}
