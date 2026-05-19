"use client";

import { Download, Search } from "lucide-react";
import { useDeferredValue, useState } from "react";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { EmptyState } from "@/components/dashboard/empty-state";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatDate } from "@/lib/utils";
import type { AuditRun, AuditRunItem } from "@/types";

function ScorePill({ score }: { score?: number | null }) {
  if (typeof score !== "number") {
    return <span className="text-sm text-muted-foreground">Chưa có</span>;
  }

  const tone =
    score >= 80
      ? "text-emerald-600 dark:text-emerald-300"
      : score >= 60
        ? "text-amber-600 dark:text-amber-300"
        : "text-red-600 dark:text-red-300";

  return <span className={`text-sm font-semibold ${tone}`}>{score}/100</span>;
}

function filterItem(item: AuditRunItem, search: string) {
  if (!search) {
    return true;
  }

  return [
    item.targetUrl,
    item.primaryKeyword ?? "",
    item.categoryName ?? "",
    item.categoryUrl ?? "",
    item.pageTitle ?? "",
    item.errorMessage ?? "",
    item.status
  ].some((value) => value.toLowerCase().includes(search));
}

export function AuditRunItemsTable({
  run,
  items,
  onExport,
  exporting
}: {
  run: AuditRun;
  items: AuditRunItem[];
  onExport: () => void;
  exporting?: boolean;
}) {
  const [search, setSearch] = useState("");
  const deferredSearch = useDeferredValue(search.trim().toLowerCase());
  const filteredItems = items.filter((item) => filterItem(item, deferredSearch));

  return (
    <Card>
      <CardHeader className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="space-y-1">
          <CardTitle>Kết quả chi tiết theo URL</CardTitle>
          <p className="text-sm text-muted-foreground">
            Theo dõi realtime trạng thái từng URL, điểm audit, từ khóa chính và định hướng chỉnh sửa nội dung.
          </p>
        </div>
        <div className="flex w-full flex-col gap-3 md:w-auto md:flex-row md:items-center">
          <div className="relative w-full md:w-72">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input className="pl-10" placeholder="Tìm theo URL, keyword..." value={search} onChange={(event) => setSearch(event.target.value)} />
          </div>
          <Button disabled={exporting || items.length === 0} onClick={onExport} type="button" variant="outline">
            <Download className="size-4" />
            {exporting ? "Đang xuất..." : "Xuất Excel"}
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {filteredItems.length ? (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>#</TableHead>
                <TableHead>URL mục tiêu</TableHead>
                <TableHead>Trạng thái</TableHead>
                <TableHead>Từ khóa chính</TableHead>
                <TableHead>Danh mục</TableHead>
                <TableHead>Điểm audit</TableHead>
                <TableHead>Đề xuất</TableHead>
                <TableHead>Lỗi</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredItems.map((item) => (
                <TableRow key={item.publicId}>
                  <TableCell className="font-medium">{item.position}</TableCell>
                  <TableCell className="min-w-[280px]">
                    <div className="space-y-2">
                      <p className="font-medium break-all">{item.targetUrl}</p>
                      {item.pageTitle ? <p className="text-xs text-muted-foreground">{item.pageTitle}</p> : null}
                      {item.extractionSource ? (
                        <p className="text-xs text-muted-foreground">Nguồn crawl: {item.extractionSource}</p>
                      ) : null}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="space-y-2">
                      <AuditStatusBadge status={item.status} />
                      <p className="text-xs text-muted-foreground">{formatDate(item.updatedAt)}</p>
                    </div>
                  </TableCell>
                  <TableCell className="min-w-[180px]">
                    <p className="font-medium">{item.primaryKeyword ?? "Đang chờ phân tích"}</p>
                  </TableCell>
                  <TableCell className="min-w-[220px]">
                    {item.categoryName ? (
                      <div className="space-y-1">
                        <p className="font-medium">{item.categoryName}</p>
                        <p className="text-xs text-muted-foreground break-all">{item.categoryUrl}</p>
                        {item.categoryMatchReason ? (
                          <p className="text-xs text-muted-foreground">{item.categoryMatchReason}</p>
                        ) : null}
                      </div>
                    ) : (
                      <span className="text-sm text-muted-foreground">Chưa phân loại</span>
                    )}
                  </TableCell>
                  <TableCell>
                    <ScorePill score={item.auditScore} />
                  </TableCell>
                  <TableCell className="min-w-[280px]">
                    {item.auditRecommendations.length ? (
                      <div className="space-y-2">
                        <p className="text-sm">{item.auditRecommendations[0]}</p>
                        {item.contentRevisionDirection ? (
                          <p className="text-xs text-muted-foreground">{item.contentRevisionDirection}</p>
                        ) : null}
                      </div>
                    ) : item.status === "completed" || item.status === "failed" ? (
                      "—"
                    ) : (
                      <span className="text-sm text-muted-foreground">Đang chờ kết quả AI</span>
                    )}
                  </TableCell>
                  <TableCell className="min-w-[220px]">
                    {item.errorMessage ? (
                      <p className="text-sm text-red-600 dark:text-red-300">{item.errorMessage}</p>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        ) : (
          <EmptyState
            title="Chưa có kết quả hiển thị"
            description={
              items.length === 0
                ? "Audit run này chưa có item nào hoặc dữ liệu realtime chưa đồng bộ xong."
                : "Không có URL nào khớp với từ khóa tìm kiếm hiện tại."
            }
          />
        )}
        {run.lastError ? <p className="mt-4 text-sm text-red-600 dark:text-red-300">{run.lastError}</p> : null}
      </CardContent>
    </Card>
  );
}
