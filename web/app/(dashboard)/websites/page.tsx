"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";

import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { WebsiteCard } from "@/components/dashboard/website-card";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { ProgressBar } from "@/components/dashboard/progress-bar";
import { useAuth } from "@/hooks/use-auth";
import { fetchWebsites } from "@/lib/firestore";
import { ACTIVE_AUDIT_POLL_INTERVAL_MS, isActiveAuditRun } from "@/lib/audit-runs";
import { formatDate } from "@/lib/utils";
import type { Website } from "@/types";

export default function WebsitesPage() {
  const { profile } = useAuth();
  const [websites, setWebsites] = useState<Website[]>([]);
  const [search, setSearch] = useState("");

  async function loadWebsites() {
    const nextWebsites = await fetchWebsites();
    setWebsites(nextWebsites);
  }

  useEffect(() => {
    if (!profile) {
      return;
    }

    void loadWebsites().catch(() => undefined);
  }, [profile]);

  const hasActiveRuns = useMemo(() => websites.some((website) => isActiveAuditRun(website.activeRun?.status)), [websites]);

  useEffect(() => {
    if (!profile || !hasActiveRuns) {
      return;
    }

    const intervalId = window.setInterval(() => {
      void loadWebsites().catch(() => undefined);
    }, ACTIVE_AUDIT_POLL_INTERVAL_MS);

    return () => {
      window.clearInterval(intervalId);
    };
  }, [hasActiveRuns, profile]);

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();

    if (!keyword) {
      return websites;
    }

    return websites.filter((website) => [website.name, website.url].some((field) => field.toLowerCase().includes(keyword)));
  }, [search, websites]);

  function renderAuditActivity(website: Website) {
    const activeRun = website.activeRun;

    if (!activeRun || !isActiveAuditRun(activeRun.status)) {
      return <span className="text-sm text-muted-foreground">Không có run active</span>;
    }

    const progressPercent = activeRun.totalUrls > 0 ? Math.min(100, Math.round((activeRun.processedUrls / activeRun.totalUrls) * 100)) : 0;

    return (
      <div className="min-w-[180px] space-y-1.5">
        <div className="flex items-center gap-2">
          <AuditStatusBadge status={activeRun.status} />
          <span className="text-xs text-muted-foreground">
            {activeRun.processedUrls}/{activeRun.totalUrls} URL
          </span>
        </div>
        <ProgressBar className="h-2" value={progressPercent} />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Websites"
        description="Quản lý toàn bộ website của bạn, tách biệt rõ giữa danh sách, tạo mới, chi tiết và trang audit."
        breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Websites" }]}
        action={{ label: "Tạo audit website", href: "/websites/create" }}
      />

      <DataTable
        title="Website list"
        search={search}
        onSearchChange={setSearch}
        rows={filtered}
        columns={[
          { key: "name", header: "Website", render: (row: Website) => row.name },
          { key: "url", header: "URL", render: (row: Website) => <span className="truncate">{row.url}</span> },
          { key: "activeRun", header: "Audit đang chạy", render: (row: Website) => renderAuditActivity(row) },
          { key: "createdAt", header: "Ngày tạo", render: (row: Website) => formatDate(row.createdAt) },
          {
            key: "actions",
            header: "Actions",
            render: (row: Website) => (
              <div className="flex gap-2">
                <Button asChild size="sm" variant="secondary">
                  <Link href={`/websites/${row.id}`}>Chi tiết</Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/websites/${row.id}/audit`}>Audit</Link>
                </Button>
              </div>
            )
          }
        ]}
        empty={<EmptyState title="Chưa có website" description="Danh sách trống. Tạo website audit đầu tiên." action={{ label: "Tạo audit website", href: "/websites/create" }} />}
      />

      {filtered.length ? (
        <div className="grid gap-4 lg:grid-cols-3">
          {filtered.map((website) => (
            <WebsiteCard key={website.id} website={website} />
          ))}
        </div>
      ) : null}
    </div>
  );
}
