"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/dashboard/confirm-dialog";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { fetchAdminPlans, updatePlan } from "@/lib/account";
import { formatCurrency, formatDate, formatUsd } from "@/lib/utils";
import type { Plan } from "@/types";

export default function AdminPlansPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [search, setSearch] = useState("");

  useEffect(() => {
    void fetchAdminPlans().then(setPlans).catch(() => setPlans([]));
  }, []);

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();

    if (!keyword) {
      return plans;
    }

    return plans.filter((plan) => plan.name.toLowerCase().includes(keyword));
  }, [plans, search]);

  async function deactivatePlan(plan: Plan) {
    try {
      await updatePlan(plan.id, { isActive: false });
      toast.success("Gói cước đã được deactivate.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể deactivate gói cước.");
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Plans"
        description="Tạo, sửa và deactivate gói cước để điều phối credit packages cho người dùng."
        breadcrumbs={[{ label: "Admin", href: "/admin" }, { label: "Plans" }]}
        action={{ label: "Tạo gói cước", href: "/admin/plans/create" }}
      />

      <DataTable
        title="Plan management"
        search={search}
        onSearchChange={setSearch}
        rows={filtered}
        columns={[
          { key: "name", header: "Plan", render: (row: Plan) => row.name },
          { key: "price", header: "Giá", render: (row: Plan) => formatCurrency(row.price) },
          { key: "balanceUsd", header: "Số dư USD", render: (row: Plan) => formatUsd(row.balanceUsd, 2) },
          { key: "status", header: "Status", render: (row: Plan) => (row.isActive ? "active" : "inactive") },
          { key: "createdAt", header: "Ngày tạo", render: (row: Plan) => formatDate(row.createdAt) },
          {
            key: "actions",
            header: "Actions",
            render: (row: Plan) => (
              <div className="flex gap-2">
                <Button asChild size="sm" variant="secondary">
                  <Link href={`/admin/plans/${row.id}/edit`}>Sửa</Link>
                </Button>
                {row.isActive ? (
                  <ConfirmDialog
                    trigger={
                      <Button size="sm" variant="outline">
                        Deactivate
                      </Button>
                    }
                    title="Deactivate gói cước"
                    description="Gói cước sẽ bị ẩn khỏi trang billing cho user."
                    actionLabel="Deactivate"
                    onConfirm={() => void deactivatePlan(row)}
                  />
                ) : null}
              </div>
            )
          }
        ]}
        empty={<EmptyState title="Chưa có gói cước" description="Hãy tạo gói cước đầu tiên cho hệ thống." action={{ label: "Tạo gói cước", href: "/admin/plans/create" }} />}
      />
    </div>
  );
}
