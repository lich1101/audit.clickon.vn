"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { CreditBadge } from "@/components/dashboard/credit-badge";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { RoleBadge } from "@/components/dashboard/role-badge";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/hooks/use-auth";
import { fetchAdminUsers } from "@/lib/account";
import { startImpersonation } from "@/lib/impersonation";
import { formatDate } from "@/lib/utils";
import type { AppUser } from "@/types";

export default function AdminUsersPage() {
  const router = useRouter();
  const { profile, refreshProfile } = useAuth();
  const [users, setUsers] = useState<AppUser[]>([]);
  const [search, setSearch] = useState("");
  const [impersonatingUid, setImpersonatingUid] = useState<string | null>(null);

  useEffect(() => {
    void fetchAdminUsers(search).then(setUsers).catch(() => setUsers([]));
  }, [search]);

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();

    if (!keyword) {
      return users;
    }

    return users.filter((user) => [user.email, user.uid, user.displayName ?? ""].some((field) => field.toLowerCase().includes(keyword)));
  }, [search, users]);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Users"
        description="Xem danh sách user, tìm theo email hoặc UID, vào chi tiết hoặc đăng nhập nhanh để kiểm tra luồng như chính user đó."
        breadcrumbs={[{ label: "Admin", href: "/admin" }, { label: "Users" }]}
      />

      <DataTable
        title="User management"
        search={search}
        onSearchChange={setSearch}
        rows={filtered}
        columns={[
          { key: "email", header: "Email", render: (row: AppUser) => row.email },
          { key: "role", header: "Role", render: (row: AppUser) => <RoleBadge role={row.role} /> },
          { key: "balanceUsd", header: "Số dư", render: (row: AppUser) => <CreditBadge balanceUsd={row.balanceUsd} /> },
          { key: "createdAt", header: "Ngày tạo", render: (row: AppUser) => formatDate(row.createdAt) },
          {
            key: "actions",
            header: "Actions",
            render: (row: AppUser) => (
              <div className="flex flex-wrap gap-2">
                <Button asChild size="sm" variant="secondary">
                  <Link href={`/admin/users/${row.uid}`}>Chi tiết</Link>
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={impersonatingUid === row.uid || profile?.uid === row.uid}
                  onClick={async () => {
                    try {
                      setImpersonatingUid(row.uid);
                      const result = await startImpersonation(row);
                      await refreshProfile();
                      router.push("/dashboard");
                      router.refresh();
                      toast.success(result.message ?? `Đã đăng nhập nhanh vào ${row.email}.`);
                    } catch (error) {
                      toast.error(error instanceof Error ? error.message : "Không thể đăng nhập nhanh vào tài khoản này.");
                    } finally {
                      setImpersonatingUid(null);
                    }
                  }}
                >
                  {profile?.uid === row.uid ? "Tài khoản hiện tại" : impersonatingUid === row.uid ? "Đang vào..." : "Đăng nhập nhanh"}
                </Button>
              </div>
            )
          }
        ]}
        empty={<EmptyState title="Không có user nào" description="Bảng user trong MySQL đang trống." />}
      />
    </div>
  );
}
