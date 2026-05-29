"use client";

import { Check, ListChecks, Pencil, Plus, Rows3, Trash2, X } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { AuditStep1ReaderButton } from "@/components/dashboard/audit-step1-reader-button";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { isHttpUrl } from "@/lib/validators";
import type { AuditWorkbenchRow } from "@/lib/audit-workbench-data";
import type { AuditRunItemStatus } from "@/types";

export type { AuditWorkbenchRow };

function ScoreCell({ score }: { score?: number | null }) {
  if (typeof score !== "number") {
    return <span className="text-muted-foreground">—</span>;
  }

  const tone =
    score >= 80 ? "text-emerald-600 dark:text-emerald-300" : score >= 60 ? "text-amber-600 dark:text-amber-300" : "text-red-600 dark:text-red-300";

  return <span className={`font-semibold ${tone}`}>{score}</span>;
}

function stageLabelForSource(source?: string | null) {
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
    return "Chờ bước 3";
  }

  if (source === "url_only_batch_step3_running") {
    return "Bước 3: audit onpage";
  }

  if (source === "audit_deep_research_running" || source === "audit_deep_research") {
    return "Bước 3: deep research";
  }

  return null;
}

function hasStep1Data(row?: AuditWorkbenchRow | null) {
  return Boolean(
    row?.pageTitle?.trim() ||
      row?.metaDescription?.trim() ||
      row?.contentExcerpt?.trim() ||
      row?.contentSource?.trim() ||
      row?.contentError?.trim()
  );
}

function hasStep2Data(row?: AuditWorkbenchRow | null) {
  return Boolean(row?.primaryKeyword?.trim() && row?.categoryName?.trim() && row?.categoryUrl?.trim());
}

