"use client";

import { SettingsForm } from "@/components/forms/settings-form";
import { PageHeader } from "@/components/layout/page-header";
import { useAuth } from "@/hooks/use-auth";

export default function SettingsPage() {
  const { profile } = useAuth();

  if (!profile) {
    return null;
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
