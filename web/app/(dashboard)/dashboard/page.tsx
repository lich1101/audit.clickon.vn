"use client";

import Link from "next/link";
import { Globe2, Sparkles, Wallet } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { CreditBadge } from "@/components/dashboard/credit-badge";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { StatCard } from "@/components/dashboard/stat-card";
import { WebsiteCard } from "@/components/dashboard/website-card";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import { fetchCreditLogs, fetchWebsites } from "@/lib/firestore";
import { formatDate, formatNumber } from "@/lib/utils";
import type { CreditLog, Website } from "@/types";

export default function DashboardPage() {
  const { profile } = useAuth();
  const [websites, setWebsites] = useState<Website[]>([]);
  const [logs, setLogs] = useState<CreditLog[]>([]);

  useEffect(() => {
    if (!profile) {
      return;
    }

    void Promise.all([fetchWebsites(), fetchCreditLogs(profile.uid, 20)])
      .then(([nextWebsites, nextLogs]) => {
        setWebsites(nextWebsites);
        setLogs(nextLogs);
      })
      .catch(() => undefined);
  }, [profile]);

  const recentWebsites = useMemo(() => websites.slice(0, 3), [websites]);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Dashboard"
        description="Tổng quan realtime về credit, website đã tạo và các giao dịch credit gần nhất của tài khoản hiện tại."
        breadcrumbs={[{ label: "Dashboard" }]}
        action={{ label: "Tạo audit website", href: "/websites/create" }}
      />

      <div className="grid gap-5 xl:grid-cols-3">
        <StatCard title="Credit hiện tại" value={formatNumber(profile?.credits ?? 0)} hint="Được lắng nghe realtime từ users/{uid}" icon={Wallet} />
        <StatCard title="Website đang quản lý" value={formatNumber(websites.length)} hint="Mỗi user chỉ thấy dữ liệu của chính mình" icon={Globe2} />
        <StatCard title="Giao dịch credit gần đây" value={formatNumber(logs.length)} hint="Log được cập nhật ngay sau mutation" icon={Sparkles} />
      </div>

      <div className="grid gap-5 xl:grid-cols-[1.25fr_0.75fr]">
        <DataTable
          title="Recent credit activity"
          columns={[
            { key: "type", header: "Loại", render: (row: CreditLog) => row.type },
            { key: "amount", header: "Amount", render: (row: CreditLog) => `${row.type === "subtract" ? "-" : "+"}${formatNumber(row.amount)}` },
            { key: "reason", header: "Lý do", render: (row: CreditLog) => row.reason },
            { key: "createdAt", header: "Thời gian", render: (row: CreditLog) => formatDate(row.createdAt) }
          ]}
          rows={logs.slice(0, 5)}
          empty={<EmptyState title="Chưa có credit log" description="Các lần cộng hoặc trừ credit sẽ xuất hiện ở đây." />}
        />

        <Card>
          <CardHeader className="flex-row items-center justify-between">
            <CardTitle>Quick actions</CardTitle>
            <CreditBadge credits={profile?.credits ?? 0} />
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <Button asChild className="justify-start">
              <Link href="/websites/create">Tạo audit website</Link>
            </Button>
            <Button asChild variant="secondary" className="justify-start">
              <Link href="/billing">Đăng ký gói cước</Link>
            </Button>
            <Button asChild variant="outline" className="justify-start">
              <Link href="/credit-history">Xem toàn bộ credit log</Link>
            </Button>
          </CardContent>
        </Card>
      </div>

      <section className="flex flex-col gap-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-xl font-semibold">Recent websites</h2>
            <p className="mt-1 text-sm text-muted-foreground">Các website vừa được tạo sẽ hiển thị ở đây.</p>
          </div>
          <Button asChild variant="outline">
            <Link href="/websites">Xem tất cả</Link>
          </Button>
        </div>

        {recentWebsites.length ? (
          <div className="grid gap-4 lg:grid-cols-3">
            {recentWebsites.map((website) => (
              <WebsiteCard key={website.id} website={website} />
            ))}
          </div>
        ) : (
          <EmptyState title="Chưa có website" description="Bắt đầu bằng cách tạo website audit đầu tiên." action={{ label: "Tạo audit website", href: "/websites/create" }} />
        )}
      </section>
    </div>
  );
}
