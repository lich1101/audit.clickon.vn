"use client";

import { useEffect, useRef, useState } from "react";
import { Loader2, RefreshCw, Save, Settings } from "lucide-react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { fetchIndexSettings, saveIndexSettings, syncIndexFromGsc, testIndexSettings } from "@/lib/index";
import type { IndexGscSite, IndexSettings } from "@/types";

export function IndexSettingsDialog({
  onUpdated,
  siteCount
}: {
  onUpdated?: () => void | Promise<void>;
  siteCount?: number;
}) {
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [settings, setSettings] = useState<IndexSettings | null>(null);
  const [serviceAccountJson, setServiceAccountJson] = useState("");
  const [sites, setSites] = useState<IndexGscSite[]>([]);
  const fileInputRef = useRef<HTMLInputElement>(null);

  async function loadSettings() {
    setLoading(true);
    try {
      const response = await fetchIndexSettings();
      setSettings(response.data);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không tải được cấu hình.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (!open) {
      return;
    }

    void loadSettings();
  }, [open]);

  async function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    try {
      const text = await file.text();
      setServiceAccountJson(text);
      toast.success(`Đã đọc file ${file.name}.`);
    } catch {
      toast.error("Không đọc được file JSON.");
    } finally {
      event.target.value = "";
    }
  }

  async function setLiveMode(live: boolean) {
    setSaving(true);
    try {
      const result = await saveIndexSettings({ dryRun: !live });
      if (!result.ok || !result.settings) {
        toast.error(String(result.message ?? "Không cập nhật được chế độ gửi."));
        return;
      }

      setSettings(result.settings);
      toast.success(live ? "Đã bật LIVE." : "Đã bật DRY_RUN.");
      await onUpdated?.();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không cập nhật được chế độ gửi.");
    } finally {
      setSaving(false);
    }
  }

  async function handleSave() {
    if (!serviceAccountJson.trim()) {
      toast.error("Chọn hoặc dán file JSON trước khi lưu.");
      return;
    }

    setSaving(true);
    try {
      const result = await saveIndexSettings({
        serviceAccountJson: serviceAccountJson.trim(),
        dryRun: settings?.dryRun ?? true
      });

      if (!result.ok) {
        toast.error(String(result.message ?? "Lưu cấu hình thất bại."));
        return;
      }

      if (result.settings) {
        setSettings(result.settings);
      }
      if (result.test?.sites) {
        setSites(result.test.sites);
      }

      setServiceAccountJson("");
      toast.success(String(result.message ?? "Đã lưu JSON."));
      await onUpdated?.();
      await loadSettings();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Lưu cấu hình thất bại.");
    } finally {
      setSaving(false);
    }
  }

  async function handleTest() {
    setTesting(true);
    try {
      const response = await testIndexSettings();
      setSites(response.data.sites ?? []);
      toast.success(response.data.message ?? "Kết nối GSC thành công.");
      await onUpdated?.();
      await loadSettings();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Test kết nối thất bại.");
    } finally {
      setTesting(false);
    }
  }

  async function handleSyncGsc() {
    setSyncing(true);
    try {
      const result = await syncIndexFromGsc();
      toast.success(String(result.message ?? "Đã đồng bộ GSC."));
      await onUpdated?.();
      await loadSettings();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Đồng bộ GSC thất bại.");
    } finally {
      setSyncing(false);
    }
  }

  const isLive = settings?.configured ? settings.dryRun === false : false;
  const websiteCount = siteCount !== undefined ? siteCount : (settings?.gscSiteCount ?? 0);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button type="button" variant="outline" size="icon" className="rounded-xl" aria-label="Cấu hình Google API">
          <Settings className="size-4" />
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>Cấu hình Google API</DialogTitle>
        </DialogHeader>

        <div className="space-y-4">
          <div className="rounded-xl border border-border/70 bg-secondary/20 p-4 text-sm">
            {loading ? (
              <div className="flex items-center gap-2 text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Đang tải...
              </div>
            ) : settings?.configured ? (
              <div className="space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge>Đã cấu hình</Badge>
                  {isLive ? <Badge>LIVE</Badge> : <Badge variant="secondary">DRY_RUN</Badge>}
                </div>
                <p className="truncate text-xs">{settings.serviceAccountEmail}</p>
                {settings.projectId ? <p className="text-xs text-muted-foreground">GCP: {settings.projectId}</p> : null}
                <p className="text-muted-foreground">Website trong GSC: {websiteCount}</p>
              </div>
            ) : (
              <p className="text-muted-foreground">Chưa có JSON Service Account.</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="serviceAccountJson">Service Account JSON</Label>
            <Textarea
              id="serviceAccountJson"
              rows={6}
              placeholder='{"type":"service_account","client_email":"..."}'
              value={serviceAccountJson}
              onChange={(event) => setServiceAccountJson(event.target.value)}
            />
            <input ref={fileInputRef} type="file" accept=".json,application/json" className="hidden" onChange={handleFileChange} />
            <Button type="button" variant="secondary" size="sm" onClick={() => fileInputRef.current?.click()}>
              Chọn file JSON
            </Button>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button type="button" variant={isLive ? "default" : "outline"} disabled={saving || !settings?.configured} onClick={() => void setLiveMode(true)}>
              LIVE
            </Button>
            <Button type="button" variant={!isLive ? "secondary" : "outline"} disabled={saving || !settings?.configured} onClick={() => void setLiveMode(false)}>
              DRY_RUN
            </Button>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button type="button" disabled={saving} onClick={() => void handleSave()}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Lưu JSON
            </Button>
            <Button type="button" variant="secondary" disabled={testing || !settings?.configured} onClick={() => void handleTest()}>
              {testing ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
              Test GSC
            </Button>
            <Button type="button" variant="outline" disabled={syncing || !settings?.configured} onClick={() => void handleSyncGsc()}>
              {syncing ? <Loader2 className="size-4 animate-spin" /> : null}
              Đồng bộ GSC
            </Button>
          </div>

          {sites.length > 0 ? (
            <div className="max-h-48 space-y-2 overflow-y-auto text-sm">
              {sites.map((site) => (
                <div key={site.siteUrl} className="flex items-center justify-between gap-3 rounded-lg border border-border/60 px-3 py-2">
                  <span className="truncate">{site.siteUrl}</span>
                  <Badge variant="secondary">{site.permissionLevel ?? "unknown"}</Badge>
                </div>
              ))}
            </div>
          ) : null}
        </div>
      </DialogContent>
    </Dialog>
  );
}
