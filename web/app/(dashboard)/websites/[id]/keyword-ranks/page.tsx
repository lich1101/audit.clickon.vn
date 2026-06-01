"use client";

import { Download, ExternalLink, FileUp, Play, Save, Settings, Square } from "lucide-react";
import Link from "next/link";
import { use, useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/dashboard/empty-state";
import { LoadingState } from "@/components/dashboard/loading-state";
import { WebsiteSectionTabs } from "@/components/dashboard/website-section-tabs";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { Textarea } from "@/components/ui/textarea";
import { useAuth } from "@/hooks/use-auth";
import { downloadKeywordTemplateFile, parseKeywordFile } from "@/lib/audit-files";
import { dedupeKeywordsFromText } from "@/lib/keyword-utils";
import { resolveKeywordRankProxiesForRun } from "@/lib/keyword-rank-proxies";
import {
  completeKeywordRankRun,
  createCaptchaSolveTask,
  createKeywordRankRun,
  fetchKeywordRankBoard,
  heartbeatKeywordRankRun,
  pollCaptchaSolveTask,
  recordKeywordRankRunItem,
  saveKeywordRankKeywords,
  updateKeywordRankPreferences,
} from "@/lib/keyword-ranks";
import {
  keywordRankPreferencesEqual,
  reconcileKeywordRankPreferences,
} from "@/lib/keyword-rank-prefs";
import { formatDate, formatNumber } from "@/lib/utils";
import type { CaptchaSolveTask, KeywordRankBoard, KeywordRankKeyword, KeywordRankPreferences, KeywordRankRunItem } from "@/types";

type ExtensionMessage =
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_EXTENSION_READY"; version: number }
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_STATUS"; payload: { message: string; processed?: number; total?: number } }
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_ITEM_RESULT"; requestId: string; payload: KeywordRankRunItem }
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_COMPLETE"; payload: { stopped?: boolean; error?: string | null } }
  | {
      source: "clickon-rank-extension";
      type: "CLICKON_RANK_CAPTCHA_TASK_REQUEST";
      requestId: string;
      payload: {
        runPublicId: string;
        websiteUrl: string;
        websiteKey: string;
        recaptchaDataSValue?: string | null;
        isInvisible?: boolean;
        userAgent?: string | null;
        cookies?: string | null;
      };
    }
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_PREFS"; payload: KeywordRankPreferences };

function splitKeywords(value: string) {
  return dedupeKeywordsFromText(value);
}

function wait(ms: number) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

function latestRunStatusText(board: KeywordRankBoard) {
  const latestRun = board.latestRun;

  if (!latestRun) {
    return "Chưa chạy";
  }

  const processed = latestRun.processedKeywords ?? 0;
  const total = latestRun.totalKeywords ?? 0;
  const base = `${latestRun.status} (${processed}/${total})`;

  return latestRun.lastError ? `${base} - ${latestRun.lastError}` : base;
}

function statusLabel(status?: string | null) {
  return {
    found: "Tìm thấy",
    not_found: "Không thấy",
    blocked: "Bị chặn",
    error: "Lỗi",
    stopped: "Đã dừng",
    queued: "Chờ chạy",
  }[status ?? ""] ?? "-";
}

function statusClass(status?: string | null) {
  if (status === "found") return "bg-emerald-50 text-emerald-700";
  if (status === "not_found") return "bg-amber-50 text-amber-700";
  if (status === "blocked" || status === "error" || status === "stopped") return "bg-red-50 text-red-700";
  return "bg-secondary text-muted-foreground";
}

export default function WebsiteKeywordRanksPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { profile, refreshProfile } = useAuth();
  const [board, setBoard] = useState<KeywordRankBoard | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [running, setRunning] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [extensionReady, setExtensionReady] = useState(false);
  const [keywordsInput, setKeywordsInput] = useState("");
  const [selectedKeywordIds, setSelectedKeywordIds] = useState<string[]>([]);
  const [delayMin, setDelayMin] = useState(3);
  const [delayMax, setDelayMax] = useState(6);
  const [autoCaptcha, setAutoCaptcha] = useState(false);
  const [googleHost, setGoogleHost] = useState("https://www.google.com");
  const [hl, setHl] = useState("vi");
  const [gl, setGl] = useState("vn");
  const [statusText, setStatusText] = useState("Chưa chạy");
  const prefsSaveTimerRef = useRef<number | null>(null);
  const webPrefsRef = useRef<KeywordRankPreferences | null>(null);
  const extensionReadyRef = useRef(false);
  const prefsSyncInFlightRef = useRef(false);
  const lastHeartbeatAtRef = useRef(0);

  function rememberWebPreferences(preferences: KeywordRankPreferences) {
    webPrefsRef.current = preferences;
  }

  function applyPreferences(preferences: KeywordRankPreferences) {
    setDelayMin(preferences.delayMin);
    setDelayMax(preferences.delayMax);
    setAutoCaptcha(preferences.autoCaptcha);
    setGoogleHost(preferences.googleHost);
    setHl(preferences.hl);
    setGl(preferences.gl);
    rememberWebPreferences(preferences);
  }

  function buildPreferencePayload(overrides: Partial<KeywordRankPreferences> = {}): Partial<KeywordRankPreferences> {
    return {
      delayMin,
      delayMax,
      autoCaptcha,
      googleHost,
      hl,
      gl,
      ...overrides,
    };
  }

  function syncPrefsToExtension(preferences: KeywordRankPreferences) {
    window.postMessage(
      {
        source: "clickon-web",
        type: "CLICKON_RANK_SYNC_PREFS",
        payload: preferences,
      },
      window.location.origin
    );
  }

  function requestExtensionPreferences() {
    window.postMessage({ source: "clickon-web", type: "CLICKON_RANK_REQUEST_PREFS" }, window.location.origin);
  }

  async function reconcileWithExtensionPreferences(extensionPrefs: KeywordRankPreferences) {
    if (prefsSyncInFlightRef.current) return;

    const webPrefs = webPrefsRef.current;
    if (!webPrefs) return;

    const { preferences, source } = reconcileKeywordRankPreferences(webPrefs, extensionPrefs);

    if (source === "equal" || keywordRankPreferencesEqual(preferences, webPrefs)) {
      applyPreferences(preferences);
      return;
    }

    prefsSyncInFlightRef.current = true;

    try {
      if (source === "extension") {
        applyPreferences(preferences);
        const saved = await updateKeywordRankPreferences({
          delayMin: preferences.delayMin,
          delayMax: preferences.delayMax,
          autoCaptcha: preferences.autoCaptcha,
          googleHost: preferences.googleHost,
          hl: preferences.hl,
          gl: preferences.gl,
          updatedAt: preferences.updatedAt ?? undefined,
        });
        applyPreferences(saved);
        syncPrefsToExtension(saved);
        toast.message("Đã đồng bộ cấu hình từ extension popup.");
        return;
      }

      applyPreferences(preferences);
      syncPrefsToExtension(preferences);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể đồng bộ cấu hình keyword rank.");
    } finally {
      prefsSyncInFlightRef.current = false;
    }
  }

  function beginPreferenceSync(preferences: KeywordRankPreferences) {
    rememberWebPreferences(preferences);
    applyPreferences(preferences);

    if (extensionReadyRef.current) {
      requestExtensionPreferences();
      return;
    }

    syncPrefsToExtension(preferences);
  }

  function schedulePreferenceSave(next: Partial<KeywordRankPreferences>) {
    if (prefsSaveTimerRef.current) {
      window.clearTimeout(prefsSaveTimerRef.current);
    }

    prefsSaveTimerRef.current = window.setTimeout(() => {
      void updateKeywordRankPreferences(buildPreferencePayload(next))
        .then((saved) => {
          beginPreferenceSync(saved);
        })
        .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể lưu cấu hình keyword rank."));
    }, 400);
  }
  const activeRunPublicIdRef = useRef<string | null>(null);
  const inputFileRef = useRef<HTMLInputElement | null>(null);

  function postItemResultAck(requestId: string, payload: { ok: boolean; error?: string | null }) {
    window.postMessage(
      {
        source: "clickon-web",
        type: "CLICKON_RANK_ITEM_RESULT_ACK",
        requestId,
        payload,
      },
      window.location.origin
    );
  }

  async function persistRunItemWithRetry(runPublicId: string, item: KeywordRankRunItem) {
    let lastError: unknown = null;

    for (let attempt = 0; attempt < 3; attempt += 1) {
      try {
        return await recordKeywordRankRunItem(runPublicId, item);
      } catch (error) {
        lastError = error;
        if (attempt < 2) {
          await wait(1000 * (attempt + 1));
        }
      }
    }

    throw lastError instanceof Error ? lastError : new Error("Không thể lưu kết quả keyword rank.");
  }

  function heartbeatActiveRun(force = false) {
    const runPublicId = activeRunPublicIdRef.current;
    if (!runPublicId) return;

    const now = Date.now();
    if (!force && now - lastHeartbeatAtRef.current < 15000) {
      return;
    }

    lastHeartbeatAtRef.current = now;
    void heartbeatKeywordRankRun(runPublicId).catch(() => undefined);
  }

  async function loadBoard() {
    const nextBoard = await fetchKeywordRankBoard(id);
    setBoard(nextBoard);
    setKeywordsInput(nextBoard.keywords.map((item) => item.keyword).join("\n"));
    beginPreferenceSync(nextBoard.preferences);
    if (!activeRunPublicIdRef.current) {
      setStatusText(latestRunStatusText(nextBoard));
    }
    setSelectedKeywordIds((current) => {
      const ids = new Set(nextBoard.keywords.map((item) => item.id));
      const retained = current.filter((item) => ids.has(item));
      return retained.length ? retained : nextBoard.keywords.map((item) => item.id);
    });
  }

  useEffect(() => {
    let mounted = true;

    setLoading(true);
    void fetchKeywordRankBoard(id)
      .then((nextBoard) => {
        if (!mounted) return;
        setBoard(nextBoard);
        setKeywordsInput(nextBoard.keywords.map((item) => item.keyword).join("\n"));
        beginPreferenceSync(nextBoard.preferences);
        setStatusText(latestRunStatusText(nextBoard));
        setSelectedKeywordIds(nextBoard.keywords.map((item) => item.id));
      })
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải keyword rank."))
      .finally(() => {
        if (mounted) setLoading(false);
      });

    return () => {
      mounted = false;
    };
  }, [id]);

  useEffect(() => {
    function onMessage(event: MessageEvent<ExtensionMessage>) {
      if (event.source !== window || event.data?.source !== "clickon-rank-extension") {
        return;
      }

      if (event.data.type === "CLICKON_RANK_EXTENSION_READY") {
        extensionReadyRef.current = true;
        setExtensionReady(true);
        requestExtensionPreferences();
        return;
      }

      if (event.data.type === "CLICKON_RANK_PREFS") {
        void reconcileWithExtensionPreferences(event.data.payload);
        return;
      }

      if (event.data.type === "CLICKON_RANK_STATUS") {
        const { message, processed, total } = event.data.payload;
        setStatusText(typeof processed === "number" && typeof total === "number" ? `${message} (${processed}/${total})` : message);
        heartbeatActiveRun(false);
        return;
      }

      if (event.data.type === "CLICKON_RANK_ITEM_RESULT") {
        const runPublicId = activeRunPublicIdRef.current;
        const { payload, requestId } = event.data;
        if (!runPublicId) {
          postItemResultAck(requestId, { ok: false, error: "Không tìm thấy run keyword rank đang hoạt động." });
          return;
        }

        void persistRunItemWithRetry(runPublicId, payload)
          .then(() => {
            postItemResultAck(requestId, { ok: true });
            return loadBoard();
          })
          .catch((error) => {
            const message = error instanceof Error ? error.message : "Không thể lưu kết quả keyword.";
            postItemResultAck(requestId, { ok: false, error: message });
            toast.error(message);
          });
        return;
      }

      if (event.data.type === "CLICKON_RANK_COMPLETE") {
        const runPublicId = activeRunPublicIdRef.current;
        if (!runPublicId) return;
        const payload = event.data.payload;

        void completeKeywordRankRun(runPublicId, {
          status: payload.stopped ? "stopped" : payload.error ? "partial" : undefined,
          error: payload.error ?? null,
        })
          .then(() => Promise.all([loadBoard(), refreshProfile()]))
          .then(() => {
            setRunning(false);
            activeRunPublicIdRef.current = null;
            toast.success(payload.stopped ? "Đã dừng check rank." : "Đã hoàn tất check rank.");
          })
          .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể hoàn tất run keyword rank."));
        return;
      }

      if (event.data.type === "CLICKON_RANK_CAPTCHA_TASK_REQUEST") {
        void solveCaptchaForExtension(event.data.requestId, event.data.payload);
      }
    }

    window.addEventListener("message", onMessage);
    const ping = window.setInterval(() => {
      window.postMessage({ source: "clickon-web", type: "CLICKON_RANK_EXTENSION_PING" }, window.location.origin);
    }, 1500);
    window.postMessage({ source: "clickon-web", type: "CLICKON_RANK_EXTENSION_PING" }, window.location.origin);

    return () => {
      window.removeEventListener("message", onMessage);
      window.clearInterval(ping);
    };
  }, [refreshProfile]);

  async function solveCaptchaForExtension(requestId: string, payload: Parameters<typeof createCaptchaSolveTask>[0]) {
    try {
      let task: CaptchaSolveTask = await createCaptchaSolveTask(payload);
      setBoard((current) => (current ? { ...current, captchaCredits: task.captchaCredits } : current));
      heartbeatActiveRun(true);

      for (let attempt = 0; attempt < 40 && task.status === "processing"; attempt += 1) {
        await new Promise((resolve) => window.setTimeout(resolve, 5000));
        task = await pollCaptchaSolveTask(task.id);
        setBoard((current) => (current ? { ...current, captchaCredits: task.captchaCredits } : current));
        heartbeatActiveRun(true);
      }

      window.postMessage(
        {
          source: "clickon-web",
          type: "CLICKON_RANK_CAPTCHA_TASK_RESPONSE",
          requestId,
          payload: task.status === "ready"
            ? { ok: true, solutionToken: task.solutionToken, captchaCredits: task.captchaCredits }
            : { ok: false, error: task.errorMessage || "2captcha chưa trả kết quả.", captchaCredits: task.captchaCredits },
        },
        window.location.origin
      );
    } catch (error) {
      window.postMessage(
        {
          source: "clickon-web",
          type: "CLICKON_RANK_CAPTCHA_TASK_RESPONSE",
          requestId,
          payload: { ok: false, error: error instanceof Error ? error.message : "Không thể tạo task 2captcha." },
        },
        window.location.origin
      );
    }
  }

  const selectedKeywords = useMemo(() => {
    const selected = new Set(selectedKeywordIds);
    return (board?.keywords ?? []).filter((item) => selected.has(item.id));
  }, [board?.keywords, selectedKeywordIds]);

  async function handleSaveKeywords() {
    const rawLines = keywordsInput.split(/\r?\n/g).map((line) => line.trim()).filter(Boolean);
    const keywords = splitKeywords(keywordsInput);
    const removedDuplicates = Math.max(0, rawLines.length - keywords.length);

    if (!keywords.length) {
      toast.error("Nhập ít nhất một keyword.");
      return [] as KeywordRankKeyword[];
    }

    if (removedDuplicates > 0) {
      setKeywordsInput(keywords.join("\n"));
    }

    try {
      setSaving(true);
      const saved = await saveKeywordRankKeywords(id, keywords);
      await loadBoard();
      toast.success(
        removedDuplicates > 0
          ? `Đã lưu ${saved.length} keyword (đã bỏ ${removedDuplicates} từ khóa trùng).`
          : `Đã lưu ${saved.length} keyword.`
      );
      return saved;
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu keyword.");
      return [] as KeywordRankKeyword[];
    } finally {
      setSaving(false);
    }
  }

  async function handleRun() {
    if (!board) return;
    if (!extensionReady) {
      toast.error("Cần cài Clickon Rank Checker extension trước khi chạy.");
      return;
    }

    const saved = await handleSaveKeywords();
    const keywords = saved.length ? saved : board.keywords;
    const selectedSet = new Set(selectedKeywordIds);
    const matchedSelection = selectedSet.size > 0 ? keywords.filter((item) => selectedSet.has(item.id)) : [];
    const runKeywords = matchedSelection.length ? matchedSelection : keywords;

    if (!runKeywords.length) {
      toast.error("Chọn ít nhất một keyword để chạy.");
      return;
    }

    if (autoCaptcha && board.captchaCredits <= 0) {
      toast.error("Không còn lượt giải captcha tự động. Mua thêm tại trang Sản phẩm.");
      return;
    }

    const preferences = buildPreferencePayload() as KeywordRankPreferences;

    try {
      setStatusText("Đang chuẩn bị proxy (cấu hình admin)...");
      const proxyResolve = await resolveKeywordRankProxiesForRun();
      const { proxyEnabled, proxyUrls } = proxyResolve.data;

      if (proxyEnabled && proxyUrls.length === 0) {
        toast.error("Admin đã bật proxy nhưng chưa có proxy hợp lệ. Liên hệ admin.");
        setStatusText("Thiếu proxy (cấu hình admin).");
        return;
      }

      if (proxyEnabled) {
        toast.message(proxyResolve.message);
      }

      const saved = await updateKeywordRankPreferences(preferences);
      beginPreferenceSync(saved);
      setRunning(true);
      const response = await createKeywordRankRun(id, {
        keywordIds: runKeywords.map((item) => item.id),
        captchaEnabled: autoCaptcha,
      });
      activeRunPublicIdRef.current = response.data.publicId;
      lastHeartbeatAtRef.current = Date.now();
      setStatusText("Đã gửi task sang extension.");

      window.postMessage(
        {
          source: "clickon-web",
          type: "CLICKON_RANK_RUN",
          payload: {
            runPublicId: response.data.publicId,
            websiteId: id,
            targetDomain: board.targetDomain,
            pages: board.serpPages,
            delayMin,
            delayMax,
            googleHost,
            hl,
            gl,
            autoCaptcha,
            proxyEnabled,
            proxyUrls,
            keywords: runKeywords.map((item) => ({ id: item.id, keyword: item.keyword })),
          },
        },
        window.location.origin
      );
      toast.success(response.message ?? "Đã bắt đầu check rank bằng extension.");
      await loadBoard();
    } catch (error) {
      setRunning(false);
      activeRunPublicIdRef.current = null;
      toast.error(error instanceof Error ? error.message : "Không thể tạo run keyword rank.");
    }
  }

  function handleStop() {
    window.postMessage({ source: "clickon-web", type: "CLICKON_RANK_STOP" }, window.location.origin);
    setStatusText("Đang gửi yêu cầu dừng sang extension.");
  }

  async function handleImport(file?: File | null) {
    if (!file) return;

    try {
      const imported = await parseKeywordFile(file);
      const mergedRaw = `${keywordsInput}\n${imported.join("\n")}`;
      const beforeCount = mergedRaw.split(/\r?\n/g).map((line) => line.trim()).filter(Boolean).length;
      const merged = splitKeywords(mergedRaw);
      const removedDuplicates = Math.max(0, beforeCount - merged.length);

      setKeywordsInput(merged.join("\n"));
      toast.success(
        removedDuplicates > 0
          ? `Đã import ${merged.length} keyword (đã bỏ ${removedDuplicates} từ khóa trùng).`
          : `Đã import ${merged.length} keyword.`
      );
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể import keyword.");
    } finally {
      if (inputFileRef.current) {
        inputFileRef.current.value = "";
      }
    }
  }

  if (loading) {
    return <LoadingState title="Đang tải keyword rank..." description="Đang lấy danh sách keyword và kết quả gần nhất." />;
  }

  if (!board || !profile) {
    return <EmptyState title="Không tìm thấy website" description="Website không tồn tại hoặc không thuộc quyền truy cập của bạn." action={{ label: "Về websites", href: "/websites" }} />;
  }

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title={`Thứ hạng keyword: ${board.website.name}`}
        description={`${board.website.url} · Chrome extension sẽ cào Google trên máy người dùng, server chỉ lưu kết quả.`}
        breadcrumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Websites", href: "/websites" },
          { label: board.website.name, href: `/websites/${board.website.id}` },
          { label: "Thứ hạng keyword" },
        ]}
      />

      <WebsiteSectionTabs websiteId={board.website.id} />

      {!extensionReady ? (
        <button
          type="button"
          className="flex w-full items-start gap-3 rounded-2xl border border-amber-300/70 bg-amber-50 px-4 py-4 text-left text-amber-950 transition hover:bg-amber-100"
          onClick={() => {
            const installUrl = board.extension.installUrl?.trim();
            if (installUrl) {
              window.open(installUrl, "_blank", "noopener,noreferrer");
              return;
            }
            toast.error("Admin chưa cấu hình link cài extension.");
          }}
        >
          <ExternalLink className="mt-0.5 size-5 shrink-0" />
          <span>
            <span className="block font-semibold">Cần cài Clickon Rank Checker extension</span>
            <span className="mt-1 block text-sm opacity-90">
              Bấm vào banner này để mở trang cài extension. Nút Run chỉ bật sau khi trình duyệt phát hiện extension.
            </span>
          </span>
        </button>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Bảng check rank</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="grid gap-4 lg:grid-cols-[1.1fr_0.9fr]">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div className="rounded-2xl border border-border bg-background/70 p-4 text-sm">
                <p className="font-medium">Tổng keyword</p>
                <p className="mt-2 text-2xl font-semibold">{formatNumber(board.keywords.length)}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Đã chọn {formatNumber(selectedKeywords.length || board.keywords.length)} keyword để chạy.
                </p>
              </div>
              <div className="rounded-2xl border border-border bg-background/70 p-4 text-sm">
                <p className="font-medium">Extension</p>
                <p className={extensionReady ? "mt-2 font-medium text-emerald-600" : "mt-2 font-medium text-destructive"}>
                  {extensionReady ? "Đã phát hiện Clickon Rank Checker" : "Chưa phát hiện extension trong trình duyệt này"}
                </p>
              </div>
              <div className="rounded-2xl border border-border bg-background/70 p-4 text-sm">
                <p className="font-medium">Lượt captcha</p>
                <p className="mt-2 text-2xl font-semibold">{formatNumber(board.captchaCredits)}</p>
                <Link className="mt-2 inline-block text-xs text-primary underline-offset-4 hover:underline" href="/products">
                  Mua thêm lượt captcha
                </Link>
              </div>
              <label className="flex items-start gap-3 rounded-2xl border border-border bg-background/70 p-4 text-sm">
                <Checkbox
                  disabled={running}
                  checked={autoCaptcha}
                  onChange={(event) => {
                    const checked = event.target.checked;
                    setAutoCaptcha(checked);
                    schedulePreferenceSave({ autoCaptcha: checked });
                  }}
                />
                <span>
                  <span className="block font-medium">Tự động giải captcha bằng 2captcha</span>
                  <span className="mt-1 block text-muted-foreground">
                    Bật: giải ngầm qua 2captcha, trừ 1 lượt khi thành công. Tắt: mở tab Google để bạn giải captcha thủ công rồi tiếp tục.
                  </span>
                </span>
              </label>
            </div>
            <div className="flex flex-col justify-between gap-4 rounded-2xl border border-border bg-background/70 p-4">
              <div className="space-y-2">
                <p className="text-sm font-medium">Trạng thái hiện tại</p>
                <p className="text-sm text-muted-foreground">{statusText}</p>
                <div className="grid gap-3 pt-2 sm:grid-cols-2">
                  <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">Domain</p>
                    <p className="text-sm font-medium break-all">{board.targetDomain}</p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">Google crawl</p>
                    <p className="text-sm font-medium">{board.serpPages} trang / keyword</p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">Delay</p>
                    <p className="text-sm font-medium">
                      {delayMin}s - {delayMax}s
                    </p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">Captcha</p>
                    <p className="text-sm font-medium">{autoCaptcha ? "Tự động qua 2captcha" : "Giải thủ công"}</p>
                  </div>
                  <div className="space-y-1">
                    <p className="text-xs text-muted-foreground">Proxy</p>
                    <p className="text-sm font-medium">
                      {board.proxyPolicy?.enabled ? "Admin đã bật (xoay IP)" : "Tắt — IP trình duyệt"}
                    </p>
                  </div>
                </div>
              </div>

              <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" onClick={() => setSettingsOpen(true)}>
                  <Settings className="size-4" />
                  Cấu hình
                </Button>
                <Button type="button" onClick={() => void handleRun()} disabled={running || !extensionReady || board.keywords.length === 0}>
                  <Play className="size-4" />
                  {running ? "Đang chạy..." : `Run (${selectedKeywords.length || board.keywords.length})`}
                </Button>
                {running ? (
                  <Button type="button" variant="destructive" onClick={handleStop}>
                    <Square className="size-4" />
                    Stop
                  </Button>
                ) : null}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Sheet open={settingsOpen} onOpenChange={setSettingsOpen}>
        <SheetContent className="left-auto right-0 w-[min(920px,92vw)] max-w-none overflow-y-auto border-l border-r-0 bg-background text-foreground">
          <SheetHeader className="pr-10">
            <SheetTitle>Cấu hình check rank</SheetTitle>
            <SheetDescription>
              Nhập keyword, import file mẫu và chỉnh các thông số delay/captcha cho extension Chrome. Cấu hình này chỉ áp dụng cho website hiện tại.
            </SheetDescription>
          </SheetHeader>

          <div className="mt-6 grid gap-6">
            <div className="grid gap-4 lg:grid-cols-[1fr_280px]">
              <div className="space-y-2">
                <Label htmlFor="keywords">Danh sách keyword</Label>
                <Textarea id="keywords" rows={12} value={keywordsInput} onChange={(event) => setKeywordsInput(event.target.value)} placeholder="Mỗi dòng một keyword" />
              </div>
              <div className="grid content-start gap-3">
                <div className="rounded-2xl border border-border bg-background/70 p-4 text-sm">
                  <p className="font-medium">Extension</p>
                  <p className={extensionReady ? "mt-2 text-emerald-600" : "mt-2 text-destructive"}>
                    {extensionReady ? "Đã phát hiện Clickon Rank Checker" : "Chưa phát hiện extension trong trình duyệt này"}
                  </p>
                </div>
                <div className="rounded-2xl border border-border bg-background/70 p-4 text-sm">
                  <p className="font-medium">Lượt captcha</p>
                  <p className="mt-2 text-2xl font-semibold">{formatNumber(board.captchaCredits)}</p>
                  <Link className="mt-2 inline-block text-xs text-primary underline-offset-4 hover:underline" href="/products">
                    Mua thêm lượt captcha
                  </Link>
                </div>
                <label className="flex items-start gap-3 rounded-2xl border border-border bg-background/70 p-4 text-sm">
                  <Checkbox
                    disabled={running}
                    checked={autoCaptcha}
                    onChange={(event) => {
                      const checked = event.target.checked;
                      setAutoCaptcha(checked);
                      schedulePreferenceSave({ autoCaptcha: checked });
                    }}
                  />
                  <span>
                    <span className="block font-medium">Tự động giải captcha bằng 2captcha</span>
                    <span className="mt-1 block text-muted-foreground">
                      Bật: giải ngầm qua 2captcha, trừ 1 lượt khi thành công. Tắt: mở tab Google để bạn giải captcha thủ công rồi tiếp tục.
                    </span>
                  </span>
                </label>
              </div>
            </div>

            <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm">
              <p className="font-medium text-amber-800 dark:text-amber-200">Tránh bị Google chặn (429 / CAPTCHA)</p>
              <ul className="mt-2 list-disc space-y-1 pl-5 text-muted-foreground">
                <li>
                  Check rank chạy trên <strong className="text-foreground">trình duyệt của bạn</strong> (extension), không qua PHP. Hệ thống tự nghỉ ngẫu nhiên{" "}
                  <strong className="text-foreground">{delayMin}–{delayMax} giây</strong> giữa mỗi keyword và giữa các trang SERP.
                </li>
                <li>Khuyến nghị: delay <strong className="text-foreground">3–8 giây</strong> (hoặc 2–5 giây nếu ít keyword); list lớn nên 8–15 giây.</li>
                <li>Nếu gặp 429, extension sẽ <strong className="text-foreground">cooldown ~45–90 giây</strong> rồi thử lại; sau khi bị chặn sẽ nghỉ lâu hơn trước keyword tiếp theo.</li>
                <li>
                  <strong className="text-foreground">Proxy</strong> do admin cấu hình tại Admin → Keyword Rank Settings (bật/tắt, nguồn GitHub, danh sách thủ công). User không nhập hay chỉnh proxy.
                  {board.proxyPolicy?.enabled
                    ? " Hiện admin đã bật proxy — Run sẽ xoay IP qua extension."
                    : " Hiện proxy đang tắt — Run dùng IP trình duyệt của bạn."}
                </li>
              </ul>
            </div>

            <div className="grid gap-3 md:grid-cols-4">
              <div className="space-y-2">
                <Label htmlFor="pages">Số trang Google</Label>
                <Input id="pages" readOnly value={`${board.serpPages} trang (tối thiểu, dừng khi tìm thấy domain)`} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="delay-min">Delay min (giây)</Label>
                <Input
                  id="delay-min"
                  min={2}
                  type="number"
                  value={delayMin}
                  onChange={(event) => {
                    const value = Math.max(2, Number(event.target.value || 2));
                    setDelayMin(value);
                    schedulePreferenceSave({ delayMin: value, delayMax: Math.max(value, delayMax) });
                  }}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="delay-max">Delay max (giây)</Label>
                <p className="text-xs text-muted-foreground">Nghỉ ngẫu nhiên giữa mỗi keyword / mỗi trang Google.</p>
                <Input
                  id="delay-max"
                  min={2}
                  type="number"
                  value={delayMax}
                  onChange={(event) => {
                    const value = Math.max(delayMin, Number(event.target.value || delayMin));
                    setDelayMax(value);
                    schedulePreferenceSave({ delayMax: value });
                  }}
                />
              </div>
              <div className="space-y-2">
                <Label>Domain</Label>
                <Input value={board.targetDomain} readOnly />
              </div>
            </div>

            <div className="flex flex-wrap gap-2">
              <Button type="button" onClick={() => void handleSaveKeywords()} disabled={saving || running}>
                <Save className="size-4" />
                {saving ? "Đang lưu..." : "Lưu keyword"}
              </Button>
              <Button type="button" variant="outline" onClick={() => inputFileRef.current?.click()} disabled={running}>
                <FileUp className="size-4" />
                Import keyword
              </Button>
              <Button type="button" variant="outline" onClick={() => void downloadKeywordTemplateFile()}>
                <Download className="size-4" />
                Tải file mẫu
              </Button>
              <input ref={inputFileRef} className="hidden" type="file" accept=".xlsx,.xls,.csv,.txt,text/plain" onChange={(event) => void handleImport(event.target.files?.[0])} />
            </div>
          </div>
        </SheetContent>
      </Sheet>

      <Card>
        <CardHeader>
          <CardTitle>Keyword đang quản lý</CardTitle>
        </CardHeader>
        <CardContent>
          {board.keywords.length ? (
            <div className="overflow-auto rounded-2xl border border-border">
              <table className="w-full min-w-[980px] text-sm">
                <thead className="bg-secondary/50 text-left text-xs text-muted-foreground">
                  <tr>
                    <th className="w-12 px-3 py-3">
                      <Checkbox
                        checked={selectedKeywordIds.length === board.keywords.length}
                        onChange={(event) => setSelectedKeywordIds(event.target.checked ? board.keywords.map((item) => item.id) : [])}
                      />
                    </th>
                    <th className="w-16 px-3 py-3">#</th>
                    <th className="w-[280px] px-3 py-3">Keyword</th>
                    <th className="w-32 px-3 py-3">Trạng thái</th>
                    <th className="w-24 px-3 py-3">Rank</th>
                    <th className="w-24 px-3 py-3">Trang</th>
                    <th className="w-[280px] px-3 py-3">URL</th>
                    <th className="w-[240px] px-3 py-3">Lỗi</th>
                    <th className="w-36 px-3 py-3">Cập nhật</th>
                  </tr>
                </thead>
                <tbody>
                  {board.keywords.map((keyword, index) => (
                    <tr key={keyword.id} className="border-t border-border align-top">
                      <td className="px-3 py-3">
                        <Checkbox
                          checked={selectedKeywordIds.includes(keyword.id)}
                          onChange={(event) =>
                            setSelectedKeywordIds((current) => event.target.checked ? Array.from(new Set([...current, keyword.id])) : current.filter((item) => item !== keyword.id))
                          }
                        />
                      </td>
                      <td className="px-3 py-3">{index + 1}</td>
                      <td className="px-3 py-3 font-medium">{keyword.keyword}</td>
                      <td className="px-3 py-3">
                        <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${statusClass(keyword.latestStatus)}`}>{statusLabel(keyword.latestStatus)}</span>
                      </td>
                      <td className="px-3 py-3">{keyword.latestRank ?? "-"}</td>
                      <td className="px-3 py-3">{keyword.latestPage ?? "-"}</td>
                      <td className="px-3 py-3 break-all">
                        {keyword.latestUrl ? (
                          <a className="text-primary underline-offset-4 hover:underline" href={keyword.latestUrl} target="_blank" rel="noreferrer">
                            {keyword.latestUrl}
                          </a>
                        ) : (
                          "-"
                        )}
                      </td>
                      <td className="px-3 py-3 text-destructive">{keyword.latestError ?? "-"}</td>
                      <td className="px-3 py-3 text-muted-foreground">{keyword.latestCheckedAt ? formatDate(keyword.latestCheckedAt) : "-"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <EmptyState title="Chưa có keyword" description="Nhập thủ công hoặc import file để bắt đầu quản lý thứ hạng keyword." />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
