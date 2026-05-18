"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";

import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { WebsiteCard } from "@/components/dashboard/website-card";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/hooks/use-auth";
import { listenToWebsites } from "@/lib/firestore";
import { formatDate } from "@/lib/utils";
import type { Website } from "@/types";

export default function WebsitesPage() {
  const { profile } = useAuth();
  const [websites, setWebsites] = useState<Website[]>([]);
  const [search, setSearch] = useState("");

  useEffect(() => {
    if (!profile) {
      return;
    }

    return listenToWebsites(profile.uid, setWebsites);
  }, [profile]);

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();

    if (!keyword) {
      return websites;
    }

    return websites.filter((website) => [website.name, website.url].some((field) => field.toLowerCase().includes(keyword)));
  }, [search, websites]);

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Websites"
        description="Quản lý toàn bộ website của bạn, tách biệt rõ giữa danh sách, tạo mới, chi tiết và trang audit."
        breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Websites" }]}
        action={{ label: "Tạo website", href: "/websites/create" }}
      />

      <DataTable
        title="Website list"
        search={search}
        onSearchChange={setSearch}
        rows={filtered}
        columns={[
          { key: "name", header: "Website", render: (row: Website) => row.name },
          { key: "url", header: "URL", render: (row: Website) => <span className="truncate">{row.url}</span> },
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
        empty={<EmptyState title="Chưa có website" description="Danh sách website trống. Hãy tạo website đầu tiên." action={{ label: "Tạo website", href: "/websites/create" }} />}
      />

      {filtered.length ? (
        <div className="grid gap-5 lg:grid-cols-3">
          {filtered.map((website) => (
            <WebsiteCard key={website.id} website={website} />
          ))}
        </div>
      ) : null}
    </div>
  );
}
