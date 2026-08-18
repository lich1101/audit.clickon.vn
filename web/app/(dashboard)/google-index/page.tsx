"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { AlertCircle, BookOpen, Download, FileSpreadsheet, Globe, Loader2, Play, SearchCheck, Upload } from "lucide-react";

import { IndexProjectChart } from "@/components/dashboard/index-project-chart";
import { IndexSettingsDialog } from "@/components/dashboard/index-settings-dialog";
import { IndexUrlListDialog } from "@/components/dashboard/index-url-list-dialog";
import { PageHeader } from "@/components/layout/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useAuth } from "@/hooks/use-auth";
import { downloadIndexImportTemplate, downloadIndexReport, readUrlsFromXlsx, XLSX_ACCEPT } from "@/lib/index-excel";
import {
  fetchIndexProperties,
  fetchIndexPropertyReport,
  fetchIndexSettings,
  importIndexLinksGlobal,
  previewIndexLinks,
  runIndexPublish,
  saveIndexSettings,
  syncIndexFromGsc
} from "@/lib/index";
import { formatNumber } from "@/lib/utils";
import type { IndexPreview, IndexProperty, IndexQuotaStatus, IndexUrlView } from "@/types";

const STATS_POLL_MS = 5000;
const GSC_SYNC_MS = 15 * 60 * 1000;

