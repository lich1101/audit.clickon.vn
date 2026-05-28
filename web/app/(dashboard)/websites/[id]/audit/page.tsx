"use client";

import { Download, Play, Settings, Square } from "lucide-react";
import { use, useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { AuditWorkbenchTable, type AuditWorkbenchRow } from "@/components/dashboard/audit-workbench-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { ProgressBar } from "@/components/dashboard/progress-bar";
import { SeoAuditRunForm } from "@/components/forms/seo-audit-run-form";
import { urlsToInput } from "@/components/forms/audit-target-url-editor";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { useAuth } from "@/hooks/use-auth";
import { exportAuditRunToExcel } from "@/lib/audit-report";
import {
  ACTIVE_AUDIT_POLL_INTERVAL_MS,
  createAuditRun,
  fetchAuditBoard,
  formatCategoriesInput,
  getAuditRun,
  isActiveAuditRun,
  normalizeAuditRun,
  stopAuditRun
} from "@/lib/audit-runs";
import { listenToAuditRunSignal, saveWebsiteAudit } from "@/lib/firestore";
import { formatDate } from "@/lib/utils";
import type { PublicAuditSettings } from "@/lib/audit-settings";
import type { AuditRun, AuditRunItem, AuditRunStartStep, AuditRunStopAfterStep, AuditWorkflow, Website, WebsiteAudit, WebsiteAuditUrlResult } from "@/types";

const workflowLabels: Record<AuditWorkflow, string> = {
  standard: "Audit chuẩn",
  audit_deep_research: "Audit Deep Research"
};

function progressFor(run?: AuditRun | null) {
  if (!run || run.totalUrls <= 0) {
    return 0;
  }

  return Math.min(100, Math.round((run.processedUrls / run.totalUrls) * 100));
}

function hasStep2SeedData(row?: {
  primaryKeyword?: string | null;
  categoryName?: string | null;
  categoryUrl?: string | null;
} | null) {
  return Boolean(row?.primaryKeyword?.trim() && row.categoryName?.trim() && row.categoryUrl?.trim());
}

function hasStep1SeedData(row?: {
  pageTitle?: string | null;
  metaDescription?: string | null;
  contentExcerpt?: string | null;
  contentSource?: string | null;
  contentError?: string | null;
} | null) {
  return Boolean(
    row?.pageTitle?.trim() ||
      row?.metaDescription?.trim() ||
      row?.contentExcerpt?.trim() ||
      row?.contentSource?.trim() ||
      row?.contentError?.trim()
  );
}

function hasStep3SeedData(row?: {
  auditScore?: number | null;
  auditRecommendations?: string[];
  contentRevisionDirection?: string | null;
} | null) {
  return typeof row?.auditScore === "number" || Boolean(row?.auditRecommendations?.length || row?.contentRevisionDirection?.trim());
}

function preferFilledString(...values: Array<string | null | undefined>) {
  for (const value of values) {
    if (typeof value === "string" && value.trim()) {
      return value;
    }
  }

  return null;
}

function preferStringArray(...values: Array<string[] | null | undefined>) {
  for (const value of values) {
    if (Array.isArray(value) && value.length > 0) {
      return value;
    }
  }

  return [];
}

function mergeAuditWorkbenchRow(
  persisted?: WebsiteAuditUrlResult | null,
  current?: AuditRunItem | null
): AuditWorkbenchRow {
  const errorMessage = current
    ? current.status === "failed"
      ? preferFilledString(current.errorMessage, persisted?.errorMessage)
      : preferFilledString(current.errorMessage)
    : preferFilledString(persisted?.errorMessage);

  return {
    ...persisted,
    ...current,
    status: current?.status ?? persisted?.status,
    extractionSource: current?.extractionSource ?? null,
    contentSource: preferFilledString(current?.contentSource, persisted?.contentSource),
    contentError: preferFilledString(current?.contentError, persisted?.contentError),
    readerUrl: preferFilledString(current?.readerUrl, persisted?.readerUrl),
    pageTitle: preferFilledString(current?.pageTitle, persisted?.pageTitle),
    metaDescription: preferFilledString(current?.metaDescription, persisted?.metaDescription),
    canonicalUrl: preferFilledString(current?.canonicalUrl, persisted?.canonicalUrl),
    headings: current?.headings && Object.keys(current.headings).length > 0 ? current.headings : persisted?.headings,
    metrics: current?.metrics && Object.keys(current.metrics).length > 0 ? current.metrics : persisted?.metrics,
    primaryKeyword: preferFilledString(current?.primaryKeyword, persisted?.primaryKeyword),
    categoryName: preferFilledString(current?.categoryName, persisted?.categoryName),
    categoryUrl: preferFilledString(current?.categoryUrl, persisted?.categoryUrl),
    categoryMatchReason: preferFilledString(current?.categoryMatchReason, persisted?.categoryMatchReason),
    auditScore: current?.auditScore ?? persisted?.auditScore ?? null,
    auditRecommendations: preferStringArray(current?.auditRecommendations, persisted?.auditRecommendations),
    contentRevisionDirection: preferFilledString(current?.contentRevisionDirection, persisted?.contentRevisionDirection),
    contentExcerpt: preferFilledString(current?.contentExcerpt, persisted?.contentExcerpt),
    errorMessage,
  };
}

export default function WebsiteAuditPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { profile, refreshProfile } = useAuth();
  const [website, setWebsite] = useState<Website | null>(null);
  const [audit, setAudit] = useState<WebsiteAudit | null>(null);
  const [run, setRun] = useState<AuditRun | null>(null);
  const [urlResults, setUrlResults] = useState<WebsiteAuditUrlResult[]>([]);
  const [systemAi, setSystemAi] = useState<PublicAuditSettings>({
    aiProvider: "openai",
    aiModel: null,
    step2AiProvider: "openai",
    step2AiModel: null,
    step3AiProvider: "openai",
    step3AiModel: null,
    step2FormatterProvider: "gemini",
    step2FormatterModel: "gemini-2.5-flash",
    step3FormatterProvider: "gemini",
    step3FormatterModel: "gemini-2.5-flash",
    step3FlowMode: "standard",
    maxParallelItems: 3,
    step2BatchSize: 60,
    step3BatchSize: 30,
    deepResearchBatchSize: 5,
    deepResearchResearchProvider: "perplexity",
    deepResearchResearchModel: "sonar-deep-research",
    deepResearchReasoningProvider: "openai",
    deepResearchReasoningModel: "gpt-5.5",
    deepResearchFormatterProvider: "openai",
    deepResearchFormatterModel: "gpt-5.5",
    minCreditsPerAiCall: 0,
    minCreditsPerRun: 0,
    minCreditsPerUrl: 0
  });
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [running, setRunning] = useState(false);
  const [stopping, setStopping] = useState(false);
  const [urlList, setUrlList] = useState<string[]>([]);
  const [selectedUrls, setSelectedUrls] = useState<string[]>([]);
  const [savingUrls, setSavingUrls] = useState(false);
  const saveUrlsTimerRef = useRef<number | undefined>(undefined);
  const runFinishedRef = useRef(false);
  const boardLoadedRef = useRef(false);
  const lastProfileRefreshRef = useRef(0);
  const boardPollInFlightRef = useRef(false);
  const profileUid = profile?.uid;

  useEffect(() => {
    const urls = audit?.articleUrls ?? [];
    setUrlList(urls);
    setSelectedUrls((current) => current.filter((url) => urls.includes(url)));
  }, [audit]);

  useEffect(() => {
    const runUrls = run?.targetUrls ?? [];

    if (!runUrls.length || !isActiveAuditRun(run?.status)) {
      return;
    }

    const urlSet = new Set(urlList);
    setSelectedUrls(runUrls.filter((url) => urlSet.has(url)));
  }, [run?.publicId, run?.status, urlList, run?.targetUrls]);

  async function loadBoard(options?: { silent?: boolean }) {
    try {
      if (!options?.silent) {
        setLoading(true);
      }

      const board = await fetchAuditBoard(id);

      setWebsite({
        id: board.website.id,
        name: board.website.name,
        url: board.website.url,
        userId: profile?.uid ?? "",
        createdAt: "",
        updatedAt: ""
      });
      setAudit(board.audit);
      setRun(board.run);
      setUrlResults(board.urlResults ?? []);
      setSystemAi(board.systemAi ?? {
        aiProvider: "openai",
        aiModel: null,
        step2AiProvider: "openai",
        step2AiModel: null,
        step3AiProvider: "openai",
        step3AiModel: null,
        step2FormatterProvider: "gemini",
        step2FormatterModel: "gemini-2.5-flash",
        step3FormatterProvider: "gemini",
        step3FormatterModel: "gemini-2.5-flash",
        step3FlowMode: "standard",
        maxParallelItems: 3,
        step2BatchSize: 60,
        step3BatchSize: 30,
        deepResearchBatchSize: 5,
        deepResearchResearchProvider: "perplexity",
        deepResearchResearchModel: "sonar-deep-research",
        deepResearchReasoningProvider: "openai",
        deepResearchReasoningModel: "gpt-5.5",
        deepResearchFormatterProvider: "openai",
        deepResearchFormatterModel: "gpt-5.5",
        minCreditsPerAiCall: 0,
        minCreditsPerRun: 0,
        minCreditsPerUrl: 0
      });
    } catch (error) {
      if (!options?.silent) {
        setWebsite(null);
        setAudit(null);
        setRun(null);
        toast.error(error instanceof Error ? error.message : "Không thể tải dữ liệu audit.");
      }
    } finally {
      if (!options?.silent) {
        setLoading(false);
      }
    }
  }

  useEffect(() => {
    boardLoadedRef.current = false;
  }, [id]);

  useEffect(() => {
    if (!profileUid) {
      return;
    }

    void loadBoard({ silent: boardLoadedRef.current });
    boardLoadedRef.current = true;
  }, [id, profileUid]);

  const isRunActive = isActiveAuditRun(run?.status);

  function refreshProfileFromAuditSignal(force = false) {
    const now = Date.now();

    if (!force && now - lastProfileRefreshRef.current < 5000) {
      return;
    }

    lastProfileRefreshRef.current = now;
    void refreshProfile().catch(() => undefined);
  }

  useEffect(() => {
    const publicId = run?.publicId;
    if (!publicId || !isRunActive) {
      return;
    }

    runFinishedRef.current = false;

    const unsubscribe = listenToAuditRunSignal(publicId, (liveRun) => {
      if (!liveRun) {
        return;
      }

      setRun(normalizeAuditRun(liveRun));
      refreshProfileFromAuditSignal(false);

      if (!isActiveAuditRun(liveRun.status) && !runFinishedRef.current) {
        runFinishedRef.current = true;
        refreshProfileFromAuditSignal(true);
        void loadBoard({ silent: true });
      }
    });

    return () => {
      unsubscribe();
    };
  }, [run?.publicId, isRunActive, refreshProfile]);

  useEffect(() => {
    const publicId = run?.publicId;

    if (!publicId || !isRunActive) {
      return;
    }

    const intervalId = window.setInterval(() => {
      if (boardPollInFlightRef.current) {
        return;
      }

      boardPollInFlightRef.current = true;

      void loadBoard({ silent: true }).finally(() => {
        boardPollInFlightRef.current = false;
      });
    }, ACTIVE_AUDIT_POLL_INTERVAL_MS);

    return () => {
      window.clearInterval(intervalId);
    };
  }, [run?.publicId, isRunActive]);

  const itemsByUrl = useMemo(() => {
    const persistedByUrl = new Map(urlResults.map((result) => [result.targetUrl, result]));
    const currentByUrl = new Map((run?.items ?? []).map((item) => [item.targetUrl, item]));
    const map: Record<string, AuditWorkbenchRow> = {};

    for (const url of urlList) {
      map[url] = mergeAuditWorkbenchRow(persistedByUrl.get(url), currentByUrl.get(url));
    }

    return map;
  }, [run?.items, urlList, urlResults]);
  const step3ReadySelectedUrls = useMemo(
    () => selectedUrls.filter((url) => hasStep2SeedData(itemsByUrl[url])),
    [itemsByUrl, selectedUrls]
  );
  const stepCounts = useMemo(() => {
    let step1Ready = 0;
    let step2Ready = 0;
    let step3Ready = 0;

    for (const url of urlList) {
      const row = itemsByUrl[url];

      if (hasStep1SeedData(row)) {
        step1Ready += 1;
      }

      if (hasStep2SeedData(row)) {
        step2Ready += 1;
      }

      if (hasStep3SeedData(row)) {
        step3Ready += 1;
      }
    }

    return {
      step1Ready,
      step1Missing: Math.max(0, urlList.length - step1Ready),
      step2Ready,
      step2Missing: Math.max(0, urlList.length - step2Ready),
      step3Ready,
      step3Missing: Math.max(0, urlList.length - step3Ready)
    };
  }, [itemsByUrl, urlList]);

  const progressPercent = progressFor(run);
  const firstItemError = (run?.items ?? []).find((item) => item.errorMessage)?.errorMessage ?? null;
  const displayError = run?.lastError ?? firstItemError;
  const activeUrls = (run?.items ?? []).filter(
    (item) => item.status === "fetching" || (item.status === "analyzing" && item.extractionSource !== "url_only_batch_step2_done")
  ).length;
  const queuedUrls = (run?.items ?? []).filter(
    (item) => item.status === "queued" || (item.status === "analyzing" && item.extractionSource === "url_only_batch_step2_done")
  ).length;
  const isPreparingRun = run?.status === "processing" && activeUrls === 0 && queuedUrls > 0 && run.processedUrls === 0;
  const configuredWorkflow: AuditWorkflow = systemAi.step3FlowMode ?? "standard";
  const currentCredits = profile?.credits ?? 0;

  async function persistUrlList(nextUrls: string[]) {
    if (!audit || !website || !profile) {
      return;
    }

    try {
      setSavingUrls(true);
      const savedAudit = await saveWebsiteAudit({
        auditId: audit.id,
        websiteId: website.id,
        userId: profile.uid,
        articleUrlsInput: urlsToInput(nextUrls),
        categoriesInput: formatCategoriesInput(audit.categories),
        checklistText: audit.checklistText ?? ""
      });
      setAudit(savedAudit);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu danh sách URL.");
      setUrlList(audit.articleUrls);
      setSelectedUrls(audit.articleUrls);
    } finally {
      setSavingUrls(false);
    }
  }

  function schedulePersist(nextUrls: string[]) {
    if (!audit || nextUrls.join("\n") === audit.articleUrls.join("\n")) {
      return;
    }

    if (saveUrlsTimerRef.current) {
      window.clearTimeout(saveUrlsTimerRef.current);
    }

    saveUrlsTimerRef.current = window.setTimeout(() => {
      void persistUrlList(nextUrls);
    }, 700);
  }

  function handleUrlListChange(nextUrls: string[], options?: { selectedUrls?: string[] }) {
    setUrlList(nextUrls);
    if (options?.selectedUrls) {
      setSelectedUrls(options.selectedUrls);
    } else {
      setSelectedUrls((current) => current.filter((url) => nextUrls.includes(url)));
    }
    schedulePersist(nextUrls);
  }

  function handleAddUrl(url: string) {
    handleUrlListChange([...urlList, url]);
  }

  function handleUpdateUrl(currentUrl: string, nextUrl: string) {
    const normalizedNextUrl = nextUrl.trim();

    if (normalizedNextUrl === currentUrl) {
      return;
    }

    const nextUrls = urlList.map((url) => (url === currentUrl ? normalizedNextUrl : url));
    const nextSelectedUrls = Array.from(
      new Set(selectedUrls.map((url) => (url === currentUrl ? normalizedNextUrl : url)).filter((url) => nextUrls.includes(url)))
    );

    handleUrlListChange(nextUrls, { selectedUrls: nextSelectedUrls });
  }

  function handleDeleteUrl(url: string) {
    handleUrlListChange(urlList.filter((item) => item !== url));
  }

  useEffect(() => {
    return () => {
      if (saveUrlsTimerRef.current) {
        window.clearTimeout(saveUrlsTimerRef.current);
      }
    };
  }, []);

  async function handleRun(startFromStep: AuditRunStartStep = 1, stopAfterStep: AuditRunStopAfterStep = null) {
    if (!selectedUrls.length) {
      toast.error("Chọn ít nhất một URL để chạy audit.");
      return;
    }

    if (!audit?.articleUrls.length) {
      toast.error("Cần lưu ít nhất một URL trước khi chạy.");
      setSettingsOpen(true);
      return;
    }

    if (isRunActive) {
      toast.error("Đang có audit run đang chạy. Hãy dừng run hiện tại trước.");
      return;
    }

    if (startFromStep === 3 && step3ReadySelectedUrls.length === 0) {
      toast.error("Không có URL nào trong lựa chọn hiện tại có đủ keyword + danh mục từ bước 2 để chạy thẳng bước 3.");
      return;
    }

    try {
      setRunning(true);
      const latestProfile = await refreshProfile().catch(() => profile);
      const latestCredits = latestProfile?.credits ?? currentCredits;

      if (latestCredits <= 0) {
        toast.error(`Không đủ credit. Cần có credit trong tài khoản để chạy audit; hệ thống sẽ trừ theo token AI thực tế. Hiện có ${latestCredits}.`);
        return;
      }

      await createAuditRun({
        websiteId: website!.id,
        websiteName: website!.name,
        websiteUrl: website!.url,
        startFromStep,
        stopAfterStep,
        targetUrlsInput: selectedUrls.join("\n"),
        categoriesInput: formatCategoriesInput(audit.categories),
        checklistText: audit.checklistText ?? ""
      }).then((response) => {
        const skippedCount = response.data.skippedTargetUrls.length;
        const baseMessage = response.message || "Audit run đã được đưa vào hàng đợi.";

        if (startFromStep === 3 && skippedCount > 0) {
          toast.warning(baseMessage);
          return;
        }

        toast.success(baseMessage);
      });
      await loadBoard({ silent: true });
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể bắt đầu audit run.");
    } finally {
      setRunning(false);
    }
  }

  async function handleStop() {
    if (!run || !isRunActive) {
      return;
    }

    try {
      setStopping(true);
      await stopAuditRun(run.publicId);
      await loadBoard({ silent: true });
      toast.success("Đã dừng audit run.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể dừng audit run.");
    } finally {
      setStopping(false);
    }
  }

  async function handleExport() {
    if (!run) {
      return;
    }

    try {
      setExporting(true);
      const fullRun = await getAuditRun(run.publicId);
      await exportAuditRunToExcel(fullRun);
      toast.success("Đã xuất báo cáo Excel.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể xuất file Excel.");
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải audit..." description="Đang lấy cấu hình website và bảng kết quả." />;
  }

  if (!website || !profile) {
    return <EmptyState title="Không tìm thấy website" description="Website không tồn tại hoặc không thuộc tài khoản hiện tại." action={{ label: "Về websites", href: "/websites" }} />;
  }

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title={`Audit: ${website.name}`}
        description={website.url}
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: website.name, href: `/websites/${website.id}` },
          { label: "Audit" }
        ]}
      />

      <div className="flex flex-col gap-3 rounded-[24px] border border-border/70 bg-card/80 p-4 shadow-soft md:flex-row md:items-center md:justify-between">
        <div className="min-w-0 space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-medium">Bảng audit</p>
            {run ? <AuditStatusBadge status={run.status} /> : null}
            <span className="rounded-full border border-border/70 bg-secondary/50 px-2.5 py-1 text-xs font-medium">
              {workflowLabels[configuredWorkflow]}
            </span>
            {run?.stopAfterStep === 1 ? (
              <span className="rounded-full border border-border/70 bg-secondary/50 px-2.5 py-1 text-xs font-medium">
                Chỉ chạy bước 1
              </span>
            ) : null}
            {run?.stopAfterStep === 2 ? (
              <span className="rounded-full border border-border/70 bg-secondary/50 px-2.5 py-1 text-xs font-medium">
                Chỉ chạy bước 2
              </span>
            ) : null}
            {audit ? <span className="text-xs text-muted-foreground">Cập nhật {formatDate(audit.updatedAt)}</span> : null}
            {savingUrls ? <span className="text-xs text-muted-foreground">Đang lưu URL...</span> : null}
          </div>
          <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
            <span className="rounded-full bg-secondary/50 px-2.5 py-1">{urlList.length} URL</span>
            <span className="rounded-full bg-secondary/50 px-2.5 py-1">{audit?.categories.length ?? 0} danh mục</span>
            <span className="rounded-full bg-secondary/50 px-2.5 py-1">{currentCredits} credit</span>
            <span className="rounded-full bg-secondary/50 px-2.5 py-1">B1 {stepCounts.step1Ready}/{urlList.length}</span>
            <span className="rounded-full bg-secondary/50 px-2.5 py-1">B2 {stepCounts.step2Ready}/{urlList.length}</span>
            <span className="rounded-full bg-secondary/50 px-2.5 py-1">B3 {stepCounts.step3Ready}/{urlList.length}</span>
            {run ? <span className="rounded-full bg-secondary/50 px-2.5 py-1">Run #{run.publicId.slice(-8)}</span> : null}
          </div>
          <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
            <span>Thiếu B1: {stepCounts.step1Missing}</span>
            <span>Thiếu B2: {stepCounts.step2Missing}</span>
            <span>Thiếu B3: {stepCounts.step3Missing}</span>
          </div>
          {run ? (
            <div className="space-y-1.5">
              <ProgressBar className="h-2 max-w-md" value={progressPercent} />
              <p className="text-xs text-muted-foreground">
                {run.processedUrls}/{run.totalUrls} hoàn tất · {activeUrls} đang chạy · {queuedUrls} chờ xử lý · {progressPercent}%
                {isPreparingRun ? " · Đang chuẩn bị chunk AI" : ""}
              </p>
            </div>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" onClick={() => void handleRun(1)} disabled={running || isRunActive || selectedUrls.length === 0}>
            <Play className="size-4" />
            {running ? "Đang khởi chạy..." : `Run full (${selectedUrls.length})`}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => void handleRun(1, 1)}
            disabled={running || isRunActive || selectedUrls.length === 0}
          >
            <Play className="size-4" />
            {running ? "Đang khởi chạy..." : `Run bước 1 (${selectedUrls.length})`}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => void handleRun(2, 2)}
            disabled={running || isRunActive || selectedUrls.length === 0}
          >
            <Play className="size-4" />
            {running ? "Đang khởi chạy..." : `Run bước 2 (${selectedUrls.length})`}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => void handleRun(3)}
            disabled={running || isRunActive || selectedUrls.length === 0 || step3ReadySelectedUrls.length === 0}
          >
            <Play className="size-4" />
            {running ? "Đang khởi chạy..." : `Run từ bước 3 (${step3ReadySelectedUrls.length})`}
          </Button>
          <Button
            type="button"
            variant="outline"
            onClick={() => setSelectedUrls(step3ReadySelectedUrls)}
            disabled={isRunActive || step3ReadySelectedUrls.length === 0}
          >
            Chọn URL đủ B2
          </Button>
          {isRunActive ? (
            <Button type="button" variant="destructive" onClick={handleStop} disabled={stopping}>
              <Square className="size-4" />
              {stopping ? "Đang dừng..." : "Stop"}
            </Button>
          ) : null}
          <Button type="button" variant="outline" onClick={() => setSettingsOpen(true)}>
            <Settings className="size-4" />
            Cấu hình
          </Button>
          {run ? (
            <Button type="button" variant="outline" onClick={handleExport} disabled={exporting}>
              <Download className="size-4" />
              {exporting ? "Đang xuất..." : "Xuất Excel"}
            </Button>
          ) : null}
        </div>
      </div>

      {displayError ? (
        <div className="rounded-[20px] border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          <p className="font-medium">Audit run gặp lỗi</p>
          <p className="mt-1 whitespace-pre-wrap break-words">{displayError}</p>
        </div>
      ) : null}

      <AuditWorkbenchTable
        urls={urlList}
        selectedUrls={selectedUrls}
        onSelectedChange={setSelectedUrls}
        onDeleteUrl={handleDeleteUrl}
        onAddUrl={handleAddUrl}
        onUpdateUrl={handleUpdateUrl}
        itemsByUrl={itemsByUrl}
        run={run}
        canManageUrls={!isRunActive}
        canSelectUrls={urlList.length > 0}
      />

      <Sheet open={settingsOpen} onOpenChange={setSettingsOpen}>
        <SheetContent className="left-auto right-0 w-[min(960px,92vw)] max-w-none overflow-y-auto border-l border-r-0 bg-background text-foreground">
          <SheetHeader className="pr-10">
            <SheetTitle>Cấu hình audit</SheetTitle>
            <SheetDescription>
              Lưu danh mục, checklist và AI provider/model. URL quản lý trực tiếp trên bảng chính.
            </SheetDescription>
          </SheetHeader>
          <div className="mt-5">
            <SeoAuditRunForm
              websiteId={website.id}
              userId={profile.uid}
              auditId={audit?.id}
              websiteName={website.name}
              websiteUrl={website.url}
              defaultArticleUrls={audit?.articleUrls}
              defaultCategories={audit?.categories}
              defaultChecklistText={audit?.checklistText}
              showSourceSummary={false}
              onSaved={(savedAudit) => {
                setAudit(savedAudit);
                setSettingsOpen(false);
              }}
            />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}
