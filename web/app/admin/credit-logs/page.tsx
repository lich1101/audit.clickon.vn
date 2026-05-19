"use client";

import { useEffect, useState } from "react";

import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { fetchAdminCreditTransactions } from "@/lib/account";
import { formatDate, formatNumber } from "@/lib/utils";
import type { CreditLog } from "@/types";

export default function AdminCreditLogsPage() {
  const [logs, setLogs] = useState<CreditLog[]>([]);

  useEffect(() => {
    void fetchAdminCreditTransactions(200).then(setLogs).catch(() => setLogs([]));
  }, []);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Credit logs"
        description="Toàn bộ log cộng/trừ credit trên hệ thống, bao gồm nguồn và số dư trước/sau."
        breadcrumbs={[{ label: "Admin", href: "/admin" }, { label: "Credit Logs" }]}
      />

      <DataTable
        title="System credit logs"
        rows={logs}
        columns={[
          { key: "userId", header: "User ID", render: (row: CreditLog) => row.userId },
          { key: "type", header: "Type", render: (row: CreditLog) => row.type },
          { key: "amount", header: "Amount", render: (row: CreditLog) => formatNumber(row.amount) },
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
