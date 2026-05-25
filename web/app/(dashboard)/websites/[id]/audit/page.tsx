"use client";

import { Download, Play, Settings, Square } from "lucide-react";
import { use, useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import { AuditStatusBadge } from "@/components/dashboard/audit-status-badge";
import { AuditWorkbenchTable } from "@/components/dashboard/audit-workbench-table";
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
import type { AuditRun, AuditRunItem, Website, WebsiteAudit, WebsiteAuditUrlResult } from "@/types";

function progressFor(run?: AuditRun | null) {
  if (!run || run.totalUrls <= 0) {
    return 0;
  }

  return Math.min(100, Math.round((run.processedUrls / run.totalUrls) * 100));
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
    maxParallelItems: 3,
    step2BatchSize: 60,
    step3BatchSize: 30,
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
        maxParallelItems: 3,
        step2BatchSize: 60,
        step3BatchSize: 30,
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

  const itemsByUrl = useMemo(() => {
    const map: Record<string, AuditRunItem | WebsiteAuditUrlResult> = {};

    for (const result of urlResults) {
      map[result.targetUrl] = result;
    }

    for (const item of run?.items ?? []) {
      map[item.targetUrl] = item;
    }

    return map;
  }, [run?.items, urlResults]);

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
  const step2BatchSize = Math.max(1, Number(systemAi.step2BatchSize ?? 60));
  const step3BatchSize = Math.max(1, Number(systemAi.step3BatchSize ?? 30));
  const step2Chunks = selectedUrls.length ? Math.ceil(selectedUrls.length / step2BatchSize) : 0;
  const step3Chunks = selectedUrls.length ? Math.ceil(selectedUrls.length / step3BatchSize) : 0;
  const step2Provider = systemAi.step2AiProvider ?? systemAi.aiProvider;
  const step3Provider = systemAi.step3AiProvider ?? systemAi.aiProvider;
  const formatterChunks = (step2Provider === "gemini_deep_research" ? step2Chunks : 0) + (step3Provider === "gemini_deep_research" ? step3Chunks : 0);
  const estimatedAiCalls = step2Chunks + step3Chunks + formatterChunks;
  const currentCredits = profile?.credits ?? 0;
  const hasEnoughCredits = currentCredits > 0;

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

  function handleUrlListChange(nextUrls: string[]) {
    setUrlList(nextUrls);
    setSelectedUrls((current) => current.filter((url) => nextUrls.includes(url)));
    schedulePersist(nextUrls);
  }

  function handleAddUrl(url: string) {
    handleUrlListChange([...urlList, url]);
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

  async function handleRun() {
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
        targetUrlsInput: selectedUrls.join("\n"),
        categoriesInput: formatCategoriesInput(audit.categories),
        checklistText: audit.checklistText ?? ""
      });
      toast.success("Audit run đã được đưa vào hàng đợi.");
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
        description="Mọi thao tác trên một bảng: chọn URL, chạy audit, xem kết quả và quản lý danh sách."
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: website.name, href: `/websites/${website.id}` },
          { label: "Audit" }
        ]}
      />

      <div className="flex flex-col gap-3 rounded-[24px] border border-border/70 bg-card/80 p-4 shadow-soft md:flex-row md:items-center md:justify-between">
        <div className="min-w-0 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-medium">Bảng audit</p>
            {run ? <AuditStatusBadge status={run.status} /> : null}
            {savingUrls ? <span className="text-xs text-muted-foreground">Đang lưu URL...</span> : null}
          </div>
          <p className="text-sm text-muted-foreground">
            {urlList.length} URL · {audit?.categories.length ?? 0} danh mục
            {` · B2 ${step2Provider}/${systemAi.step2AiModel ?? systemAi.aiModel ?? "default"} · B3 ${step3Provider}/${systemAi.step3AiModel ?? systemAi.aiModel ?? "default"} (hệ thống)`}
            {audit ? ` · Cập nhật ${formatDate(audit.updatedAt)}` : ""}
          </p>
          {selectedUrls.length ? (
            <p className={hasEnoughCredits ? "text-xs text-muted-foreground" : "text-xs font-medium text-destructive"}>
              Dự kiến tối đa {estimatedAiCalls} AI call ({selectedUrls.length} URL; bước 2 tạo {step2Chunks} batch x {step2BatchSize} URL, bước 3 tạo {step3Chunks} batch x {step3BatchSize} URL
              {formatterChunks ? `, thêm ${formatterChunks} batch 2.5/3.5 để ép JSON` : ""}). Credit sẽ trừ theo token thực tế sau từng lần gọi; hiện có {currentCredits} credit.
            </p>
          ) : null}
          {run ? (
            <div className="space-y-1.5">
              <ProgressBar className="h-2 max-w-md" value={progressPercent} />
              <p className="text-xs text-muted-foreground">
                Run #{run.publicId.slice(-8)} · {run.processedUrls}/{run.totalUrls} hoàn tất · {activeUrls} đang chạy · {queuedUrls} chờ xử lý · {progressPercent}%
                {isPreparingRun ? " · Đang chuẩn bị chunk AI" : ""}
              </p>
            </div>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" onClick={handleRun} disabled={running || isRunActive || selectedUrls.length === 0}>
            <Play className="size-4" />
            {running ? "Đang khởi chạy..." : `Run (${selectedUrls.length})`}
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
        itemsByUrl={itemsByUrl}
        run={run}
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
