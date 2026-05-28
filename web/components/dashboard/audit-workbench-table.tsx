"use client";

import { Check, ListChecks, Pencil, Plus, Rows3, Trash2, X } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { isHttpUrl } from "@/lib/validators";
import type { AuditRunItemStatus } from "@/types";

export type AuditWorkbenchRow = {
  status?: AuditRunItemStatus;
  extractionSource?: string | null;
  pageTitle?: string | null;
  primaryKeyword?: string | null;
  categoryName?: string | null;
  categoryUrl?: string | null;
  categoryMatchReason?: string | null;
  auditScore?: number | null;
  auditRecommendations?: string[];
  contentRevisionDirection?: string | null;
  errorMessage?: string | null;
};

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
  onUpdateUrl,
  itemsByUrl,
  run,
  canManageUrls,
  canSelectUrls = true,
}: {
  urls: string[];
  selectedUrls: string[];
  onSelectedChange: (urls: string[]) => void;
  onDeleteUrl: (url: string) => void;
  onAddUrl: (url: string) => void;
  onUpdateUrl: (currentUrl: string, nextUrl: string) => void;
  itemsByUrl: Record<string, AuditWorkbenchRow>;
  run: { status?: string } | null;
  canManageUrls: boolean;
  canSelectUrls?: boolean;
}) {
  const [newUrl, setNewUrl] = useState("");
  const [editingUrl, setEditingUrl] = useState<string | null>(null);
  const [editingValue, setEditingValue] = useState("");
  const [rangeStart, setRangeStart] = useState("");
  const [rangeEnd, setRangeEnd] = useState("");
  const selectedSet = useMemo(() => new Set(selectedUrls), [selectedUrls]);
  const lastToggledIndexRef = useRef<number | null>(null);
  const allSelected = urls.length > 0 && selectedUrls.length === urls.length;
  const quickGroups = useMemo(() => {
    const completed: string[] = [];
    const failed: string[] = [];
    const active: string[] = [];
    const step2Ready: string[] = [];
    const step2Missing: string[] = [];

    for (const url of urls) {
      const item = itemsByUrl[url];

      if (item?.status === "completed") {
        completed.push(url);
      }

      if (item?.status === "failed") {
        failed.push(url);
      }

      if (item?.status === "fetching" || item?.status === "analyzing" || item?.status === "queued") {
        active.push(url);
      }

      if (item?.primaryKeyword?.trim() && item?.categoryName?.trim() && item?.categoryUrl?.trim()) {
        step2Ready.push(url);
      } else {
        step2Missing.push(url);
      }
    }

    return { completed, failed, active, step2Ready, step2Missing };
  }, [itemsByUrl, urls]);

  useEffect(() => {
    if (!editingUrl) {
      return;
    }

    if (!canManageUrls || !urls.includes(editingUrl)) {
      setEditingUrl(null);
      setEditingValue("");
    }
  }, [canManageUrls, editingUrl, urls]);

  function toggleAll(checked: boolean) {
    onSelectedChange(checked ? [...urls] : []);
  }

  function applyRangeSelection(startIndex: number, endIndex: number, checked: boolean) {
    const [from, to] = [startIndex, endIndex].sort((left, right) => left - right);
    const rangeUrls = urls.slice(from, to + 1);

    if (checked) {
      onSelectedChange(Array.from(new Set([...selectedUrls, ...rangeUrls])));
      return;
    }

    onSelectedChange(selectedUrls.filter((item) => !rangeUrls.includes(item)));
  }

  function toggleOne(url: string, checked: boolean, index: number, shiftKey = false) {
    if (shiftKey && lastToggledIndexRef.current !== null) {
      applyRangeSelection(lastToggledIndexRef.current, index, checked);
      lastToggledIndexRef.current = index;
      return;
    }

    if (checked) {
      onSelectedChange(Array.from(new Set([...selectedUrls, url])));
    } else {
      onSelectedChange(selectedUrls.filter((item) => item !== url));
    }

    lastToggledIndexRef.current = index;
  }

  function applyQuickSelection(targetUrls: string[], mode: "replace" | "add" | "remove" = "replace") {
    if (mode === "replace") {
      onSelectedChange([...targetUrls]);
      return;
    }

    if (mode === "add") {
      onSelectedChange(Array.from(new Set([...selectedUrls, ...targetUrls])));
      return;
    }

    onSelectedChange(selectedUrls.filter((url) => !targetUrls.includes(url)));
  }

  function parseRangeValue(value: string) {
    const parsed = Number(value);

    if (!Number.isInteger(parsed) || parsed < 1 || parsed > urls.length) {
      return null;
    }

    return parsed - 1;
  }

  function submitRangeSelection(remove = false) {
    const startIndex = parseRangeValue(rangeStart);
    const endIndex = parseRangeValue(rangeEnd);

    if (startIndex === null || endIndex === null) {
      toast.error("Khoảng dòng không hợp lệ.");
      return;
    }

    applyRangeSelection(startIndex, endIndex, !remove);
  }

  function submitNewUrl() {
    if (!canManageUrls) {
      return;
    }

    const value = newUrl.trim();

    if (!value) {
      return;
    }

    if (!isHttpUrl(value)) {
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

  function startEditing(url: string) {
    if (!canManageUrls) {
      return;
    }

    setEditingUrl(url);
    setEditingValue(url);
  }

  function cancelEditing() {
    setEditingUrl(null);
    setEditingValue("");
  }

  function submitEditedUrl(currentUrl: string) {
    if (!canManageUrls) {
      return;
    }

    const value = editingValue.trim();

    if (!value) {
      toast.error("URL không được để trống.");
      return;
    }

    if (!isHttpUrl(value)) {
      toast.error("URL không hợp lệ.");
      return;
    }

    if (value !== currentUrl && urls.includes(value)) {
      toast.error("URL đã có trong bảng.");
      return;
    }

    onUpdateUrl(currentUrl, value);
    cancelEditing();
  }

  return (
    <div className="overflow-hidden rounded-[20px] border border-border/70 bg-card shadow-soft">
      <div className="flex flex-col gap-3 border-b border-border/70 px-4 py-4">
        <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div className="flex items-center gap-2 text-sm">
            <ListChecks className="size-4 text-primary" />
            <span className="font-medium">{selectedUrls.length}/{urls.length} URL đã chọn</span>
            {!canManageUrls ? <span className="text-xs text-muted-foreground">Danh sách URL đang khóa chỉnh sửa, nhưng vẫn có thể chọn nhanh.</span> : null}
          </div>
          <div className="flex flex-wrap gap-2">
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(urls)} disabled={!canSelectUrls || urls.length === 0}>
              Chọn tất cả
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => onSelectedChange([])} disabled={!canSelectUrls || selectedUrls.length === 0}>
              Bỏ hết
            </Button>
          </div>
        </div>

        <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
          <div className="flex flex-wrap items-end gap-2">
            <div className="flex flex-col gap-1">
              <span className="text-xs text-muted-foreground">Từ dòng</span>
              <Input className="h-9 w-24" inputMode="numeric" placeholder="1" value={rangeStart} onChange={(event) => setRangeStart(event.target.value)} />
            </div>
            <div className="flex flex-col gap-1">
              <span className="text-xs text-muted-foreground">Đến dòng</span>
              <Input className="h-9 w-24" inputMode="numeric" placeholder={String(urls.length || 1)} value={rangeEnd} onChange={(event) => setRangeEnd(event.target.value)} />
            </div>
            <Button type="button" size="sm" variant="secondary" onClick={() => submitRangeSelection(false)} disabled={!canSelectUrls || urls.length === 0}>
              <Rows3 className="size-4" />
              Chọn khoảng
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => submitRangeSelection(true)} disabled={!canSelectUrls || selectedUrls.length === 0}>
              Bỏ khoảng
            </Button>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.active)} disabled={!canSelectUrls || quickGroups.active.length === 0}>
              Đang chạy ({quickGroups.active.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.failed)} disabled={!canSelectUrls || quickGroups.failed.length === 0}>
              Lỗi ({quickGroups.failed.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.completed)} disabled={!canSelectUrls || quickGroups.completed.length === 0}>
              Hoàn tất ({quickGroups.completed.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step2Ready)} disabled={!canSelectUrls || quickGroups.step2Ready.length === 0}>
              Có dữ liệu B2 ({quickGroups.step2Ready.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step2Missing)} disabled={!canSelectUrls || quickGroups.step2Missing.length === 0}>
              Thiếu dữ liệu B2 ({quickGroups.step2Missing.length})
            </Button>
          </div>
        </div>
      </div>

      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10 sticky left-0 z-10 bg-card">
                <Checkbox checked={allSelected} onChange={(event) => toggleAll(event.target.checked)} disabled={!canSelectUrls || urls.length === 0} />
              </TableHead>
              <TableHead className="w-12">#</TableHead>
              <TableHead className="min-w-[280px]">URL mục tiêu</TableHead>
              <TableHead className="min-w-[120px]">Trạng thái</TableHead>
              <TableHead className="min-w-[160px]">Từ khóa chính</TableHead>
              <TableHead className="min-w-[200px]">Danh mục</TableHead>
              <TableHead className="min-w-[80px]">Điểm</TableHead>
              <TableHead className="min-w-[280px]">Đề xuất</TableHead>
              <TableHead className="min-w-[220px]">Lỗi</TableHead>
              <TableHead className="w-[120px] text-right">Thao tác</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {urls.length === 0 ? (
              <TableRow>
                <TableCell colSpan={10} className="py-10 text-center text-sm text-muted-foreground">
                  Chưa có URL. Thêm dòng mới ở cuối bảng hoặc mở Cấu hình audit.
                </TableCell>
              </TableRow>
            ) : (
              urls.map((url, index) => {
                const item = itemsByUrl[url];
                const status = item?.status;
                const stageLabel =
                  item?.extractionSource === "url_only_batch_step2_running"
                    ? "Bước 2: keyword + danh mục"
                    : item?.extractionSource === "url_only_batch_step2_done"
                      ? "Chờ bước 3"
                      : item?.extractionSource === "url_only_batch_step3_running"
                        ? "Bước 3: audit onpage"
                        : item?.extractionSource === "audit_deep_research_running"
                          ? "Bước 3: deep research"
                          : item?.extractionSource === "audit_deep_research"
                            ? "Bước 3: deep research"
                        : null;

                return (
                  <TableRow key={url} className={selectedSet.has(url) ? "bg-primary/5" : undefined}>
                    <TableCell className="sticky left-0 z-10 bg-card">
                      <Checkbox
                        checked={selectedSet.has(url)}
                        onChange={(event) =>
                          toggleOne(
                            url,
                            event.target.checked,
                            index,
                            "shiftKey" in event.nativeEvent ? Boolean((event.nativeEvent as MouseEvent | KeyboardEvent).shiftKey) : false,
                          )
                        }
                        disabled={!canSelectUrls}
                      />
                    </TableCell>
                    <TableCell className="font-medium">{index + 1}</TableCell>
                    <TableCell>
                      {editingUrl === url ? (
                        <Input
                          value={editingValue}
                          onChange={(event) => setEditingValue(event.target.value)}
                          onKeyDown={(event) => {
                            if (event.key === "Enter") {
                              event.preventDefault();
                              submitEditedUrl(url);
                            }

                            if (event.key === "Escape") {
                              event.preventDefault();
                              cancelEditing();
                            }
                          }}
                          autoFocus
                        />
                      ) : (
                        <p className="break-all text-sm font-medium">{url}</p>
                      )}
                      {item?.pageTitle ? <p className="mt-1 text-xs text-muted-foreground">{item.pageTitle}</p> : null}
                    </TableCell>
                    <TableCell>
                      {!status ? (
                        <span className="text-sm text-muted-foreground">Chưa chạy</span>
                      ) : (
                        <div className="space-y-1">
                          <AuditStatusBadge status={status} />
                          {stageLabel ? <p className="text-xs text-muted-foreground">{stageLabel}</p> : null}
                        </div>
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
                      {item?.auditRecommendations?.length ? (
                        <div className="space-y-1 text-sm">
                          <p>{item.auditRecommendations[0]}</p>
                          {item.contentRevisionDirection ? (
                            <p className="text-xs text-muted-foreground">{item.contentRevisionDirection}</p>
                          ) : null}
                        </div>
                      ) : run && status && status !== "completed" && status !== "failed" ? (
                        <span className="text-sm text-muted-foreground">Đang chờ kết quả</span>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell>
                      {item?.errorMessage ? (
                        <p className="whitespace-pre-wrap break-words text-sm text-red-600 dark:text-red-300">{item.errorMessage}</p>
                      ) : null}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        {editingUrl === url ? (
                          <>
                            <Button type="button" size="icon" variant="ghost" className="size-8" onClick={() => submitEditedUrl(url)}>
                              <Check className="size-4" />
                            </Button>
                            <Button type="button" size="icon" variant="ghost" className="size-8" onClick={cancelEditing}>
                              <X className="size-4" />
                            </Button>
                          </>
                        ) : (
                          <>
                            <Button type="button" size="icon" variant="ghost" className="size-8" onClick={() => startEditing(url)} disabled={!canManageUrls}>
                              <Pencil className="size-4" />
                            </Button>
                            <Button type="button" size="icon" variant="ghost" className="size-8" onClick={() => onDeleteUrl(url)} disabled={!canManageUrls}>
                              <Trash2 className="size-4" />
                            </Button>
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
            <TableRow>
              <TableCell colSpan={10}>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Input
                    placeholder="https://example.com/bai-viet-moi"
                    value={newUrl}
                    onChange={(event) => setNewUrl(event.target.value)}
                    disabled={!canManageUrls}
                    onKeyDown={(event) => {
                      if (event.key === "Enter") {
                        event.preventDefault();
                        submitNewUrl();
                      }
                    }}
                  />
                  <Button type="button" variant="secondary" onClick={submitNewUrl} disabled={!canManageUrls}>
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
