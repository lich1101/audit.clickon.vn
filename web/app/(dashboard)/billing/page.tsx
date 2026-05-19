"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/dashboard/empty-state";
import { PlanCard } from "@/components/dashboard/plan-card";
import { PageHeader } from "@/components/layout/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import { listenToPlans } from "@/lib/firestore";
import { laravelRequest } from "@/lib/laravel";
import { formatCurrency, formatDate } from "@/lib/utils";
import type { Plan, PlanRequest } from "@/types";

export default function BillingPage() {
  const { profile } = useAuth();
  const [plans, setPlans] = useState<Plan[]>([]);
  const [requests, setRequests] = useState<PlanRequest[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => listenToPlans(setPlans), []);

  useEffect(() => {
    async function loadRequests() {
      if (!profile) {
        return;
      }

      try {
        const payload = await laravelRequest<{ data: PlanRequest[] }>("/api/plan-requests");
        setRequests(payload.data);
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Không thể tải lịch sử đăng ký gói cước.");
      }
    }

    void loadRequests();
  }, [profile]);

  async function handleRegister(plan: Plan) {
    try {
      setLoading(true);
      await laravelRequest("/api/plan-requests", {
        method: "POST",
        body: JSON.stringify({ planId: plan.id })
      });
      toast.success("Yêu cầu đăng ký gói cước đã được gửi để admin duyệt.");
      const payload = await laravelRequest<{ data: PlanRequest[] }>("/api/plan-requests");
      setRequests(payload.data);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tạo yêu cầu đăng ký gói cước.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Billing"
        description="Chọn gói cước theo số credit, gửi yêu cầu đăng ký và chờ admin duyệt thủ công trước khi credit được cộng vào tài khoản."
        breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Billing" }]}
      />

      {plans.length ? (
        <div className="grid gap-4 lg:grid-cols-3">
          {plans.map((plan) => (
            <PlanCard key={plan.id} plan={plan} loading={loading} onSelect={handleRegister} />
          ))}
        </div>
      ) : (
        <EmptyState title="Chưa có gói cước active" description="Admin chưa publish gói cước nào cho hệ thống." />
      )}

      <Card>
        <CardHeader>
          <CardTitle>Yêu cầu gói cước gần đây</CardTitle>
        </CardHeader>
        <CardContent>
          {requests.length ? (
            <div className="grid gap-2">
              {requests.map((request) => (
                <div key={request.id} className="mail-row rounded-xl border border-border bg-background/70 px-4 py-3">
                  <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                      <p className="font-semibold">{request.planName}</p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {formatCurrency(request.price)} · {request.credits} credits
                      </p>
                    </div>
                    <div className="text-sm">
                      <p className="font-medium uppercase tracking-[0.16em] text-primary">{request.status}</p>
                      <p className="mt-1 text-muted-foreground">{formatDate(request.createdAt)}</p>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState title="Chưa có yêu cầu billing" description="Khi bạn đăng ký gói cước, trạng thái chờ duyệt sẽ hiển thị ở đây." />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
