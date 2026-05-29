"use client";

import { Download, Search } from "lucide-react";
import { useDeferredValue, useState } from "react";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { AuditStep1ReaderButton } from "@/components/dashboard/audit-step1-reader-button";
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
    item.contentSource ?? "",
    item.contentExcerpt ?? "",
    item.contentError ?? "",
    item.errorMessage ?? "",
    item.status
  ].some((value) => value.toLowerCase().includes(search));
}

function stageLabel(source?: string | null) {
  if (source === "url_only_batch_step1_running") {
    return "Bước 1: lấy nội dung";
  }

  if (source === "url_only_batch_step1_done") {
    return "Chờ bước 2";
  }

  if (source === "url_only_batch_step1_only_completed") {
    return "Hoàn tất bước 1";
  }

  if (source === "url_only_batch_step2_running") {
    return "Bước 2: keyword + danh mục";
  }

  if (source === "url_only_batch_step2_done") {
    return "Chờ bước 3: audit onpage";
  }

  if (source === "url_only_batch_step2_only_completed") {
    return "Hoàn tất bước 2";
  }

  if (source === "url_only_batch_step3_running") {
    return "Bước 3: audit onpage";
  }

  return source;
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
        <CardTitle>Kết quả chi tiết theo URL</CardTitle>
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
          <Table className="table-fixed min-w-[1580px]">
            <TableHeader>
              <TableRow>
                <TableHead className="w-12">#</TableHead>
                <TableHead className="w-[240px]">URL mục tiêu</TableHead>
                <TableHead className="w-[220px]">B1: dữ liệu</TableHead>
                <TableHead className="w-[300px]">B1: nội dung</TableHead>
                <TableHead className="w-[132px]">Trạng thái</TableHead>
                <TableHead className="w-[170px]">Từ khóa chính</TableHead>
                <TableHead className="w-[190px]">Danh mục</TableHead>
                <TableHead className="w-[92px]">Điểm audit</TableHead>
                <TableHead className="w-[240px]">Đề xuất</TableHead>
                <TableHead className="w-[220px]">Lỗi</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredItems.map((item) => (
                <TableRow key={item.publicId}>
                  <TableCell className="w-12 font-medium">{item.position}</TableCell>
                  <TableCell className="w-[240px]">
                    <div className="max-w-[240px] space-y-2">
                      <p className="line-clamp-4 break-all font-medium">{item.targetUrl}</p>
                      {item.extractionSource ? (
                        <p className="line-clamp-2 text-xs text-muted-foreground">Nguồn dữ liệu: {stageLabel(item.extractionSource)}</p>
                      ) : null}
                    </div>
                  </TableCell>
                  <TableCell className="w-[220px]">
                    <div className="max-w-[220px] space-y-1">
                      {item.pageTitle ? <p className="line-clamp-3 text-sm font-medium">{item.pageTitle}</p> : <p className="text-sm text-muted-foreground">Chưa có title</p>}
                      {item.metaDescription ? <p className="line-clamp-3 text-xs text-muted-foreground">{item.metaDescription}</p> : null}
                      <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        {item.contentSource ? <span className="rounded-full bg-secondary/60 px-2 py-1">{item.contentSource}</span> : null}
                        <AuditStep1ReaderButton
                          itemPublicId={item.publicId}
                          preview={{
                            pageTitle: item.pageTitle,
                            metaDescription: item.metaDescription,
                            contentExcerpt: item.contentExcerpt,
                            contentSource: item.contentSource,
                            contentError: item.contentError
                          }}
                          targetUrl={item.targetUrl}
                          websiteId={item.websiteId}
                        />
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="w-[300px]">
                    {item.contentExcerpt ? (
                      <div className="max-w-[300px]">
                        <p className="line-clamp-5 whitespace-pre-wrap break-words text-sm text-muted-foreground">{item.contentExcerpt}</p>
                      </div>
                    ) : item.contentError ? (
                      <div className="max-w-[300px]">
                        <p className="line-clamp-4 break-words text-sm text-amber-600 dark:text-amber-300">{item.contentError}</p>
                      </div>
                    ) : (
                      <span className="text-sm text-muted-foreground">Chưa có nội dung</span>
                    )}
                  </TableCell>
                  <TableCell className="w-[132px]">
                    <div className="space-y-2">
                      <AuditStatusBadge status={item.status} />
                      <p className="text-xs text-muted-foreground">{formatDate(item.updatedAt)}</p>
                    </div>
                  </TableCell>
                  <TableCell className="w-[170px]">
                    <div className="max-w-[170px]">
                      <p className="line-clamp-4 break-words font-medium">{item.primaryKeyword ?? "Đang chờ phân tích"}</p>
                    </div>
                  </TableCell>
                  <TableCell className="w-[190px]">
                    {item.categoryName ? (
                      <div className="max-w-[190px] space-y-1">
                        <p className="line-clamp-3 font-medium">{item.categoryName}</p>
                        <p className="line-clamp-3 break-all text-xs text-muted-foreground">{item.categoryUrl}</p>
                        {item.categoryMatchReason ? (
                          <p className="line-clamp-3 break-words text-xs text-muted-foreground">{item.categoryMatchReason}</p>
                        ) : null}
                      </div>
                    ) : (
                      <span className="text-sm text-muted-foreground">Chưa phân loại</span>
                    )}
                  </TableCell>
                  <TableCell className="w-[92px]">
                    <ScorePill score={item.auditScore} />
                  </TableCell>
                  <TableCell className="w-[240px]">
                    {item.auditRecommendations.length ? (
                      <div className="max-w-[240px] space-y-2">
                        <p className="line-clamp-4 break-words text-sm">{item.auditRecommendations[0]}</p>
                        {item.contentRevisionDirection ? (
                          <p className="line-clamp-4 break-words text-xs text-muted-foreground">{item.contentRevisionDirection}</p>
                        ) : null}
                      </div>
                    ) : item.status === "completed" || item.status === "failed" ? (
                      "—"
                    ) : (
                      <span className="text-sm text-muted-foreground">Đang chờ kết quả AI</span>
                    )}
                  </TableCell>
                  <TableCell className="w-[220px]">
                    {item.errorMessage ? (
                      <div className="max-w-[220px]">
                        <p className="line-clamp-5 break-words text-sm text-red-600 dark:text-red-300">{item.errorMessage}</p>
                      </div>
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
                ? "Audit run này chưa có item nào hoặc dữ liệu MySQL chưa cập nhật xong."
                : "Không có URL nào khớp với từ khóa tìm kiếm hiện tại."
            }
          />
        )}
        {run.lastError ? <p className="mt-4 text-sm text-red-600 dark:text-red-300">{run.lastError}</p> : null}
      </CardContent>
    </Card>
  );
}
