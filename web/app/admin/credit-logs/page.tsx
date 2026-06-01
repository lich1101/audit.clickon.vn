"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/dashboard/confirm-dialog";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { backfillAiUsageReconciliation, fetchAdminCreditTransactions, fetchAiUsageReconciliationReport } from "@/lib/account";
import { formatDate, formatNumber, formatUsd } from "@/lib/utils";
import type { AiUsageReconciliationReport, CreditLog } from "@/types";

export default function AdminCreditLogsPage() {
  const [logs, setLogs] = useState<CreditLog[]>([]);
  const [report, setReport] = useState<AiUsageReconciliationReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [reloading, setReloading] = useState(false);
  const [backfilling, setBackfilling] = useState(false);

  async function loadAll(showToast = false) {
    try {
      setLoading(true);
      const [creditLogs, reconciliation] = await Promise.all([
        fetchAdminCreditTransactions(200),
        fetchAiUsageReconciliationReport({ status: "undercharged", limit: 1500 })
      ]);
      setLogs(creditLogs);
      setReport(reconciliation);
      if (showToast) {
        toast.success("Đã tải lại credit log và reconciliation report.");
      }
    } catch (error) {
      setLogs([]);
      setReport(null);
      if (showToast) {
        toast.error(error instanceof Error ? error.message : "Không thể tải reconciliation report.");
      }
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadAll();
  }, []);

  async function handleReload() {
    try {
      setReloading(true);
      const [creditLogs, reconciliation] = await Promise.all([
        fetchAdminCreditTransactions(200),
        fetchAiUsageReconciliationReport({ status: "undercharged", limit: 1500 })
      ]);
      setLogs(creditLogs);
      setReport(reconciliation);
      toast.success("Đã tải lại dữ liệu.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tải lại dữ liệu.");
    } finally {
      setReloading(false);
    }
  }

  async function handleBackfill(runPublicIds?: string[]) {
    try {
      setBackfilling(true);
      const result = await backfillAiUsageReconciliation({
        runPublicIds,
        limit: 1500
      });
      toast.success(
        `Đã backfill ${formatNumber(result.summary.appliedEventCount)} event, trừ thêm ${formatUsd(result.summary.appliedUsdDelta, 6)} / ${formatNumber(result.summary.appliedCreditDelta)} credit.`
      );
      await loadAll();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể backfill AI usage.");
    } finally {
      setBackfilling(false);
    }
  }

  const summary = report?.summary;
  const runs = report?.runs ?? [];
  const events = report?.events ?? [];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Credit logs"
        description="Toàn bộ log cộng/trừ credit trên hệ thống, kèm báo cáo reconciliation để phát hiện run AI đang bị tính thiếu/khác với bảng giá hiện tại."
        breadcrumbs={[{ label: "Admin", href: "/admin" }, { label: "Credit Logs" }]}
      />

      <Card>
        <CardHeader className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <CardTitle>AI billing reconciliation</CardTitle>
            <p className="mt-1 text-sm text-muted-foreground">
              So sánh `AiUsageEvent.usd_charged` hiện có với số USD lẽ ra phải trừ theo bảng giá/token hiện tại.
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => void handleReload()} disabled={loading || reloading || backfilling}>
              {reloading ? "Đang tải..." : "Tải lại"}
            </Button>
            <ConfirmDialog
              trigger={
                <Button type="button" disabled={loading || backfilling || runs.length === 0}>
                  {backfilling ? "Đang backfill..." : "Backfill tất cả run lệch"}
                </Button>
              }
              title="Backfill toàn bộ run undercharge?"
              description="Thao tác này sẽ tạo credit transaction adjustment mới và cập nhật lại AiUsageEvent về số USD/credit đúng với bảng giá hiện tại. Lịch sử trừ cũ vẫn được giữ lại."
              actionLabel="Backfill ngay"
              onConfirm={() => void handleBackfill()}
            />
          </div>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-4">
          <div className="rounded-2xl border border-border p-4">
            <p className="text-sm text-muted-foreground">Run bị lệch</p>
            <p className="mt-2 text-2xl font-semibold">{summary ? formatNumber(summary.affectedRunCount) : "0"}</p>
          </div>
          <div className="rounded-2xl border border-border p-4">
            <p className="text-sm text-muted-foreground">Event undercharge</p>
            <p className="mt-2 text-2xl font-semibold">{summary ? formatNumber(summary.underchargedEventCount) : "0"}</p>
          </div>
          <div className="rounded-2xl border border-border p-4">
            <p className="text-sm text-muted-foreground">USD đang thiếu</p>
            <p className="mt-2 text-2xl font-semibold">{summary ? formatUsd(summary.usdDelta, 6) : "$0.000000"}</p>
          </div>
          <div className="rounded-2xl border border-border p-4">
            <p className="text-sm text-muted-foreground">Credit đang thiếu</p>
            <p className="mt-2 text-2xl font-semibold">{summary ? formatNumber(summary.creditDelta) : "0"}</p>
          </div>

          <div className="rounded-2xl border border-border p-4 md:col-span-4">
            <div className="flex flex-wrap gap-6 text-sm text-muted-foreground">
              <span>Scanned: {summary ? formatNumber(summary.scannedEventCount) : "0"} event</span>
              <span>Charged: {summary ? formatUsd(summary.chargedUsd, 6) : "$0.000000"}</span>
              <span>Expected: {summary ? formatUsd(summary.expectedUsd, 6) : "$0.000000"}</span>
              <span>Aligned: {summary ? formatNumber(summary.alignedEventCount) : "0"}</span>
              <span>Overcharged: {summary ? formatNumber(summary.overchargedEventCount) : "0"}</span>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card className="overflow-hidden">
        <CardHeader>
          <CardTitle>Run đang bị undercharge</CardTitle>
        </CardHeader>
        <CardContent className="px-0 pb-0">
          {runs.length ? (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="bg-secondary/50 text-left text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 font-medium">Run</th>
                    <th className="px-4 py-3 font-medium">Website</th>
                    <th className="px-4 py-3 font-medium">Mode</th>
                    <th className="px-4 py-3 font-medium">Events</th>
                    <th className="px-4 py-3 font-medium">Charged</th>
                    <th className="px-4 py-3 font-medium">Expected</th>
                    <th className="px-4 py-3 font-medium">Delta</th>
                    <th className="px-4 py-3 font-medium">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {runs.map((run) => (
                    <tr key={run.runPublicId} className="border-t border-border/60 align-top">
                      <td className="px-4 py-3 font-mono text-xs">{run.runPublicId}</td>
                      <td className="px-4 py-3">
                        <div className="font-medium">{run.websiteName || "-"}</div>
                        <div className="mt-1 text-xs text-muted-foreground">{run.userUid}</div>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        {run.workflow}/{run.pipelineMode}
                      </td>
                      <td className="px-4 py-3">
                        {formatNumber(run.affectedEventCount)}/{formatNumber(run.eventCount)}
                      </td>
                      <td className="px-4 py-3">{formatUsd(run.chargedUsd, 6)}</td>
                      <td className="px-4 py-3">{formatUsd(run.expectedUsd, 6)}</td>
                      <td className="px-4 py-3 font-medium text-amber-600">{formatUsd(run.usdDelta, 6)}</td>
                      <td className="px-4 py-3">
                        <ConfirmDialog
                          trigger={
                            <Button type="button" size="sm" variant="outline" disabled={backfilling}>
                              Backfill run
                            </Button>
                          }
                          title={`Backfill run ${run.runPublicId}?`}
                          description={`Run này đang bị thiếu khoảng ${formatUsd(run.usdDelta, 6)} / ${formatNumber(run.creditDelta)} credit. Hệ thống sẽ tạo adjustment transaction mới và cập nhật lại AiUsageEvent của run này.`}
                          actionLabel="Backfill run"
                          onConfirm={() => void handleBackfill([run.runPublicId])}
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <EmptyState title="Chưa phát hiện run undercharge" description={loading ? "Đang tải reconciliation report..." : "Hiện chưa có run nào lệch so với bảng giá hiện tại."} />
          )}
        </CardContent>
      </Card>

      <Card className="overflow-hidden">
        <CardHeader>
          <CardTitle>Event lệch lớn nhất</CardTitle>
        </CardHeader>
        <CardContent className="px-0 pb-0">
          {events.length ? (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="bg-secondary/50 text-left text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 font-medium">Run / Item</th>
                    <th className="px-4 py-3 font-medium">Step</th>
                    <th className="px-4 py-3 font-medium">Model</th>
                    <th className="px-4 py-3 font-medium">Tokens</th>
                    <th className="px-4 py-3 font-medium">Charged</th>
                    <th className="px-4 py-3 font-medium">Expected</th>
                    <th className="px-4 py-3 font-medium">Delta</th>
                    <th className="px-4 py-3 font-medium">At</th>
                  </tr>
                </thead>
                <tbody>
                  {events.slice(0, 40).map((event) => (
                    <tr key={event.eventId} className="border-t border-border/60 align-top">
                      <td className="px-4 py-3">
                        <div className="font-mono text-xs">{event.runPublicId}</div>
                        <div className="mt-1 text-xs text-muted-foreground">{event.itemPublicId}</div>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{event.step}</td>
                      <td className="px-4 py-3">
                        <div>{event.model}</div>
                        <div className="mt-1 text-xs text-muted-foreground">{event.provider}</div>
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {formatNumber(event.inputTokens)} in / {formatNumber(event.outputTokens)} out / {formatNumber(event.reasoningTokens)} reasoning
                      </td>
                      <td className="px-4 py-3">{formatUsd(event.chargedUsd, 6)}</td>
                      <td className="px-4 py-3">{formatUsd(event.expectedUsd, 6)}</td>
                      <td className="px-4 py-3 font-medium text-amber-600">{formatUsd(event.usdDelta, 6)}</td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">{event.createdAt ? formatDate(event.createdAt) : "-"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <EmptyState title="Không có event lệch" description="Khi phát hiện event tính thiếu hoặc thừa, bảng này sẽ hiển thị token và số USD chênh lệch." />
          )}
        </CardContent>
      </Card>

      <DataTable
        title="System credit logs"
        rows={logs}
        columns={[
          { key: "userId", header: "User ID", render: (row: CreditLog) => row.userId },
          { key: "type", header: "Type", render: (row: CreditLog) => row.type },
          { key: "amount", header: "Amount", render: (row: CreditLog) => formatNumber(row.amount) },
          { key: "amountUsd", header: "USD", render: (row: CreditLog) => formatUsd(row.amountUsd, 6) },
          { key: "before", header: "Before", render: (row: CreditLog) => formatNumber(row.balanceBefore) },
          { key: "after", header: "After", render: (row: CreditLog) => formatNumber(row.balanceAfter) },
          { key: "source", header: "Source", render: (row: CreditLog) => row.source },
          { key: "reason", header: "Reason", render: (row: CreditLog) => row.reason },
          { key: "createdAt", header: "Thời gian", render: (row: CreditLog) => formatDate(row.createdAt) }
        ]}
        empty={<EmptyState title="Chưa có credit log" description="Credit logs sẽ xuất hiện sau các giao dịch đầu tiên." />}
      />
    </div>
  );
}
