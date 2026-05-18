"use client";

import { SettingsForm } from "@/components/forms/settings-form";
import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { useAuth } from "@/hooks/use-auth";

export default function SettingsPage() {
  const { profile } = useAuth();

  if (!profile) {
    return <LoadingState title="Đang tải hồ sơ..." description="Đang chuẩn bị trang cài đặt." />;
  }

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Settings"
        description="Cập nhật thông tin hồ sơ Firebase và quan sát dữ liệu account hiện tại."
        breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Settings" }]}
      />
      <SettingsForm profile={profile} />
    </div>
  );
}
