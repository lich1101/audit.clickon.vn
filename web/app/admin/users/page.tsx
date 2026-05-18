"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";

import { CreditBadge } from "@/components/dashboard/credit-badge";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { RoleBadge } from "@/components/dashboard/role-badge";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { listenToAllUsers } from "@/lib/firestore";
import { formatDate } from "@/lib/utils";
import type { AppUser } from "@/types";

export default function AdminUsersPage() {
  const [users, setUsers] = useState<AppUser[]>([]);
  const [search, setSearch] = useState("");

  useEffect(() => listenToAllUsers(setUsers), []);

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();

    if (!keyword) {
      return users;
    }

    return users.filter((user) => [user.email, user.uid, user.displayName ?? ""].some((field) => field.toLowerCase().includes(keyword)));
  }, [search, users]);

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Users"
        description="Xem danh sách user, search theo email/uid và vào trang chi tiết để cộng trừ credit."
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
          { key: "credits", header: "Credits", render: (row: AppUser) => <CreditBadge credits={row.credits} /> },
          { key: "createdAt", header: "Ngày tạo", render: (row: AppUser) => formatDate(row.createdAt) },
          {
            key: "actions",
            header: "Actions",
            render: (row: AppUser) => (
              <Button asChild size="sm" variant="secondary">
                <Link href={`/admin/users/${row.uid}`}>Chi tiết</Link>
              </Button>
            )
          }
        ]}
        empty={<EmptyState title="Không có user nào" description="Firestore collection users đang trống." />}
      />
    </div>
  );
}
