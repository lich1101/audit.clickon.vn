"use client";

import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight, Loader2 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { fetchIndexUrls } from "@/lib/index";
import type { IndexUrlList, IndexUrlView } from "@/types";

const PAGE_SIZE_OPTIONS = [10, 20, 50, "all"] as const;
type PageSize = (typeof PAGE_SIZE_OPTIONS)[number];

const STATUS_LABEL: Record<string, string> = {
  SENT: "Đã lập chỉ mục",
  PENDING: "Chưa gửi",
  SENDING: "Đang gửi",
  FAILED: "Lỗi"
};

function formatTime(value?: string | null) {
  if (!value) {
    return "—";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", hour12: false });
}

function statusVariant(status: string) {
  if (status === "SENT") {
    return "success" as const;
  }
  if (status === "FAILED") {
    return "destructive" as const;
  }
  if (status === "SENDING") {
    return "warning" as const;
  }
  return "secondary" as const;
}

export function IndexUrlListDialog({
  view,
  onClose
}: {
  view: IndexUrlView | null;
  onClose: () => void;
}) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [data, setData] = useState<IndexUrlList | null>(null);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState<PageSize>(10);

  useEffect(() => {
    setPage(1);
    setPageSize(10);
    setData(null);
    setError(null);
  }, [view]);

  useEffect(() => {
    if (!view) {
      return;
    }

    let cancelled = false;
    setLoading(true);
    setError(null);

    void fetchIndexUrls(view, page, pageSize)
      .then((response) => {
        if (!cancelled) {
          setData(response.data);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setError(caught instanceof Error ? caught.message : "Không tải được danh sách URL.");
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [view, page, pageSize]);

  const total = data?.total ?? 0;
  const lastPage = data?.lastPage ?? 1;
  const from = total === 0 ? 0 : pageSize === "all" ? 1 : (page - 1) * pageSize + 1;
  const to = pageSize === "all" ? total : Math.min(total, page * pageSize);

  return (
    <Dialog open={view !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-hidden sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>{data?.title ?? "Danh sách URL"}</DialogTitle>
        </DialogHeader>

        {error ? (
          <p className="py-6 text-sm text-destructive">{error}</p>
        ) : (
          <div className="space-y-3">
            <p className="text-sm text-muted-foreground">{total} URL</p>
            <ScrollArea className="h-[52vh] rounded-xl border border-border/70">
              {loading && !data ? (
                <div className="flex items-center gap-2 px-4 py-8 text-sm text-muted-foreground">
                  <Loader2 className="size-4 animate-spin" />
                  Đang tải...
                </div>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>URL</TableHead>
                      <TableHead>Dự án</TableHead>
                      <TableHead>Trạng thái</TableHead>
                      <TableHead>Thời gian</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(data?.urls ?? []).length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={4} className="text-muted-foreground">
                          Chưa có URL.
                        </TableCell>
                      </TableRow>
                    ) : (
                      data?.urls.map((row) => (
                        <TableRow key={`${row.id}-${row.urlExact}`}>
                          <TableCell className="max-w-[420px] break-all font-medium">{row.urlExact}</TableCell>
                          <TableCell className="whitespace-nowrap text-muted-foreground">
                            {row.siteName || row.siteHost || "—"}
                          </TableCell>
                          <TableCell>
                            <Badge variant={statusVariant(row.status)}>{STATUS_LABEL[row.status] ?? row.status}</Badge>
                            {row.lastError ? <p className="mt-1 max-w-[240px] text-xs text-destructive">{row.lastError}</p> : null}
                          </TableCell>
                          <TableCell className="whitespace-nowrap text-muted-foreground">{formatTime(row.sentAt || row.createdAt)}</TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              )}
            </ScrollArea>

            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
              <span>
                {total === 0 ? "0 URL" : `${from}–${to} / ${total}`}
                {loading ? " · đang tải" : ""}
              </span>
              <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2">
                  <span>Hiển thị</span>
                  <select
                    className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground"
                    value={String(pageSize)}
                    onChange={(event) => {
                      const value = event.target.value === "all" ? "all" : (Number(event.target.value) as 10 | 20 | 50);
                      setPageSize(value);
                      setPage(1);
                    }}
                  >
                    {PAGE_SIZE_OPTIONS.map((value) => (
                      <option key={String(value)} value={String(value)}>
                        {value === "all" ? "Tất cả" : value}
                      </option>
                    ))}
                  </select>
                </label>
                <Button
                  type="button"
                  size="icon"
                  variant="outline"
                  className="size-8"
                  disabled={page <= 1 || pageSize === "all"}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  <ChevronLeft className="size-4" />
                </Button>
                <span className="min-w-16 text-center">
                  {page}/{lastPage}
                </span>
                <Button
                  type="button"
                  size="icon"
                  variant="outline"
                  className="size-8"
                  disabled={page >= lastPage || pageSize === "all"}
                  onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
                >
                  <ChevronRight className="size-4" />
                </Button>
              </div>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
