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
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useAuth } from "@/hooks/use-auth";
import { fetchAdminUser, fetchCreditTransactions, updateAdminUser } from "@/lib/account";
import { startImpersonation } from "@/lib/impersonation";
import { formatDate, formatNumber, formatUsd } from "@/lib/utils";
import type { AppUser, CreditLog, UserRole } from "@/types";

export default function AdminUserDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const { profile, refreshProfile } = useAuth();
  const [user, setUser] = useState<AppUser | null>(null);
  const [logs, setLogs] = useState<CreditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [impersonating, setImpersonating] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);
  const [displayName, setDisplayName] = useState("");
  const [role, setRole] = useState<UserRole>("user");
  const [captchaCredits, setCaptchaCredits] = useState(0);

  async function loadUser() {
    const [profile, creditLogs] = await Promise.all([fetchAdminUser(id), fetchCreditTransactions({ userId: id, limit: 100 })]);
    setUser(profile);
    setLogs(creditLogs);
    setDisplayName(profile.displayName ?? "");
    setRole(profile.role);
    setCaptchaCredits(profile.captchaCredits ?? 0);
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
      setDisplayName(profile.displayName ?? "");
      setRole(profile.role);
      setCaptchaCredits(profile.captchaCredits ?? 0);
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
                <CreditBadge balanceUsd={user.balanceUsd} credits={user.credits} />
              </div>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Lượt giải captcha tự động</p>
              <p className="mt-1 font-medium">{formatNumber(user.captchaCredits ?? 0)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Created At</p>
              <p className="mt-1 font-medium">{formatDate(user.createdAt)}</p>
            </div>
            <div className="space-y-3 rounded-lg border border-border bg-background/70 p-3">
              <div className="space-y-2">
                <Label htmlFor="admin-user-display-name">Tên hiển thị</Label>
                <Input id="admin-user-display-name" value={displayName} onChange={(event) => setDisplayName(event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="admin-user-role">Quyền tài khoản</Label>
                <Select value={role} onValueChange={(value) => setRole(value as UserRole)}>
                  <SelectTrigger id="admin-user-role">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="user">User</SelectItem>
                    <SelectItem value="admin">Admin</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="admin-user-captcha-credits">Lượt giải captcha</Label>
                <Input
                  id="admin-user-captcha-credits"
                  min={0}
                  step={1}
                  type="number"
                  value={captchaCredits}
                  onChange={(event) => setCaptchaCredits(Number(event.target.value || 0))}
                />
              </div>
              <Button
                type="button"
                className="w-full"
                disabled={savingProfile}
                onClick={async () => {
                  try {
                    setSavingProfile(true);
                    const nextUser = await updateAdminUser(user.uid, {
                      displayName: displayName.trim(),
                      role,
                      captchaCredits,
                    });
                    setUser(nextUser);
                    setDisplayName(nextUser.displayName ?? "");
                    setRole(nextUser.role);
                    setCaptchaCredits(nextUser.captchaCredits ?? 0);
                    toast.success("Đã cập nhật user.");
                  } catch (error) {
                    toast.error(error instanceof Error ? error.message : "Không thể cập nhật user.");
                  } finally {
                    setSavingProfile(false);
                  }
                }}
              >
                {savingProfile ? "Đang lưu..." : "Lưu hồ sơ"}
              </Button>
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
              { key: "amountUsd", header: "USD", render: (row: CreditLog) => formatUsd(row.amountUsd, 6) },
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
