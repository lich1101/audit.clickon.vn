"use client";

import { WebsiteForm } from "@/components/forms/website-form";
import { PageHeader } from "@/components/layout/page-header";
import { useAuth } from "@/hooks/use-auth";

export default function CreateWebsitePage() {
  const { profile } = useAuth();

  if (!profile) {
    return null;
  }

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Tạo website"
        description="Khai báo website mới để gắn các bài viết, danh mục và audit tương ứng."
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: "Create" }
        ]}
      />
      <WebsiteForm userId={profile.uid} />
    </div>
  );
}