function hasStep3Data(row?: AuditWorkbenchRow | null) {
  return typeof row?.auditScore === "number" || Boolean(row?.auditRecommendations?.length || row?.contentRevisionDirection?.trim());
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
  websiteId,
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
  websiteId: string;
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
    const step1Ready: string[] = [];
    const step1Missing: string[] = [];
    const step2Ready: string[] = [];
    const step2Missing: string[] = [];
    const step3Ready: string[] = [];
    const step3Missing: string[] = [];

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

      if (hasStep1Data(item)) {
        step1Ready.push(url);
      } else {
        step1Missing.push(url);
      }

      if (hasStep2Data(item)) {
        step2Ready.push(url);
      } else {
        step2Missing.push(url);
      }

      if (hasStep3Data(item)) {
        step3Ready.push(url);
      } else {
        step3Missing.push(url);
      }
    }

    return { completed, failed, active, step1Ready, step1Missing, step2Ready, step2Missing, step3Ready, step3Missing };
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
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step1Ready)} disabled={!canSelectUrls || quickGroups.step1Ready.length === 0}>
              Có dữ liệu B1 ({quickGroups.step1Ready.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step1Missing)} disabled={!canSelectUrls || quickGroups.step1Missing.length === 0}>
              Thiếu dữ liệu B1 ({quickGroups.step1Missing.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step2Ready)} disabled={!canSelectUrls || quickGroups.step2Ready.length === 0}>
              Có dữ liệu B2 ({quickGroups.step2Ready.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step2Missing)} disabled={!canSelectUrls || quickGroups.step2Missing.length === 0}>
              Thiếu dữ liệu B2 ({quickGroups.step2Missing.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step3Ready)} disabled={!canSelectUrls || quickGroups.step3Ready.length === 0}>
              Có dữ liệu B3 ({quickGroups.step3Ready.length})
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => applyQuickSelection(quickGroups.step3Missing)} disabled={!canSelectUrls || quickGroups.step3Missing.length === 0}>
              Thiếu dữ liệu B3 ({quickGroups.step3Missing.length})
            </Button>
          </div>
        </div>
      </div>

      <div className="overflow-x-auto">
        <Table className="table-fixed min-w-[1680px]">
          <TableHeader>
            <TableRow>
              <TableHead className="sticky left-0 z-10 w-10 bg-card">
                <Checkbox checked={allSelected} onChange={(event) => toggleAll(event.target.checked)} disabled={!canSelectUrls || urls.length === 0} />
              </TableHead>
              <TableHead className="w-12">#</TableHead>
              <TableHead className="w-[240px]">URL mục tiêu</TableHead>
              <TableHead className="w-[220px]">B1: dữ liệu</TableHead>
              <TableHead className="w-[300px]">B1: nội dung</TableHead>
              <TableHead className="w-[124px]">Trạng thái</TableHead>
              <TableHead className="w-[170px]">Từ khóa chính</TableHead>
              <TableHead className="w-[190px]">Danh mục</TableHead>
              <TableHead className="w-[80px]">Điểm</TableHead>
              <TableHead className="w-[240px]">Đề xuất</TableHead>
              <TableHead className="w-[220px]">Lỗi</TableHead>
              <TableHead className="w-[96px] text-right">Thao tác</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {urls.length === 0 ? (
              <TableRow>
                <TableCell colSpan={12} className="py-10 text-center text-sm text-muted-foreground">
                  Chưa có URL. Thêm dòng mới ở cuối bảng hoặc mở Cấu hình audit.
                </TableCell>
              </TableRow>
            ) : (
              urls.map((url, index) => {
                const item = itemsByUrl[url];
                const status = item?.status;
                const stageLabel = stageLabelForSource(item?.extractionSource);

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
                    <TableCell className="w-[240px]">
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
                        <div className="max-w-[240px]">
                          <p className="line-clamp-4 break-all text-sm font-medium">{url}</p>
                        </div>
                      )}
                    </TableCell>
                    <TableCell className="w-[220px]">
                      {item?.pageTitle || item?.metaDescription || item?.contentSource ? (
                        <div className="max-w-[220px] space-y-1">
                          {item?.pageTitle ? <p className="line-clamp-3 text-sm font-medium">{item.pageTitle}</p> : <p className="text-sm text-muted-foreground">Chưa có title</p>}
                          {item?.metaDescription ? <p className="line-clamp-3 text-xs text-muted-foreground">{item.metaDescription}</p> : null}
                          <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {item?.contentSource ? <span className="rounded-full bg-secondary/60 px-2 py-1">{item.contentSource}</span> : null}
                            {item ? (
                              <AuditStep1ReaderButton
                                preview={{
                                  pageTitle: item.pageTitle,
                                  metaDescription: item.metaDescription,
                                  contentExcerpt: item.contentExcerpt,
                                  contentSource: item.contentSource,
                                  contentError: item.contentError
                                }}
                                targetUrl={url}
                                websiteId={websiteId}
                              />
                            ) : null}
                          </div>
                        </div>
                      ) : (
                        <span className="text-sm text-muted-foreground">—</span>
                      )}
                    </TableCell>
                    <TableCell className="w-[300px]">
                      {item?.contentExcerpt ? (
                        <div className="max-w-[300px]">
                          <p className="line-clamp-5 whitespace-pre-wrap break-words text-sm text-muted-foreground">{item.contentExcerpt}</p>
                        </div>
                      ) : item?.contentError ? (
                        <div className="max-w-[300px]">
                          <p className="line-clamp-4 whitespace-pre-wrap break-words text-sm text-amber-600 dark:text-amber-300">{item.contentError}</p>
                        </div>
                      ) : (
                        <span className="text-sm text-muted-foreground">Chưa có nội dung</span>
                      )}
                    </TableCell>
                    <TableCell className="w-[124px]">
                      {!status ? (
                        <span className="text-sm text-muted-foreground">Chưa chạy</span>
                      ) : (
                        <div className="space-y-1">
                          <AuditStatusBadge status={status} />
                          {stageLabel ? <p className="text-xs text-muted-foreground">{stageLabel}</p> : null}
                          {item?.stageHint ? (
                            <p className="text-xs text-amber-700 dark:text-amber-300">{item.stageHint}</p>
                          ) : null}
                        </div>
                      )}
                    </TableCell>
                    <TableCell className="w-[170px]">
                      <div className="max-w-[170px]">
                        <p className="line-clamp-4 break-words text-sm">{item?.primaryKeyword ?? "—"}</p>
                      </div>
                    </TableCell>
                    <TableCell className="w-[190px]">
                      {item?.categoryName ? (
                        <div className="max-w-[190px] space-y-1">
                          <p className="line-clamp-3 text-sm font-medium">{item.categoryName}</p>
                          {item.categoryUrl ? <p className="line-clamp-3 break-all text-xs text-muted-foreground">{item.categoryUrl}</p> : null}
                        </div>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell className="w-[80px]">
                      <ScoreCell score={item?.auditScore} />
                    </TableCell>
                    <TableCell className="w-[240px]">
                      {item?.auditRecommendations?.length ? (
                        <div className="max-w-[240px] space-y-1 text-sm">
                          <p className="line-clamp-4 break-words">{item.auditRecommendations[0]}</p>
                          {item.contentRevisionDirection ? (
                            <p className="line-clamp-4 break-words text-xs text-muted-foreground">{item.contentRevisionDirection}</p>
                          ) : null}
                        </div>
                      ) : run && status && status !== "completed" && status !== "failed" ? (
                        <span className="text-sm text-muted-foreground">Đang chờ kết quả</span>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell className="w-[220px]">
                      {item?.errorMessage ? (
                        <div className="max-w-[220px]">
                          <p className="line-clamp-5 whitespace-pre-wrap break-words text-sm text-red-600 dark:text-red-300">{item.errorMessage}</p>
                        </div>
                      ) : item?.stageHint ? (
                        <div className="max-w-[220px]">
                          <p className="line-clamp-4 whitespace-pre-wrap break-words text-sm text-amber-700 dark:text-amber-300">{item.stageHint}</p>
                        </div>
                      ) : null}
                    </TableCell>
                    <TableCell className="w-[96px] text-right">
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
              <TableCell colSpan={12}>
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
