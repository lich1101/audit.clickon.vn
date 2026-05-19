"use client";

import { Plus, Trash2 } from "lucide-react";
import { useMemo, useState } from "react";
import { toast } from "sonner";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { AuditRun, AuditRunItem } from "@/types";

function ScoreCell({ score }: { score?: number | null }) {
  if (typeof score !== "number") {
    return <span className="text-muted-foreground">—</span>;
  }

  const tone =
    score >= 80 ? "text-emerald-600 dark:text-emerald-300" : score >= 60 ? "text-amber-600 dark:text-amber-300" : "text-red-600 dark:text-red-300";

  return <span className={`font-semibold ${tone}`}>{score}</span>;
}

export function AuditWorkbenchTable({
  urls,
  selectedUrls,
  onSelectedChange,
  onDeleteUrl,
  onAddUrl,
  itemsByUrl,
  run
}: {
  urls: string[];
  selectedUrls: string[];
  onSelectedChange: (urls: string[]) => void;
  onDeleteUrl: (url: string) => void;
  onAddUrl: (url: string) => void;
  itemsByUrl: Record<string, AuditRunItem>;
  run: AuditRun | null;
}) {
  const [newUrl, setNewUrl] = useState("");
  const selectedSet = useMemo(() => new Set(selectedUrls), [selectedUrls]);
  const allSelected = urls.length > 0 && selectedUrls.length === urls.length;

  function toggleAll(checked: boolean) {
    onSelectedChange(checked ? [...urls] : []);
  }

  function toggleOne(url: string, checked: boolean) {
    if (checked) {
      onSelectedChange(Array.from(new Set([...selectedUrls, url])));
      return;
    }

    onSelectedChange(selectedUrls.filter((item) => item !== url));
  }

  function submitNewUrl() {
    const value = newUrl.trim();

    if (!value) {
      return;
    }

    try {
      const parsed = new URL(value);
      if (!["http:", "https:"].includes(parsed.protocol)) {
        throw new Error("invalid");
      }
    } catch {
      toast.error("URL không hợp lệ.");
      return;
    }

    if (urls.includes(value)) {
      toast.error("URL đã có trong bảng.");
      return;
    }

    onAddUrl(value);
    setNewUrl("");
  }

  return (
    <div className="overflow-hidden rounded-[20px] border border-border/70 bg-card shadow-soft">
      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10 sticky left-0 z-10 bg-card">
                <Checkbox checked={allSelected} onChange={(event) => toggleAll(event.target.checked)} disabled={urls.length === 0} />
              </TableHead>
              <TableHead className="w-12">#</TableHead>
              <TableHead className="min-w-[280px]">URL mục tiêu</TableHead>
              <TableHead className="min-w-[120px]">Trạng thái</TableHead>
              <TableHead className="min-w-[160px]">Từ khóa chính</TableHead>
              <TableHead className="min-w-[200px]">Danh mục</TableHead>
              <TableHead className="min-w-[80px]">Điểm</TableHead>
              <TableHead className="min-w-[320px]">Đề xuất / lỗi</TableHead>
              <TableHead className="w-12" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {urls.length === 0 ? (
              <TableRow>
                <TableCell colSpan={9} className="py-10 text-center text-sm text-muted-foreground">
                  Chưa có URL. Thêm dòng mới ở cuối bảng hoặc mở Cấu hình audit.
                </TableCell>
              </TableRow>
            ) : (
              urls.map((url, index) => {
                const item = itemsByUrl[url];
                const status = item?.status;

                return (
                  <TableRow key={url}>
                    <TableCell className="sticky left-0 z-10 bg-card">
                      <Checkbox checked={selectedSet.has(url)} onChange={(event) => toggleOne(url, event.target.checked)} />
                    </TableCell>
                    <TableCell className="font-medium">{index + 1}</TableCell>
                    <TableCell>
                      <p className="break-all text-sm font-medium">{url}</p>
                      {item?.pageTitle ? <p className="mt-1 text-xs text-muted-foreground">{item.pageTitle}</p> : null}
                    </TableCell>
                    <TableCell>
                      {!status ? (
                        <span className="text-sm text-muted-foreground">Chưa chạy</span>
                      ) : (
                        <AuditStatusBadge status={status} />
                      )}
                    </TableCell>
                    <TableCell>{item?.primaryKeyword ?? "—"}</TableCell>
                    <TableCell>
                      {item?.categoryName ? (
                        <div className="space-y-1">
                          <p className="text-sm font-medium">{item.categoryName}</p>
                          {item.categoryUrl ? <p className="break-all text-xs text-muted-foreground">{item.categoryUrl}</p> : null}
                        </div>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell>
                      <ScoreCell score={item?.auditScore} />
                    </TableCell>
                    <TableCell>
                      {item?.errorMessage ? (
                        <p className="text-sm text-red-600 dark:text-red-300">{item.errorMessage}</p>
                      ) : item?.auditRecommendations?.length ? (
                        <div className="space-y-1 text-sm">
                          <p>{item.auditRecommendations[0]}</p>
                          {item.contentRevisionDirection ? (
                            <p className="text-xs text-muted-foreground">{item.contentRevisionDirection}</p>
                          ) : null}
                        </div>
                      ) : run ? (
                        <span className="text-sm text-muted-foreground">Đang chờ kết quả</span>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell>
                      <Button type="button" size="icon" variant="ghost" className="size-8" onClick={() => onDeleteUrl(url)}>
                        <Trash2 className="size-4" />
                      </Button>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
            <TableRow>
              <TableCell colSpan={9}>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Input
                    placeholder="https://example.com/bai-viet-moi"
                    value={newUrl}
                    onChange={(event) => setNewUrl(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === "Enter") {
                        event.preventDefault();
                        submitNewUrl();
                      }
                    }}
                  />
                  <Button type="button" variant="secondary" onClick={submitNewUrl}>
                    <Plus className="size-4" />
                    Thêm URL
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
