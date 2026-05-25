import Link from "next/link";
import { ArrowUpRight, FileSearch, Globe } from "lucide-react";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { ProgressBar } from "@/components/dashboard/progress-bar";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate } from "@/lib/utils";
import type { Website } from "@/types";

export function WebsiteCard({ website }: { website: Website }) {
  const activeRun = website.activeRun;
  const progressPercent = activeRun?.totalUrls
    ? Math.min(100, Math.round((activeRun.processedUrls / activeRun.totalUrls) * 100))
    : 0;

  return (
    <Card className="group h-full hover:-translate-y-0.5 hover:shadow-md">
      <CardHeader>
        <div className="flex items-start gap-3">
          <div className="flex size-11 items-center justify-center rounded-full bg-secondary text-primary">
            <Globe className="size-5" />
          </div>
          <div className="min-w-0 flex-1">
            <CardTitle className="truncate">{website.name}</CardTitle>
            <p className="mt-1 truncate text-sm text-muted-foreground">{website.url}</p>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <FileSearch className="size-4" />
          Tạo lúc {formatDate(website.createdAt)}
        </div>
        {activeRun ? (
          <div className="space-y-2 rounded-[18px] border border-border/70 bg-secondary/50 p-3">
            <div className="flex items-center justify-between gap-2">
              <p className="text-xs font-medium text-foreground">Audit đang chạy</p>
              <AuditStatusBadge status={activeRun.status} />
            </div>
            <ProgressBar className="h-2" value={progressPercent} />
            <p className="text-xs text-muted-foreground">
              {activeRun.processedUrls}/{activeRun.totalUrls} URL · {progressPercent}%
            </p>
          </div>
        ) : null}
      </CardContent>
      <CardFooter className="justify-between">
        <Button asChild variant="secondary" size="sm">
          <Link href={`/websites/${website.id}`}>Chi tiết</Link>
        </Button>
        <Button asChild variant="ghost" size="icon">
          <Link href={`/websites/${website.id}/audit`}>
            <ArrowUpRight className="size-4 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
          </Link>
        </Button>
      </CardFooter>
    </Card>
  );
}
