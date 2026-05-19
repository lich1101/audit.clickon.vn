"use client";

import { WebsiteForm } from "@/components/forms/website-form";
import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { useAuth } from "@/hooks/use-auth";

export default function CreateWebsitePage() {
  const { profile } = useAuth();

  if (!profile) {
    return <LoadingState title="Đang tải hồ sơ..." description="Đang chuẩn bị form tạo website." />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Tạo audit website"
        description="Tạo website mới và tuỳ chọn cấu hình URL, danh mục, checklist ngay từ đầu."
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: "Tạo audit website" }
        ]}
      />
      <WebsiteForm userId={profile.uid} />
    </div>
  );
}
