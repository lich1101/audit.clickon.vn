"use client";

import { use, useEffect, useState } from "react";
import { toast } from "sonner";

import { PlanForm } from "@/components/forms/plan-form";
import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { getPlanById } from "@/lib/firestore";
import type { Plan } from "@/types";

export default function EditPlanPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const [plan, setPlan] = useState<Plan | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        setLoading(true);
        setPlan(await getPlanById(id));
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Không thể tải thông tin gói cước.");
      } finally {
        setLoading(false);
      }
    }

    void load();
  }, [id]);

  if (loading) {
    return <LoadingState title="Đang tải plan..." description="Đang lấy dữ liệu gói cước để chỉnh sửa." />;
  }

  if (!plan) {
    return <EmptyState title="Không tìm thấy gói cước" description="Plan này không tồn tại trong Firestore." action={{ label: "Về plans", href: "/admin/plans" }} />;
  }

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title={`Sửa gói cước: ${plan.name}`}
        description="Điều chỉnh giá trị, số credit hoặc trạng thái active."
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Plans", href: "/admin/plans" },
          { label: "Edit" }
        ]}
      />
      <PlanForm plan={plan} />
    </div>
  );
}
