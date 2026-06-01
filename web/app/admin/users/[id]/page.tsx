"use client";

import { use, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/dashboard/confirm-dialog";
import { CreditBadge } from "@/components/dashboard/credit-badge";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { RoleBadge } from "@/components/dashboard/role-badge";
import { CreditAdjustmentForm } from "@/components/forms/credit-adjustment-form";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useAuth } from "@/hooks/use-auth";
import { deleteAdminUser, fetchAdminUser, fetchAdminUsers, fetchCreditTransactions, updateAdminUser } from "@/lib/account";
import { startImpersonation } from "@/lib/impersonation";
import { formatDate, formatNumber, formatUsd } from "@/lib/utils";
import type { AppUser, CreditLog, UserRole } from "@/types";

export default function AdminUserDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const { profile, refreshProfile } = useAuth();
  const [user, setUser] = useState<AppUser | null>(null);
  const [users, setUsers] = useState<AppUser[]>([]);
  const [logs, setLogs] = useState<CreditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [impersonating, setImpersonating] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [email, setEmail] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [password, setPassword] = useState("");
  const [role, setRole] = useState<UserRole>("user");
  const [captchaCredits, setCaptchaCredits] = useState(0);
  const [deleteMode, setDeleteMode] = useState<"purge" | "transfer">("purge");
  const [transferToUid, setTransferToUid] = useState("");

  async function loadUser() {
    const [profilePayload, creditLogs, adminUsers] = await Promise.all([
      fetchAdminUser(id),
      fetchCreditTransactions({ userId: id, limit: 100 }),
      fetchAdminUsers(),
    ]);

    setUser(profilePayload);
    setUsers(adminUsers);
    setLogs(creditLogs);
    setEmail(profilePayload.email);
    setDisplayName(profilePayload.displayName ?? "");
    setPassword("");
    setRole(profilePayload.role);
    setCaptchaCredits(profilePayload.captchaCredits ?? 0);
  }

  useEffect(() => {
    let mounted = true;

    setLoading(true);
    void loadUser()
      .catch((error) => {
        if (mounted) {
          toast.error(error instanceof Error ? error.message : "Không thể tải user.");
        }
      })
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

  const transferOptions = users.filter((candidate) => candidate.uid !== user.uid);
  const ownedDataSummary = user.ownedDataSummary;
  const hasActiveRuns = Boolean((ownedDataSummary?.activeAuditRunCount ?? 0) > 0 || (ownedDataSummary?.activeKeywordRankRunCount ?? 0) > 0);
  const isCurrentAccount = profile?.uid === user.uid;
  const deleteBlocked = deleting || isCurrentAccount || hasActiveRuns || (deleteMode === "transfer" && transferToUid === "");
  const deleteDescription =
    deleteMode === "transfer"
      ? "Website, lịch sử audit, keyword rank, số dư và các request của tài khoản này sẽ được chuyển sang user đích trước khi xoá."
      : "Tài khoản sẽ bị xoá cùng dữ liệu website/audit/keyword rank, request và credit log liên quan. Hành động này không thể hoàn tác.";

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

      <div className="grid gap-5 xl:grid-cols-[0.85fr_1.15fr]">
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
                <Label htmlFor="admin-user-email">Email đăng nhập</Label>
                <Input id="admin-user-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="admin-user-display-name">Tên hiển thị</Label>
                <Input id="admin-user-display-name" value={displayName} onChange={(event) => setDisplayName(event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="admin-user-password">Đổi mật khẩu</Label>
                <Input
                  id="admin-user-password"
                  type="password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="Để trống nếu không đổi"
                />
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
                      email: email.trim(),
                      password: password.trim(),
                      displayName: displayName.trim(),
                      role,
                      captchaCredits,
                    });
                    setUser(nextUser);
                    setEmail(nextUser.email);
                    setDisplayName(nextUser.displayName ?? "");
                    setPassword("");
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
          <Card>
            <CardHeader>
              <CardTitle>Dữ liệu đang bám theo tài khoản</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              <div className="rounded-xl border border-border bg-background/70 p-4">
                <p className="text-sm text-muted-foreground">Websites</p>
                <p className="mt-2 text-2xl font-semibold">{formatNumber(ownedDataSummary?.websiteCount ?? 0)}</p>
              </div>
              <div className="rounded-xl border border-border bg-background/70 p-4">
                <p className="text-sm text-muted-foreground">Audit runs</p>
                <p className="mt-2 text-2xl font-semibold">{formatNumber(ownedDataSummary?.auditRunCount ?? 0)}</p>
              </div>
              <div className="rounded-xl border border-border bg-background/70 p-4">
                <p className="text-sm text-muted-foreground">Keyword / runs</p>
                <p className="mt-2 text-2xl font-semibold">
                  {formatNumber(ownedDataSummary?.keywordCount ?? 0)} / {formatNumber(ownedDataSummary?.keywordRunCount ?? 0)}
                </p>
              </div>
              <div className="rounded-xl border border-border bg-background/70 p-4">
                <p className="text-sm text-muted-foreground">Captcha / logs</p>
                <p className="mt-2 text-2xl font-semibold">
                  {formatNumber(ownedDataSummary?.captchaTaskCount ?? 0)} / {formatNumber(ownedDataSummary?.creditTransactionCount ?? 0)}
                </p>
              </div>
              <div className="rounded-xl border border-border bg-background/70 p-4">
                <p className="text-sm text-muted-foreground">Plan / product requests</p>
                <p className="mt-2 text-2xl font-semibold">
                  {formatNumber(ownedDataSummary?.planRequestCount ?? 0)} / {formatNumber(ownedDataSummary?.productRequestCount ?? 0)}
                </p>
              </div>
              <div className="rounded-xl border border-border bg-background/70 p-4">
                <p className="text-sm text-muted-foreground">Run đang chạy</p>
                <p className="mt-2 text-2xl font-semibold">
                  {formatNumber(ownedDataSummary?.activeAuditRunCount ?? 0)} audit · {formatNumber(ownedDataSummary?.activeKeywordRankRunCount ?? 0)} rank
                </p>
              </div>
            </CardContent>
          </Card>

          <div className="grid gap-5 md:grid-cols-2">
            <CreditAdjustmentForm userId={user.uid} type="add" onMutated={() => void loadUser()} />
            <CreditAdjustmentForm userId={user.uid} type="subtract" onMutated={() => void loadUser()} />
          </div>

          <Card className="border-destructive/30">
            <CardHeader>
              <CardTitle>Xoá hoặc chuyển giao tài khoản</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4">
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="admin-user-delete-mode">Cách xử lý dữ liệu</Label>
                  <Select value={deleteMode} onValueChange={(value) => setDeleteMode(value as "purge" | "transfer")}>
                    <SelectTrigger id="admin-user-delete-mode">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="purge">Xoá sạch theo tài khoản</SelectItem>
                      <SelectItem value="transfer">Chuyển giao sang user khác</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {deleteMode === "transfer" ? (
                  <div className="space-y-2">
                    <Label htmlFor="admin-user-transfer-target">Tài khoản nhận dữ liệu</Label>
                    <Select value={transferToUid} onValueChange={setTransferToUid}>
                      <SelectTrigger id="admin-user-transfer-target">
                        <SelectValue placeholder="Chọn user đích" />
                      </SelectTrigger>
                      <SelectContent>
                        {transferOptions.map((candidate) => (
                          <SelectItem key={candidate.uid} value={candidate.uid}>
                            {candidate.email}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                ) : null}
              </div>

              <div className="rounded-xl border border-border bg-background/70 p-4 text-sm text-muted-foreground">
                <p>{deleteDescription}</p>
                {hasActiveRuns ? <p className="mt-2 text-destructive">User này còn run đang chạy. Bạn phải dừng hoặc chờ hoàn tất trước khi xoá.</p> : null}
                {isCurrentAccount ? <p className="mt-2 text-destructive">Bạn không thể tự xoá chính tài khoản admin đang đăng nhập.</p> : null}
              </div>

              <div className="flex justify-end">
                <ConfirmDialog
                  trigger={
                    <Button type="button" variant="destructive" disabled={deleteBlocked}>
                      {deleting ? "Đang xoá..." : "Xoá tài khoản"}
                    </Button>
                  }
                  title="Xác nhận xoá tài khoản"
                  description={deleteDescription}
                  actionLabel={deleteMode === "transfer" ? "Xoá và chuyển giao" : "Xoá sạch"}
                  onConfirm={() => {
                    void (async () => {
                      try {
                        setDeleting(true);
                        const response = await deleteAdminUser(user.uid, {
                          mode: deleteMode,
                          transferToUid: deleteMode === "transfer" ? transferToUid : undefined,
                        });
                        toast.success(response.message ?? "Đã xoá tài khoản.");
                        router.push("/admin/users");
                        router.refresh();
                      } catch (error) {
                        toast.error(error instanceof Error ? error.message : "Không thể xoá tài khoản.");
                      } finally {
                        setDeleting(false);
                      }
                    })();
                  }}
                />
              </div>
            </CardContent>
          </Card>

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
