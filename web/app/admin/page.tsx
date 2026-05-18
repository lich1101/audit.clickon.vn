"use client";

import { BarChart3, CreditCard, DatabaseZap, Users } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/dashboard/confirm-dialog";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { StatCard } from "@/components/dashboard/stat-card";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { listenToAllCreditLogs, listenToAllPlans, listenToAllUsers } from "@/lib/firestore";
import { laravelRequest } from "@/lib/laravel";
import { formatCurrency, formatDate, formatNumber } from "@/lib/utils";
import type { CreditLog, Plan, PlanRequest, AppUser } from "@/types";

export default function AdminHomePage() {
  const [users, setUsers] = useState<AppUser[]>([]);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [creditLogs, setCreditLogs] = useState<CreditLog[]>([]);
  const [planRequests, setPlanRequests] = useState<PlanRequest[]>([]);

  useEffect(() => {
    async function loadRequests() {
      const payload = await laravelRequest<{ data: PlanRequest[] }>("/api/admin/plan-requests");
      setPlanRequests(payload.data);
    }

    const unsubUsers = listenToAllUsers(setUsers);
    const unsubPlans = listenToAllPlans(setPlans);
    const unsubLogs = listenToAllCreditLogs(setCreditLogs);

    void loadRequests()
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải plan requests."));

    return () => {
      unsubUsers();
      unsubPlans();
      unsubLogs();
    };
  }, []);

  const totalCredits = useMemo(() => users.reduce((sum, user) => sum + user.credits, 0), [users]);
  const pendingRequests = useMemo(() => planRequests.filter((item) => item.status === "pending").length, [planRequests]);

  async function refreshRequests() {
    const payload = await laravelRequest<{ data: PlanRequest[] }>("/api/admin/plan-requests");
    setPlanRequests(payload.data);
  }

  async function handleDecision(requestId: number, action: "approve" | "reject") {
    try {
      await laravelRequest(`/api/admin/plan-requests/${requestId}/${action}`, {
        method: "POST",
        body: JSON.stringify({})
      });
      toast.success(action === "approve" ? "Đã duyệt gói cước." : "Đã từ chối gói cước.");
      await refreshRequests();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể cập nhật plan request.");
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Admin overview"
        description="Control room cho user, plan, request duyệt gói cước và credit logs trên toàn hệ thống."
        breadcrumbs={[{ label: "Admin" }]}
      />

      <div className="grid gap-5 xl:grid-cols-4">
        <StatCard title="Total users" value={formatNumber(users.length)} hint="Toàn bộ hồ sơ trong Firestore collection users" icon={Users} />
        <StatCard title="Published plans" value={formatNumber(plans.length)} hint="Bao gồm active và inactive plans" icon={CreditCard} />
        <StatCard title="Pending requests" value={formatNumber(pendingRequests)} hint="Đăng ký gói cước đang chờ admin duyệt" icon={BarChart3} />
        <StatCard title="Credits in circulation" value={formatNumber(totalCredits)} hint="Tổng credit đang hiện hữu trên các tài khoản" icon={DatabaseZap} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Realtime operations digest</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-3">
          <div className="rounded-[28px] border border-border bg-card/70 p-5">
            <p className="text-sm text-muted-foreground">Credit logs</p>
            <p className="mt-3 text-3xl font-semibold">{formatNumber(creditLogs.length)}</p>
          </div>
          <div className="rounded-[28px] border border-border bg-card/70 p-5">
            <p className="text-sm text-muted-foreground">Admin accounts</p>
            <p className="mt-3 text-3xl font-semibold">{formatNumber(users.filter((user) => user.role === "admin").length)}</p>
          </div>
          <div className="rounded-[28px] border border-border bg-card/70 p-5">
            <p className="text-sm text-muted-foreground">Active plans</p>
            <p className="mt-3 text-3xl font-semibold">{formatNumber(plans.filter((plan) => plan.isActive).length)}</p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Pending billing approvals</CardTitle>
        </CardHeader>
        <CardContent>
          {planRequests.length ? (
            <div className="grid gap-4">
              {planRequests.map((request) => (
                <div key={request.id} className="rounded-[28px] border border-border bg-card/70 p-5">
                  <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                      <p className="font-semibold">{request.planName}</p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {request.firebaseUid} · {formatCurrency(request.price)} · {request.credits} credits
                      </p>
                      <p className="mt-2 text-sm text-muted-foreground">
                        {request.status} · {formatDate(request.createdAt)}
                      </p>
                    </div>
                    {request.status === "pending" ? (
                      <div className="flex gap-2">
                        <ConfirmDialog
                          trigger={<Button size="sm">Approve</Button>}
                          title="Duyệt gói cước"
                          description="User sẽ được cộng credit ngay sau khi xác nhận."
                          actionLabel="Approve"
                          onConfirm={() => void handleDecision(request.id, "approve")}
                        />
                        <ConfirmDialog
                          trigger={
                            <Button size="sm" variant="outline">
                              Reject
                            </Button>
                          }
                          title="Từ chối gói cước"
                          description="Yêu cầu đăng ký này sẽ chuyển sang trạng thái rejected."
                          actionLabel="Reject"
                          onConfirm={() => void handleDecision(request.id, "reject")}
                        />
                      </div>
                    ) : null}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState title="Không có yêu cầu chờ duyệt" description="Plan request mới sẽ xuất hiện tại đây để admin xử lý thủ công." />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
