"use client";

import { useEffect, useState } from "react";

import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { useAuth } from "@/hooks/use-auth";
import { listenToCreditLogs } from "@/lib/firestore";
import { formatDate, formatNumber } from "@/lib/utils";
import type { CreditLog } from "@/types";

export default function CreditHistoryPage() {
  const { profile } = useAuth();
  const [logs, setLogs] = useState<CreditLog[]>([]);

  useEffect(() => {
    if (!profile) {
      return;
    }

    return listenToCreditLogs(profile.uid, setLogs, undefined, 100);
  }, [profile]);

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Credit history"
        description="Theo dõi toàn bộ biến động credit của tài khoản hiện tại theo thời gian thực."
        breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Credit History" }]}
      />

      <DataTable
        title="Credit logs"
        rows={logs}
        columns={[
          { key: "type", header: "Loại", render: (row: CreditLog) => row.type },
          { key: "amount", header: "Amount", render: (row: CreditLog) => `${row.type === "subtract" ? "-" : "+"}${formatNumber(row.amount)}` },
          { key: "before", header: "Trước", render: (row: CreditLog) => formatNumber(row.balanceBefore) },
          { key: "after", header: "Sau", render: (row: CreditLog) => formatNumber(row.balanceAfter) },
          { key: "reason", header: "Reason", render: (row: CreditLog) => row.reason },
          { key: "source", header: "Source", render: (row: CreditLog) => row.source },
          { key: "createdAt", header: "Thời gian", render: (row: CreditLog) => formatDate(row.createdAt) }
        ]}
        empty={<EmptyState title="Chưa có lịch sử credit" description="Credit log sẽ xuất hiện sau lần cộng hoặc trừ credit đầu tiên." />}
      />
    </div>
  );
}