export default function GoogleIndexPage() {
  const { profile } = useAuth();
  const [properties, setProperties] = useState<IndexProperty[]>([]);
  const [quota, setQuota] = useState<IndexQuotaStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedPropertyId, setSelectedPropertyId] = useState<number | null>(null);
  const [configured, setConfigured] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [excelLoading, setExcelLoading] = useState(false);
  const [exportingId, setExportingId] = useState<number | null>(null);
  const [syncing, setSyncing] = useState(false);
  const [switchingLive, setSwitchingLive] = useState(false);
  const excelInputRef = useRef<HTMLInputElement>(null);

  const [linksText, setLinksText] = useState("");
  const [preview, setPreview] = useState<IndexPreview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [urlView, setUrlView] = useState<IndexUrlView | null>(null);

  const totalPending = useMemo(
    () => properties.reduce((sum, row) => sum + row.pendingCount + row.sendingCount, 0),
    [properties]
  );
  const totalIndexed = useMemo(() => properties.reduce((sum, row) => sum + row.sentCount, 0), [properties]);
  const ownedCount = useMemo(() => properties.filter((row) => row.isOwned).length, [properties]);

  const loadData = useCallback(async () => {
    const response = await fetchIndexProperties();
    setProperties(response.data);
    setQuota(response.quota);
    setSelectedPropertyId((current) => current ?? response.data[0]?.id ?? null);
  }, []);

  const syncProjects = useCallback(async (silent = true) => {
    try {
      const result = await syncIndexFromGsc();
      await loadData();
      const changed = (result.created ?? 0) + (result.revoked ?? 0);
      if (!silent || changed > 0) {
        toast.info(String(result.message ?? "Đã đồng bộ Google Search Console."));
      }
    } catch {
      if (!silent) {
        toast.error("Không đồng bộ được website từ GSC.");
      }
    }
  }, [loadData]);

  useEffect(() => {
    if (!profile) {
      return;
    }

    setLoading(true);
    void (async () => {
      try {
        const settings = await fetchIndexSettings();
        setConfigured(settings.data.configured);
        if (settings.data.configured) {
          await syncProjects(true);
        } else {
          await loadData();
        }
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Không tải được dữ liệu lập chỉ mục.");
      } finally {
        setLoading(false);
      }
    })();
  }, [profile, loadData, syncProjects]);

  useEffect(() => {
    if (!profile) {
      return;
    }

    const statsId = window.setInterval(() => {
      void loadData().catch(() => undefined);
    }, STATS_POLL_MS);

    const syncId = window.setInterval(() => {
      if (configured) {
        void syncProjects(true);
      }
    }, GSC_SYNC_MS);

    return () => {
      window.clearInterval(statsId);
      window.clearInterval(syncId);
    };
  }, [profile, configured, loadData, syncProjects]);

  async function handleExcelChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    setExcelLoading(true);
    try {
      const text = await readUrlsFromXlsx(file);
      if (!text.trim()) {
        toast.error("File XLSX không có URL. Dùng mẫu và điền vào cột url.");
        return;
      }

      setLinksText(text);
      const response = await previewIndexLinks(text);
      setPreview(response.data);
      toast.success(`Đã đọc ${file.name}: ${formatNumber(response.data.count)} URL.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không đọc được file XLSX.");
    } finally {
      setExcelLoading(false);
      event.target.value = "";
    }
  }

  async function handlePreview() {
    if (!linksText.trim()) {
      toast.error("Nhập danh sách URL hoặc import file XLSX trước khi preview.");
      return;
    }

    setPreviewLoading(true);
    try {
      const response = await previewIndexLinks(linksText);
      setPreview(response.data);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Preview thất bại.");
    } finally {
      setPreviewLoading(false);
    }
  }

  async function handleImport() {
    if (!linksText.trim()) {
      toast.error("Nhập danh sách URL hoặc import file XLSX.");
      return;
    }

    setSubmitting(true);
    try {
      const result = await importIndexLinksGlobal(linksText);

      if (!result.ok) {
        toast.error(String(result.message ?? "Import thất bại."));
        return;
      }

      toast.success(String(result.message ?? "Import thành công."));
      setLinksText("");
      setPreview(null);
      await loadData();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Import thất bại.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handlePublishNow() {
    setPublishing(true);
    try {
      const result = await runIndexPublish(50);
      if (!result.ok) {
        toast.error(String(result.message ?? "Gửi lập chỉ mục thất bại."));
        return;
      }

      toast.success(String(result.message ?? "Đã gửi lập chỉ mục."));
      await loadData();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Gửi lập chỉ mục thất bại.");
    } finally {
      setPublishing(false);
    }
  }

  async function handleManualSync() {
    setSyncing(true);
    try {
      await syncProjects(false);
    } finally {
      setSyncing(false);
    }
  }

  async function handleEnableLive() {
    setSwitchingLive(true);
    try {
      const result = await saveIndexSettings({ dryRun: false });
      if (!result.ok) {
        toast.error(String(result.message ?? "Không bật được LIVE."));
        return;
      }

      toast.success("Đã bật gửi lập chỉ mục Google thật (LIVE).");
      await loadData();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không bật được LIVE.");
    } finally {
      setSwitchingLive(false);
    }
  }

  async function handleExport(property: IndexProperty) {
    setExportingId(property.id);
    try {
      const response = await fetchIndexPropertyReport(property.id);
      downloadIndexReport(response.data.property, response.data.urls);
      toast.success(`Đã xuất báo cáo ${property.name}.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Xuất báo cáo thất bại.");
    } finally {
      setExportingId(null);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-start justify-between gap-3">
        <PageHeader
          title="Lập chỉ mục Google"
          breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Lập chỉ mục Google" }]}
        />
        <div className="flex shrink-0 items-center gap-2">
          <Button asChild variant="outline" className="rounded-xl">
            <a
              href="https://docs.google.com/document/d/15wujqtlDxpy2NStqrRkdb63W1T30pLmaMqpPk3U0zlk/edit?tab=t.0#heading=h.ldu7f67up42z"
              target="_blank"
              rel="noopener noreferrer"
            >
              <BookOpen className="size-4" />
              Hướng dẫn
            </a>
          </Button>
          <IndexSettingsDialog
            siteCount={ownedCount}
            onUpdated={() => {
              void loadData();
              void fetchIndexSettings()
                .then((response) => setConfigured(response.data.configured))
                .catch(() => undefined);
            }}
          />
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Dự án</CardDescription>
            <CardTitle>{formatNumber(properties.length)}</CardTitle>
          </CardHeader>
        </Card>
        <button type="button" className="text-left" onClick={() => setUrlView("indexed")}>
          <Card className="h-full transition hover:border-primary/50 hover:shadow-sm">
            <CardHeader className="pb-2">
              <CardDescription>Đã lập chỉ mục</CardDescription>
              <CardTitle>{formatNumber(totalIndexed)}</CardTitle>
            </CardHeader>
          </Card>
        </button>
        <button type="button" className="text-left" onClick={() => setUrlView("pending")}>
          <Card className="h-full transition hover:border-primary/50 hover:shadow-sm">
            <CardHeader className="pb-2">
              <CardDescription>Chưa lập chỉ mục</CardDescription>
              <CardTitle>{formatNumber(totalPending)}</CardTitle>
            </CardHeader>
          </Card>
        </button>
        <button type="button" className="text-left" onClick={() => setUrlView("quota_today")}>
          <Card className="h-full transition hover:border-primary/50 hover:shadow-sm">
            <CardHeader className="pb-2">
              <CardDescription>Quota publish hôm nay</CardDescription>
              <CardTitle>{quota ? `${quota.publish.used}/${quota.publish.limit}` : "—"}</CardTitle>
            </CardHeader>
          </Card>
        </button>
      </div>

      {quota?.dryRun ? (
        <div className="flex flex-col gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-950 dark:text-amber-100 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-start gap-3">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <div>
              <p className="font-medium">Đang mô phỏng (DRY_RUN)</p>
              <p className="mt-1 text-muted-foreground">Google chưa nhận URL. Bật LIVE để gửi thật.</p>
            </div>
          </div>
          <Button type="button" size="sm" disabled={switchingLive || !configured} onClick={() => void handleEnableLive()}>
            {switchingLive ? <Loader2 className="size-4 animate-spin" /> : null}
            Bật gửi lập chỉ mục thật
          </Button>
        </div>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Tiến độ lập chỉ mục</CardTitle>
        </CardHeader>
        <CardContent>
          <IndexProjectChart properties={properties} onOpenView={setUrlView} />
        </CardContent>
      </Card>

      <div className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Upload className="size-4" />
              Import URL
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="links">Danh sách URL</Label>
              <Textarea
                id="links"
                rows={10}
                placeholder={"https://example.com/page-1\nhttps://example.com/page-2"}
                value={linksText}
                onChange={(event) => setLinksText(event.target.value)}
              />
            </div>

            <div className="flex flex-wrap gap-2">
              <input
                ref={excelInputRef}
                type="file"
                accept={XLSX_ACCEPT}
                className="hidden"
                onChange={(event) => void handleExcelChange(event)}
              />
              <Button type="button" variant="outline" onClick={() => downloadIndexImportTemplate()}>
                <Download className="size-4" />
                Tải mẫu XLSX
              </Button>
              <Button type="button" variant="outline" disabled={excelLoading} onClick={() => excelInputRef.current?.click()}>
                {excelLoading ? <Loader2 className="size-4 animate-spin" /> : <FileSpreadsheet className="size-4" />}
                Import XLSX
              </Button>
              <Button type="button" variant="secondary" disabled={previewLoading} onClick={() => void handlePreview()}>
                {previewLoading ? <Loader2 className="size-4 animate-spin" /> : <SearchCheck className="size-4" />}
                Preview
              </Button>
              <Button type="button" disabled={submitting} onClick={() => void handleImport()}>
                {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
                Import & gửi lập chỉ mục
              </Button>
              <Button type="button" variant="secondary" disabled={publishing || totalPending === 0} onClick={() => void handlePublishNow()}>
                {publishing ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />}
                Gửi lập chỉ mục đang chờ
              </Button>
            </div>

            {preview ? (
              <div className="rounded-xl border border-border/70 bg-secondary/30 p-4 text-sm">
                <p className="font-medium">{formatNumber(preview.count)} URL hợp lệ · {preview.groups.length} site</p>
                <div className="mt-3 space-y-2">
                  {preview.groups.map((group) => (
                    <div key={group.siteHost} className="rounded-lg border border-border/60 bg-background/70 px-3 py-2">
                      <div className="flex items-center gap-2">
                        <Globe className="size-3.5 text-muted-foreground" />
                        <span className="font-medium">{group.siteOrigin}</span>
                        <Badge variant="secondary">{group.urls.length} URL</Badge>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-start justify-between gap-3">
            <div>
              <CardTitle>Dự án hiện có</CardTitle>
            </div>
            <Button type="button" size="sm" variant="outline" disabled={syncing || !configured} onClick={() => void handleManualSync()}>
              {syncing ? <Loader2 className="size-4 animate-spin" /> : null}
              Đồng bộ ngay
            </Button>
          </CardHeader>
          <CardContent>
            {loading ? (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Đang tải...
              </div>
            ) : properties.length === 0 ? (
              <p className="text-sm text-muted-foreground">Chưa có dự án. Mở ⚙ để cấu hình JSON.</p>
            ) : (
              <div className="max-h-[28rem] space-y-3 overflow-y-auto pr-1">
                {properties.map((property) => {
                const active = property.id === selectedPropertyId;
                return (
                  <div
                    key={property.id}
                    className={`rounded-xl border px-4 py-3 ${
                      active ? "border-primary bg-primary/5" : "border-border/70"
                    } ${property.isOwned ? "" : "border-red-300/80 bg-red-50/60 dark:border-red-500/40 dark:bg-red-500/10"}`}
                  >
                    <button type="button" className="w-full text-left" onClick={() => setSelectedPropertyId(property.id)}>
                      <div className="flex items-center justify-between gap-3">
                        <div className="min-w-0">
                          <p className={`truncate font-medium ${property.isOwned ? "" : "text-red-700 dark:text-red-300"}`}>
                            {property.name}
                          </p>
                          <p className="truncate text-xs text-muted-foreground">{property.siteOrigin}</p>
                        </div>
                        <Badge variant={property.isOwned ? "success" : "destructive"}>
                          {property.isOwned ? "Owned" : "Mất quyền"}
                        </Badge>
                      </div>
                      <div className="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                        <span>{formatNumber(property.sentCount)} đã lập chỉ mục</span>
                        <span>·</span>
                        <span>{formatNumber(property.pendingCount + property.sendingCount)} chưa</span>
                        <span>·</span>
                        <span>{formatNumber(property.failedCount)} lỗi</span>
                      </div>
                    </button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className="mt-3 w-full"
                      disabled={exportingId === property.id}
                      onClick={() => void handleExport(property)}
                    >
                      {exportingId === property.id ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
                      Xuất báo cáo
                    </Button>
                  </div>
                );
              })}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
      <IndexUrlListDialog view={urlView} onClose={() => setUrlView(null)} />
    </div>
  );
}
