"use client";

import { useEffect, useState } from "react";

import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { useAuth } from "@/hooks/use-auth";
import { fetchCreditTransactions } from "@/lib/account";
import { formatDate, formatUsd } from "@/lib/utils";
import type { CreditLog } from "@/types";

export default function CreditHistoryPage() {
  const { profile } = useAuth();
  const [logs, setLogs] = useState<CreditLog[]>([]);

  useEffect(() => {
    if (!profile) {
      return;
    }

    void fetchCreditTransactions({ userId: profile.uid, limit: 100 })
      .then(setLogs)
      .catch(() => undefined);
  }, [profile]);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Balance history"
        description="Theo dõi toàn bộ biến động số dư USD của tài khoản hiện tại từ MySQL qua Laravel API."
        breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Balance History" }]}
      />

      <DataTable
        title="Balance logs"
        rows={logs}
        columns={[
          { key: "type", header: "Loại", render: (row: CreditLog) => row.type },
          { key: "amount", header: "Amount", render: (row: CreditLog) => `${row.type === "subtract" ? "-" : "+"}${formatUsd(row.amountUsd, 6)}` },
          { key: "before", header: "Trước", render: (row: CreditLog) => formatUsd(row.balanceBeforeUsd, 4) },
          { key: "after", header: "Sau", render: (row: CreditLog) => formatUsd(row.balanceAfterUsd, 4) },
          { key: "reason", header: "Reason", render: (row: CreditLog) => row.reason },
          { key: "source", header: "Source", render: (row: CreditLog) => row.source },
          { key: "createdAt", header: "Thời gian", render: (row: CreditLog) => formatDate(row.createdAt) }
        ]}
        empty={<EmptyState title="Chưa có lịch sử giao dịch" description="Log sẽ xuất hiện sau lần cộng hoặc trừ số dư đầu tiên." />}
      />
    </div>
  );
}
