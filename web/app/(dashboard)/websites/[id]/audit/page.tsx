"use client";

import { use, useEffect, useState } from "react";
import { toast } from "sonner";

import { AuditRunsTable } from "@/components/dashboard/audit-runs-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { AuditForm } from "@/components/forms/audit-form";
import { SeoAuditRunForm } from "@/components/forms/seo-audit-run-form";
import { PageHeader } from "@/components/layout/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import {
  getAuditByWebsiteId,
  getWebsiteById,
  listenToAuditRunsByWebsite
} from "@/lib/firestore";
import { formatDate } from "@/lib/utils";
import type { AuditRun, Website, WebsiteAudit } from "@/types";

export default function WebsiteAuditPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { profile } = useAuth();
  const [website, setWebsite] = useState<Website | null>(null);
  const [audit, setAudit] = useState<WebsiteAudit | null>(null);
  const [runs, setRuns] = useState<AuditRun[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        setLoading(true);
        const nextWebsite = await getWebsiteById(id);

        if (!nextWebsite || nextWebsite.userId !== profile?.uid) {
          setWebsite(null);
          setAudit(null);
          return;
        }

        setWebsite(nextWebsite);
        setAudit(await getAuditByWebsiteId(id));
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Không thể tải dữ liệu audit.");
      } finally {
        setLoading(false);
      }
    }

    if (profile) {
      void load();
    }
  }, [id, profile]);

  useEffect(() => {
    if (!website) {
      return;
    }

    return listenToAuditRunsByWebsite(
      website.id,
      setRuns,
      (error) => toast.error(error.message || "Không thể lắng nghe danh sách audit run.")
    );
  }, [website]);

  if (loading) {
    return <LoadingState title="Đang tải audit..." description="Đang lấy cấu hình website, nguồn audit và các đợt phân tích gần đây." />;
  }

  if (!website || !profile) {
    return <EmptyState title="Không tìm thấy website" description="Website không tồn tại hoặc không thuộc tài khoản hiện tại." action={{ label: "Về websites", href: "/websites" }} />;
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Audit pipeline: ${website.name}`}
        description="Quản lý nguồn URL, danh mục mapping và chạy từng đợt SEO audit theo queue. Kết quả sẽ cập nhật realtime qua Firebase Firestore."
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: website.name, href: `/websites/${website.id}` },
          { label: "Audit" }
        ]}
      />

      <div className="grid gap-5 xl:grid-cols-[0.92fr_1.08fr]">
        <Card>
          <CardHeader>
            <CardTitle>Nguồn audit đã lưu</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4 text-sm">
            <div className="rounded-xl border border-border/70 bg-secondary/35 px-4 py-4">
              <p className="text-muted-foreground">Website gốc</p>
              <p className="mt-1 font-medium break-all">{website.url}</p>
            </div>
            <div className="grid gap-3 md:grid-cols-2">
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4">
                <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">Article URLs</p>
                <p className="mt-2 text-2xl font-semibold">{audit?.articleUrls.length ?? 0}</p>
              </div>
              <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4">
                <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">Categories</p>
                <p className="mt-2 text-2xl font-semibold">{audit?.categories.length ?? 0}</p>
              </div>
            </div>
            <div className="rounded-xl border border-dashed border-border/70 bg-background/70 px-4 py-4">
              <p className="text-muted-foreground">Lần cập nhật cuối</p>
              <p className="mt-1 font-medium">{audit ? formatDate(audit.updatedAt) : "Chưa có"}</p>
            </div>
          </CardContent>
        </Card>

        <AuditForm
          auditId={audit?.id}
          websiteId={website.id}
          userId={profile.uid}
          defaultArticleUrls={audit?.articleUrls}
          defaultCategories={audit?.categories}
          onSaved={(payload) =>
            setAudit({
              id: payload.auditId,
              websiteId: website.id,
              userId: profile.uid,
              articleUrls: payload.articleUrls,
              categories: payload.categories,
              createdAt: audit?.createdAt ?? new Date().toISOString(),
              updatedAt: new Date().toISOString()
            })
          }
        />
      </div>

      <SeoAuditRunForm
        websiteId={website.id}
        websiteName={website.name}
        websiteUrl={website.url}
        defaultArticleUrls={audit?.articleUrls}
        defaultCategories={audit?.categories}
      />

      <AuditRunsTable websiteId={website.id} runs={runs} />
    </div>
  );
}
