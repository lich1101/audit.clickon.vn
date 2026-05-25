"use client";

import { CircleCheckBig, FileSpreadsheet, ListChecks, TriangleAlert, Waves, Waypoints } from "lucide-react";
import { use, useEffect, useRef, useState } from "react";
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
import { getAuditRun, isActiveAuditRun, normalizeAuditRun } from "@/lib/audit-runs";
import { exportAuditRunToExcel } from "@/lib/audit-report";
import { getWebsiteById, listenToAuditRunSignal } from "@/lib/firestore";
import { formatDate, formatNumber } from "@/lib/utils";
import type { AuditRun, AuditRunItem, Website } from "@/types";

export default function AuditRunDetailPage({
  params
}: {
  params: Promise<{ id: string; runId: string }>;
}) {
  const { id, runId } = use(params);
  const { profile, refreshProfile } = useAuth();
  const [website, setWebsite] = useState<Website | null>(null);
  const [run, setRun] = useState<AuditRun | null>(null);
  const [items, setItems] = useState<AuditRunItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const runFinishedRef = useRef(false);
  const runLoadedRef = useRef(false);
  const lastProfileRefreshRef = useRef(0);
  const profileUid = profile?.uid;

  async function loadRun(options?: { silent?: boolean }) {
    try {
      if (!options?.silent) {
        setLoading(true);
      }

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
      if (!options?.silent) {
        toast.error(error instanceof Error ? error.message : "Không thể tải audit run.");
      }
    } finally {
      if (!options?.silent) {
        setLoading(false);
      }
    }
  }

  useEffect(() => {
    runLoadedRef.current = false;
  }, [id, runId]);

  useEffect(() => {
    if (!profileUid) {
      return;
    }

    void loadRun({ silent: runLoadedRef.current });
    runLoadedRef.current = true;
  }, [id, profileUid, runId]);

  useEffect(() => {
    if (!run || !isActiveAuditRun(run.status)) {
      return;
    }

    runFinishedRef.current = false;

    function refreshProfileFromAuditSignal(force = false) {
      const now = Date.now();

      if (!force && now - lastProfileRefreshRef.current < 5000) {
        return;
      }

      lastProfileRefreshRef.current = now;
      void refreshProfile().catch(() => undefined);
    }

    const unsubscribe = listenToAuditRunSignal(run.publicId, (liveRun) => {
      if (!liveRun) {
        return;
      }

      const normalizedRun = normalizeAuditRun(liveRun);
      setRun(normalizedRun);
      setItems(normalizedRun.items ?? []);
      refreshProfileFromAuditSignal(false);

      if (!isActiveAuditRun(liveRun.status) && !runFinishedRef.current) {
        runFinishedRef.current = true;
        refreshProfileFromAuditSignal(true);
        void loadRun({ silent: true });
      }
    });

    return () => {
      unsubscribe();
    };
  }, [run?.publicId, run?.status, refreshProfile]);

  async function handleExport() {
    if (!run) {
      return;
    }

    try {
      setExporting(true);
      await exportAuditRunToExcel({ ...run, items });
      toast.success("Đã xuất báo cáo Excel.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể xuất file Excel.");
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải audit run..." description="Đang lấy dữ liệu từ MySQL qua API." />;
  }

  if (!website || !run) {
    return <EmptyState title="Không tìm thấy audit run" description="Run không tồn tại hoặc không thuộc website hiện tại." />;
  }

  const progressPercent = run.totalUrls > 0 ? Math.min(100, Math.round((run.processedUrls / run.totalUrls) * 100)) : 0;
  const completedCount = items.filter((item) => item.status === "completed").length;
  const failedCount = items.filter((item) => item.status === "failed").length;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Run #${run.publicId.slice(-8)}`}
        description="Chi tiết audit run — dữ liệu chính từ MySQL, Firebase chỉ bắn tín hiệu cập nhật khi đang chạy."
        breadcrumbs={[
          { label: "Websites", href: "/websites" },
          { label: website.name, href: `/websites/${website.id}` },
          { label: "Audit", href: `/websites/${website.id}/audit` },
          { label: "Run detail" }
        ]}
      />

      <div className="grid gap-4 lg:grid-cols-5">
        <StatCard title="Tổng URL" value={formatNumber(run.totalUrls)} icon={Waypoints} />
        <StatCard title="Đã xử lý" value={formatNumber(run.processedUrls)} hint={`${progressPercent}%`} icon={Waves} />
        <StatCard title="Hoàn tất" value={formatNumber(completedCount)} icon={CircleCheckBig} />
        <StatCard title="Lỗi" value={formatNumber(failedCount)} icon={TriangleAlert} />
        <StatCard title="Checklist" value={run.checklistText?.trim() ? "Tùy chỉnh" : "Mặc định"} icon={ListChecks} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-3">
            Trạng thái run
            <AuditStatusBadge status={run.status} />
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <ProgressBar className="h-3" value={progressPercent} />
          <p className="text-sm text-muted-foreground">
            Tạo {formatDate(run.createdAt)} · AI {run.aiProvider ?? "openai"} · B2 {run.step2AiModel ?? run.aiModel ?? "default"} · B3 {run.step3AiModel ?? run.aiModel ?? "default"}
          </p>
        </CardContent>
      </Card>

      <AuditRunItemsTable run={run} items={items} onExport={handleExport} exporting={exporting} />
    </div>
  );
}
