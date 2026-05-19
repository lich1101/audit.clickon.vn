"use client";

import { CircleCheckBig, FileSpreadsheet, ListChecks, TriangleAlert, Waves, Waypoints } from "lucide-react";
import { use, useEffect, useState } from "react";
import { toast } from "sonner";

import { AuditRunItemsTable } from "@/components/dashboard/audit-run-items-table";
import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { ProgressBar } from "@/components/dashboard/progress-bar";
import { StatCard } from "@/components/dashboard/stat-card";
import { PageHeader } from "@/components/layout/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import { getAuditRun, isActiveAuditRun } from "@/lib/audit-runs";
import { exportAuditRunToExcel } from "@/lib/audit-report";
import { getWebsiteById, listenToAuditRun, listenToAuditRunItems } from "@/lib/firestore";
import { formatDate, formatNumber } from "@/lib/utils";
import type { AuditRun, AuditRunItem, Website } from "@/types";

export default function AuditRunDetailPage({
  params
}: {
  params: Promise<{ id: string; runId: string }>;
}) {
  const { id, runId } = use(params);
  const { profile } = useAuth();
  const [website, setWebsite] = useState<Website | null>(null);
  const [run, setRun] = useState<AuditRun | null>(null);
  const [items, setItems] = useState<AuditRunItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    async function load() {
      try {
        setLoading(true);
        const [nextWebsite, nextRun] = await Promise.all([getWebsiteById(id), getAuditRun(runId)]);

        if (!nextWebsite || nextWebsite.userId !== profile?.uid || nextRun.websiteId !== id) {
          setWebsite(null);
          setRun(null);
          setItems([]);
          return;
        }

        setWebsite(nextWebsite);
        setRun(nextRun);
        setItems(nextRun.items ?? []);
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Không thể tải audit run.");
      } finally {
        setLoading(false);
      }
    }

    if (profile) {
      void load();
    }
  }, [id, profile, runId]);

  useEffect(() => {
    if (!profile || !run || !isActiveAuditRun(run.status)) {
      return;
    }

    const unsubscribeRun = listenToAuditRun(
      runId,
      (nextRun) => {
        if (!nextRun || nextRun.websiteId !== id) {
          return;
        }

        setRun((current) => ({
          ...(current ?? nextRun),
          ...nextRun,
          items: current?.items ?? nextRun.items
        }));
      },
      (error) => toast.error(error.message || "Không thể lắng nghe trạng thái audit run.")
    );

    const unsubscribeItems = listenToAuditRunItems(
      runId,
      (nextItems) => {
        setItems(nextItems);
        setRun((current) => (current ? { ...current, items: nextItems } : current));
      },
      (error) => toast.error(error.message || "Không thể lắng nghe kết quả từng URL.")
    );

    return () => {
      unsubscribeRun();
      unsubscribeItems();
    };
  }, [id, profile, run, runId]);

  async function handleExport() {
    if (!run) {
      return;
    }

    try {
      setExporting(true);
      await exportAuditRunToExcel({
        ...run,
        items
      });
      toast.success("Đã xuất báo cáo Excel.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể xuất file Excel.");
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải audit run..." description="Đang lấy dữ liệu batch, kết quả từng URL và tiến độ realtime." />;
  }

  if (!website || !run) {
    return <EmptyState title="Không tìm thấy audit run" description="Audit run không tồn tại, không thuộc website hiện tại hoặc bạn không có quyền truy cập." action={{ label: "Về trang audit", href: `/websites/${id}/audit` }} />;
  }

  const progressPercent = run.totalUrls > 0 ? Math.min(100, Math.round((run.processedUrls / run.totalUrls) * 100)) : 0;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Audit run #${run.publicId.slice(-8)}`}
        description="Theo dõi queue fetch nội dung, AI phân tích SEO và trạng thái realtime của từng URL trong cùng một đợt audit."
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: website.name, href: `/websites/${website.id}` },
          { label: "Audit", href: `/websites/${website.id}/audit` },
          { label: `Run ${run.publicId.slice(-8)}` }
        ]}
      />

      <div className="grid gap-4 lg:grid-cols-4">
        <StatCard title="Tổng URL" value={formatNumber(run.totalUrls)} hint="Số URL mục tiêu trong đợt này" icon={Waypoints} />
        <StatCard title="Đã xử lý" value={formatNumber(run.processedUrls)} hint={`${progressPercent}% hoàn thành`} icon={Waves} />
        <StatCard title="Hoàn tất" value={formatNumber(run.completedUrls)} hint="Đã có kết quả AI hoàn chỉnh" icon={CircleCheckBig} />
        <StatCard title="Lỗi" value={formatNumber(run.failedUrls)} hint="URL fetch/analyze thất bại" icon={TriangleAlert} />
      </div>

      <div className="grid gap-5 xl:grid-cols-[1.05fr_0.95fr]">
        <Card>
          <CardHeader>
            <CardTitle>Tổng quan batch</CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="flex flex-wrap items-center gap-3">
              <AuditStatusBadge status={run.status} />
              <div className="rounded-full border border-border/70 px-3 py-1 text-xs uppercase tracking-[0.18em] text-muted-foreground">
                {progressPercent}% tiến độ
              </div>
            </div>
            <ProgressBar className="h-3" value={progressPercent} />
            <div className="grid gap-3 text-sm md:grid-cols-3">
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4">
                <p className="text-muted-foreground">Website</p>
                <p className="mt-1 font-medium">{website.name}</p>
                <p className="mt-2 text-xs text-muted-foreground break-all">{website.url}</p>
              </div>
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4">
                <p className="text-muted-foreground">Thời gian</p>
                <p className="mt-1 font-medium">Tạo lúc {formatDate(run.createdAt)}</p>
                <p className="mt-2 text-xs text-muted-foreground">
                  {run.completedAt ? `Kết thúc ${formatDate(run.completedAt)}` : `Cập nhật gần nhất ${formatDate(run.updatedAt)}`}
                </p>
              </div>
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4">
                <p className="text-muted-foreground">AI provider</p>
                <p className="mt-1 font-medium">{run.aiProvider ?? "openai"}</p>
                <p className="mt-2 text-xs text-muted-foreground">{run.aiModel ? `Model: ${run.aiModel}` : "Đang dùng model mặc định backend"}</p>
              </div>
            </div>
            <div className="rounded-xl border border-border/70 bg-secondary/35 px-4 py-4">
              <p className="text-sm font-medium">Input của đợt audit</p>
              <div className="mt-4 grid gap-3 md:grid-cols-4">
                <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-3">
                  <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">URL mục tiêu</p>
                  <p className="mt-2 text-2xl font-semibold">{run.targetUrls.length}</p>
                </div>
                <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-3">
                  <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">Danh mục</p>
                  <p className="mt-2 text-2xl font-semibold">{run.categories.length}</p>
                </div>
                <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-3">
                  <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">Context đã crawl</p>
                  <p className="mt-2 text-2xl font-semibold">{run.categoryContexts?.length ?? 0}</p>
                </div>
                <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-3">
                  <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">Item realtime</p>
                  <p className="mt-2 text-2xl font-semibold">{items.length}</p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ListChecks className="size-5 text-primary" />
              Checklist và danh mục áp dụng
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="rounded-xl border border-border/70 bg-background/70 p-4">
              <p className="text-sm font-medium">Checklist SEO</p>
              <p className="mt-3 whitespace-pre-line text-sm leading-6 text-muted-foreground">
                {run.checklistText?.trim() || "Đợt audit này đang dùng checklist mặc định ở backend."}
              </p>
            </div>
            <div className="rounded-xl border border-border/70 bg-background/70 p-4">
              <p className="text-sm font-medium">Danh mục mục tiêu</p>
              {run.categories.length ? (
                <div className="mt-3 grid gap-3">
                  {run.categories.map((category) => (
                    <div key={`${category.name}-${category.url}`} className="rounded-xl border border-border/70 bg-secondary/35 px-4 py-3">
                      <p className="font-medium">{category.name}</p>
                      <p className="mt-1 text-xs text-muted-foreground break-all">{category.url}</p>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mt-3 text-sm text-muted-foreground">Đợt audit này không có danh mục truyền vào.</p>
              )}
            </div>
            <div className="rounded-xl border border-border/70 bg-secondary/35 px-4 py-4">
              <div className="flex items-center gap-3">
                <div className="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
                  <FileSpreadsheet className="size-5" />
                </div>
                <div>
                  <p className="font-medium">Báo cáo đầu ra</p>
                  <p className="text-sm text-muted-foreground">
                    File Excel sẽ chứa đủ: URL mục tiêu, từ khóa SEO chính, danh mục, URL danh mục, điểm audit, đề xuất audit và định hướng chỉnh sửa.
                  </p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <AuditRunItemsTable run={run} items={items} onExport={handleExport} exporting={exporting} />
    </div>
  );
}
