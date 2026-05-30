"use client";

import { use, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { CreditAdjustmentForm } from "@/components/forms/credit-adjustment-form";
import { CreditBadge } from "@/components/dashboard/credit-badge";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { RoleBadge } from "@/components/dashboard/role-badge";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/layout/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import { fetchAdminUser, fetchCreditTransactions } from "@/lib/account";
import { startImpersonation } from "@/lib/impersonation";
import { formatDate, formatNumber } from "@/lib/utils";
import type { AppUser, CreditLog } from "@/types";

export default function AdminUserDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const { profile, refreshProfile } = useAuth();
  const [user, setUser] = useState<AppUser | null>(null);
  const [logs, setLogs] = useState<CreditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [impersonating, setImpersonating] = useState(false);

  async function loadUser() {
    const [profile, creditLogs] = await Promise.all([fetchAdminUser(id), fetchCreditTransactions({ userId: id, limit: 100 })]);
    setUser(profile);
    setLogs(creditLogs);
  }

  useEffect(() => {
    let mounted = true;

    async function load() {
      const [profile, creditLogs] = await Promise.all([fetchAdminUser(id), fetchCreditTransactions({ userId: id, limit: 100 })]);

      if (!mounted) {
        return;
      }

      setUser(profile);
      setLogs(creditLogs);
    }

    setLoading(true);
    void load()
      .finally(() => {
        if (mounted) {
          setLoading(false);
        }
      });

    return () => {
      mounted = false;
    };
  }, [id]);

  if (loading) {
    return <LoadingState title="Đang tải user..." description="Đang đồng bộ hồ sơ user và credit logs." />;
  }

  if (!user) {
    return <EmptyState title="Không tìm thấy user" description="UID này không tồn tại trong hệ thống." action={{ label: "Về users", href: "/admin/users" }} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={user.displayName ?? user.email}
        description={`UID: ${user.uid}`}
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Users", href: "/admin/users" },
          { label: user.email }
        ]}
      />

      <div className="flex justify-end">
        <Button
          type="button"
          variant="outline"
          disabled={impersonating || profile?.uid === user.uid}
          onClick={async () => {
            try {
              setImpersonating(true);
              const result = await startImpersonation(user);
              await refreshProfile();
              router.push("/dashboard");
              router.refresh();
              toast.success(result.message ?? `Đã đăng nhập nhanh vào ${user.email}.`);
            } catch (error) {
              toast.error(error instanceof Error ? error.message : "Không thể đăng nhập nhanh vào tài khoản này.");
            } finally {
              setImpersonating(false);
            }
          }}
        >
          {profile?.uid === user.uid ? "Tài khoản hiện tại" : impersonating ? "Đang vào..." : "Đăng nhập nhanh"}
        </Button>
      </div>

      <div className="grid gap-5 xl:grid-cols-[0.8fr_1.2fr]">
        <Card>
          <CardHeader>
            <CardTitle>User profile</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <p className="text-sm text-muted-foreground">Email</p>
              <p className="mt-1 font-medium">{user.email}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Role</p>
              <div className="mt-2">
                <RoleBadge role={user.role} />
              </div>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Credits</p>
              <div className="mt-2">
                <CreditBadge balanceUsd={user.balanceUsd} />
              </div>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Created At</p>
              <p className="mt-1 font-medium">{formatDate(user.createdAt)}</p>
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-5">
          <div className="grid gap-5 md:grid-cols-2">
            <CreditAdjustmentForm userId={user.uid} type="add" onMutated={() => void loadUser()} />
            <CreditAdjustmentForm userId={user.uid} type="subtract" onMutated={() => void loadUser()} />
          </div>

          <DataTable
            title="User credit history"
            rows={logs}
            columns={[
              { key: "type", header: "Loại", render: (row: CreditLog) => row.type },
              { key: "amount", header: "Amount", render: (row: CreditLog) => formatNumber(row.amount) },
              { key: "reason", header: "Reason", render: (row: CreditLog) => row.reason },
              { key: "after", header: "Balance after", render: (row: CreditLog) => formatNumber(row.balanceAfter) },
              { key: "createdAt", header: "Thời gian", render: (row: CreditLog) => formatDate(row.createdAt) }
            ]}
            empty={<EmptyState title="Chưa có credit log" description="User này chưa phát sinh giao dịch credit nào." />}
          />
        </div>
      </div>
    </div>
  );
}
