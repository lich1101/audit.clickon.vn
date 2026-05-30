"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import { getAuditByWebsiteId, getWebsiteById } from "@/lib/firestore";
import { formatDate } from "@/lib/utils";
import type { Website, WebsiteAudit } from "@/types";

export default function WebsiteDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { profile } = useAuth();
  const [website, setWebsite] = useState<Website | null>(null);
  const [audit, setAudit] = useState<WebsiteAudit | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        setLoading(true);
        const [nextWebsite, nextAudit] = await Promise.all([getWebsiteById(id), getAuditByWebsiteId(id)]);

        const canAccessAsAdmin = profile?.realRole === "admin" && !profile?.isImpersonating;

        if (!nextWebsite || (nextWebsite.userId !== profile?.uid && !canAccessAsAdmin)) {
          setWebsite(null);
          setAudit(null);
          return;
        }

        setWebsite(nextWebsite);
        setAudit(nextAudit);
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Không thể tải chi tiết website.");
      } finally {
        setLoading(false);
      }
    }

    if (profile) {
      void load();
    }
  }, [id, profile]);

  if (loading) {
    return <LoadingState title="Đang tải website..." description="Đang lấy thông tin website và audit tương ứng." />;
  }

  if (!website) {
    return <EmptyState title="Không tìm thấy website" description="Website không tồn tại hoặc không thuộc quyền truy cập của bạn." action={{ label: "Về danh sách website", href: "/websites" }} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={website.name}
        description={website.url}
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: website.name }
        ]}
        action={{ label: audit ? "Cập nhật audit" : "Tạo audit", href: `/websites/${website.id}/audit` }}
      />

      <div className="grid gap-5 xl:grid-cols-[0.86fr_1.14fr]">
        <Card>
          <CardHeader>
            <CardTitle>Website overview</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4 text-sm">
            <div>
              <p className="text-muted-foreground">URL</p>
              <p className="mt-1 font-medium">{website.url}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Ngày tạo</p>
              <p className="mt-1 font-medium">{formatDate(website.createdAt)}</p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Audit data</CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            {audit ? (
              <>
                <div>
                  <p className="mb-3 text-sm font-medium">Article URLs</p>
                  <div className="flex flex-col gap-2">
                    {audit.articleUrls.map((url) => (
                      <div key={url} className="mail-row rounded-xl border border-border bg-background/70 px-4 py-3 text-sm break-all">
                        {url}
                      </div>
                    ))}
                  </div>
                </div>
                <div>
                  <p className="mb-3 text-sm font-medium">Categories</p>
                  <div className="grid gap-3 md:grid-cols-2">
                    {audit.categories.map((category) => (
                      <div key={`${category.name}-${category.url}`} className="mail-row rounded-xl border border-border bg-background/70 p-4">
                        <p className="font-medium">{category.name}</p>
                        <p className="mt-2 text-sm text-muted-foreground break-all">{category.url}</p>
                      </div>
                    ))}
                  </div>
                </div>
              </>
            ) : (
              <EmptyState title="Chưa có audit" description="Website này chưa được cấu hình article URLs và categories." action={{ label: "Tạo audit", href: `/websites/${website.id}/audit` }} />
            )}
            <Button asChild variant="outline">
              <Link href={`/websites/${website.id}/audit`}>{audit ? "Chỉnh sửa audit" : "Tạo audit"}</Link>
            </Button>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
