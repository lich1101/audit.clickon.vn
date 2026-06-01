"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { createAdminUser } from "@/lib/account";
import type { UserRole } from "@/types";

export default function AdminCreateUserPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [role, setRole] = useState<UserRole>("user");
  const [balanceUsd, setBalanceUsd] = useState("0");
  const [captchaCredits, setCaptchaCredits] = useState("0");
  const [saving, setSaving] = useState(false);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Tạo tài khoản"
        description="Admin có thể tạo thủ công user hoặc admin mới, kèm số dư ban đầu và lượt giải captcha nếu cần."
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Users", href: "/admin/users" },
          { label: "Tạo tài khoản" }
        ]}
      />

      <Card className="max-w-3xl">
        <CardHeader>
          <CardTitle>Thông tin tài khoản mới</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="create-user-email">Email</Label>
              <Input id="create-user-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="user@example.com" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="create-user-display-name">Tên hiển thị</Label>
              <Input id="create-user-display-name" value={displayName} onChange={(event) => setDisplayName(event.target.value)} placeholder="Tên người dùng" />
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="create-user-password">Mật khẩu</Label>
              <Input id="create-user-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Tối thiểu 6 ký tự" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="create-user-role">Quyền</Label>
              <Select value={role} onValueChange={(value) => setRole(value as UserRole)}>
                <SelectTrigger id="create-user-role">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="user">User</SelectItem>
                  <SelectItem value="admin">Admin</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="create-user-balance-usd">Số dư USD ban đầu</Label>
              <Input
                id="create-user-balance-usd"
                min={0}
                step="0.01"
                type="number"
                value={balanceUsd}
                onChange={(event) => setBalanceUsd(event.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="create-user-captcha-credits">Lượt giải captcha ban đầu</Label>
              <Input
                id="create-user-captcha-credits"
                min={0}
                step={1}
                type="number"
                value={captchaCredits}
                onChange={(event) => setCaptchaCredits(event.target.value)}
              />
            </div>
          </div>

          <div className="flex flex-col gap-3 pt-2 sm:flex-row sm:justify-end">
            <Button type="button" variant="outline" onClick={() => router.push("/admin/users")}>
              Huỷ
            </Button>
            <Button
              type="button"
              disabled={saving}
              onClick={async () => {
                try {
                  setSaving(true);
                  const created = await createAdminUser({
                    email: email.trim(),
                    password,
                    displayName: displayName.trim(),
                    role,
                    balanceUsd: Number(balanceUsd || 0),
                    captchaCredits: Number(captchaCredits || 0),
                  });
                  toast.success("Đã tạo tài khoản mới.");
                  router.push(`/admin/users/${created.uid}`);
                  router.refresh();
                } catch (error) {
                  toast.error(error instanceof Error ? error.message : "Không thể tạo tài khoản.");
                } finally {
                  setSaving(false);
                }
              }}
            >
              {saving ? "Đang tạo..." : "Tạo tài khoản"}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
