"use client";

import Link from "next/link";
import { useDeferredValue, useState } from "react";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { ProgressBar } from "@/components/dashboard/progress-bar";
import { Button } from "@/components/ui/button";
import { formatDate } from "@/lib/utils";
import type { AuditRun } from "@/types";

function buildProgressLabel(run: AuditRun) {
  return `${run.processedUrls}/${run.totalUrls} URL`;
}

export function AuditRunsTable({
  websiteId,
  runs
}: {
  websiteId: string;
  runs: AuditRun[];
}) {
  const [search, setSearch] = useState("");
  const deferredSearch = useDeferredValue(search.trim().toLowerCase());

  const filteredRuns = runs.filter((run) => {
    if (!deferredSearch) {
      return true;
    }

    return [
      run.publicId,
      run.status,
      run.websiteName ?? "",
      run.websiteUrl ?? "",
      run.lastError ?? ""
    ].some((value) => value.toLowerCase().includes(deferredSearch));
  });

  return (
    <DataTable
      title="Các đợt audit gần đây"
      search={search}
      onSearchChange={setSearch}
      rows={filteredRuns}
      columns={[
        {
          key: "run",
          header: "Audit run",
          render: (run) => (
            <div className="space-y-1">
              <p className="font-medium">#{run.publicId.slice(-8)}</p>
              <p className="text-xs text-muted-foreground">{formatDate(run.createdAt)}</p>
              <p className="text-xs text-muted-foreground">AI: {run.aiProvider ?? "openai"}</p>
            </div>
          )
        },
        {
          key: "status",
          header: "Trạng thái",
          render: (run) => <AuditStatusBadge status={run.status} />
        },
        {
          key: "progress",
          header: "Tiến độ",
          render: (run) => (
            <div className="min-w-[180px] space-y-2">
              <ProgressBar value={run.totalUrls > 0 ? Math.min(100, Math.round((run.processedUrls / run.totalUrls) * 100)) : 0} />
              <p className="text-xs text-muted-foreground">{buildProgressLabel(run)}</p>
            </div>
          )
        },
        {
          key: "result",
          header: "Kết quả",
          render: (run) => (
            <div className="space-y-1 text-sm">
              <p>Hoàn tất: {run.completedUrls}</p>
              <p className="text-muted-foreground">Lỗi: {run.failedUrls}</p>
            </div>
          )
        },
        {
          key: "actions",
          header: "Thao tác",
          render: (run) => (
            <Button asChild size="sm" variant="outline">
              <Link href={`/websites/${websiteId}/audit/runs/${run.publicId}`}>Xem chi tiết</Link>
            </Button>
          )
        }
      ]}
      empty={
        <EmptyState
          title="Chưa có audit run"
          description="Tạo đợt audit đầu tiên từ danh sách URL mục tiêu để hệ thống bắt đầu phân tích theo queue."
        />
      }
    />
  );
}
