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
import { ACTIVE_AUDIT_POLL_INTERVAL_MS, getAuditRun, isActiveAuditRun, normalizeAuditRun } from "@/lib/audit-runs";
import { exportAuditRunToExcel } from "@/lib/audit-report";
import { getWebsiteById, listenToAuditRunSignal } from "@/lib/firestore";
import { formatDate, formatNumber, formatUsd } from "@/lib/utils";
import type { AuditRun, AuditRunItem, Website } from "@/types";

function buildDeepResearchFlowLabel(run: AuditRun) {
  const researchProvider = run.deepResearchResearchProvider ?? "perplexity";
  const reasoningProvider = run.deepResearchReasoningProvider ?? "openai";
  const formatterProvider = run.deepResearchFormatterProvider ?? "openai";

  return `Flow audit_deep_research · 3A ${researchProvider} · 3B ${reasoningProvider} · 3C ${formatterProvider}`;
}

function buildStandardFlowLabel(run: AuditRun) {
  const base = `B2 ${run.step2AiProvider ?? run.aiProvider ?? "openai"}/${run.step2AiModel ?? run.aiModel ?? "default"} · B3 ${run.step3AiProvider ?? run.aiProvider ?? "openai"}/${run.step3AiModel ?? run.aiModel ?? "default"}`;

  if (run.stopAfterStep === 1) {
    return `Chỉ bước 1 · ${base}`;
  }

  if (run.stopAfterStep === 2) {
    return `Chỉ bước 2 · ${base}`;
  }

  return base;
}

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
  const runPollInFlightRef = useRef(false);
  const profileUid = profile?.uid;

  async function loadRun(options?: { silent?: boolean }) {
    try {
      if (!options?.silent) {
        setLoading(true);
      }

      const [nextWebsite, nextRun] = await Promise.all([getWebsiteById(id), getAuditRun(runId)]);

      const canAccessAsAdmin = profile?.realRole === "admin" && !profile?.isImpersonating;

      if (!nextWebsite || (nextWebsite.userId !== profile?.uid && !canAccessAsAdmin) || nextRun.websiteId !== id) {
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

  useEffect(() => {
    const publicId = run?.publicId;

    if (!publicId || !isActiveAuditRun(run?.status)) {
      return;
    }

    const intervalId = window.setInterval(() => {
      if (runPollInFlightRef.current) {
        return;
      }

      runPollInFlightRef.current = true;

      void loadRun({ silent: true }).finally(() => {
        runPollInFlightRef.current = false;
      });
    }, ACTIVE_AUDIT_POLL_INTERVAL_MS);

    return () => {
      window.clearInterval(intervalId);
    };
  }, [run?.publicId, run?.status]);

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
  const usageSummary = run.usageSummary ?? null;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Run #${run.publicId.slice(-8)}`}
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
            {run.workflow === "audit_deep_research"
              ? `Tạo ${formatDate(run.createdAt)} · ${buildDeepResearchFlowLabel(run)}`
              : `Tạo ${formatDate(run.createdAt)} · ${buildStandardFlowLabel(run)}`}
          </p>
        </CardContent>
      </Card>

      {usageSummary ? (
        <Card>
          <CardHeader>
            <CardTitle>Token / cost theo bước</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid gap-3 md:grid-cols-5">
              <StatCard title="Tổng token" value={formatNumber(usageSummary.totals.totalTokens)} hint={`${formatNumber(usageSummary.totals.eventCount)} AI call`} icon={Waypoints} />
              <StatCard title="Input / Output" value={`${formatNumber(usageSummary.totals.inputTokens)} / ${formatNumber(usageSummary.totals.outputTokens)}`} icon={Waves} />
              <StatCard title="Reasoning / Citation" value={`${formatNumber(usageSummary.totals.reasoningTokens)} / ${formatNumber(usageSummary.totals.citationTokens)}`} icon={ListChecks} />
              <StatCard title="USD đã trừ" value={formatUsd(usageSummary.totals.usdCharged, 6)} hint="Trừ thực tế theo giá API / token" icon={FileSpreadsheet} />
              <StatCard title="USD ước tính" value={formatUsd(usageSummary.totals.estimatedCostUsd, 6)} icon={FileSpreadsheet} />
            </div>

            <div className="overflow-x-auto">
              <table className="w-full min-w-[1120px] text-sm">
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="py-2 pr-4">Bước</th>
                    <th className="py-2 pr-4">Provider / model</th>
                    <th className="py-2 pr-4">AI call</th>
                    <th className="py-2 pr-4">Input</th>
                    <th className="py-2 pr-4">Output</th>
                    <th className="py-2 pr-4">Reasoning</th>
                    <th className="py-2 pr-4">Citation</th>
                    <th className="py-2 pr-4">Search</th>
                    <th className="py-2 pr-4">Total token</th>
                    <th className="py-2 pr-4">USD đã trừ</th>
                    <th className="py-2 pr-4">USD provider</th>
                    <th className="py-2 pr-4">USD ước tính</th>
                  </tr>
                </thead>
                <tbody>
                  {usageSummary.byStep.map((step) => (
                    <tr key={step.key} className="border-b border-border/60 align-top">
                      <td className="py-3 pr-4">
                        <p className="font-medium">{step.label}</p>
                        <p className="text-xs text-muted-foreground">{step.rawSteps.length} raw step key</p>
                      </td>
                      <td className="py-3 pr-4">
                        <p>{step.providers.join(", ") || "—"}</p>
                        <p className="text-xs text-muted-foreground break-all">{step.models.join(", ") || "—"}</p>
                      </td>
                      <td className="py-3 pr-4">{formatNumber(step.eventCount)}</td>
                      <td className="py-3 pr-4">{formatNumber(step.inputTokens)}</td>
                      <td className="py-3 pr-4">{formatNumber(step.outputTokens)}</td>
                      <td className="py-3 pr-4">{formatNumber(step.reasoningTokens)}</td>
                      <td className="py-3 pr-4">{formatNumber(step.citationTokens)}</td>
                      <td className="py-3 pr-4">{formatNumber(step.searchQueries)}</td>
                      <td className="py-3 pr-4">{formatNumber(step.totalTokens)}</td>
                      <td className="py-3 pr-4">{formatUsd(step.usdCharged, 6)}</td>
                      <td className="py-3 pr-4">{formatUsd(step.providerReportedCostUsd, 6)}</td>
                      <td className="py-3 pr-4">{formatUsd(step.estimatedCostUsd)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <AuditRunItemsTable run={run} items={items} onExport={handleExport} exporting={exporting} />
    </div>
  );
}
