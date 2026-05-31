"use client";

import { Download, FileUp, Play, Save, Square } from "lucide-react";
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
import { Textarea } from "@/components/ui/textarea";
import { useAuth } from "@/hooks/use-auth";
import { downloadKeywordTemplateFile, parseKeywordFile } from "@/lib/audit-files";
import {
  completeKeywordRankRun,
  createCaptchaSolveTask,
  createKeywordRankRun,
  fetchKeywordRankBoard,
  pollCaptchaSolveTask,
  recordKeywordRankRunItem,
  saveKeywordRankKeywords,
} from "@/lib/keyword-ranks";
import { formatDate, formatNumber } from "@/lib/utils";
import type { CaptchaSolveTask, KeywordRankBoard, KeywordRankKeyword, KeywordRankRunItem } from "@/types";

type ExtensionMessage =
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_EXTENSION_READY"; version: number }
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_STATUS"; payload: { message: string; processed?: number; total?: number } }
  | { source: "clickon-rank-extension"; type: "CLICKON_RANK_ITEM_RESULT"; payload: KeywordRankRunItem }
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
    };

function splitKeywords(value: string) {
  return Array.from(
    new Set(
      value
        .split(/\r?\n/g)
        .map((line) => line.trim())
        .filter(Boolean)
    )
  );
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
  const [extensionReady, setExtensionReady] = useState(false);
  const [keywordsInput, setKeywordsInput] = useState("");
  const [selectedKeywordIds, setSelectedKeywordIds] = useState<string[]>([]);
  const [pages, setPages] = useState(10);
  const [delayMin, setDelayMin] = useState(4);
  const [delayMax, setDelayMax] = useState(9);
  const [autoCaptcha, setAutoCaptcha] = useState(false);
  const [statusText, setStatusText] = useState("Chưa chạy");
  const activeRunPublicIdRef = useRef<string | null>(null);
  const inputFileRef = useRef<HTMLInputElement | null>(null);

  async function loadBoard() {
    const nextBoard = await fetchKeywordRankBoard(id);
    setBoard(nextBoard);
    setKeywordsInput(nextBoard.keywords.map((item) => item.keyword).join("\n"));
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
        setExtensionReady(true);
        return;
      }

      if (event.data.type === "CLICKON_RANK_STATUS") {
        const { message, processed, total } = event.data.payload;
        setStatusText(typeof processed === "number" && typeof total === "number" ? `${message} (${processed}/${total})` : message);
        return;
      }

      if (event.data.type === "CLICKON_RANK_ITEM_RESULT") {
        const runPublicId = activeRunPublicIdRef.current;
        if (!runPublicId) return;

        void recordKeywordRankRunItem(runPublicId, event.data.payload)
          .then(() => loadBoard())
          .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể lưu kết quả keyword."));
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

      for (let attempt = 0; attempt < 40 && task.status === "processing"; attempt += 1) {
        await new Promise((resolve) => window.setTimeout(resolve, 5000));
        task = await pollCaptchaSolveTask(task.id);
        setBoard((current) => (current ? { ...current, captchaCredits: task.captchaCredits } : current));
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
    const keywords = splitKeywords(keywordsInput);
    if (!keywords.length) {
      toast.error("Nhập ít nhất một keyword.");
      return [] as KeywordRankKeyword[];
    }

    try {
      setSaving(true);
      const saved = await saveKeywordRankKeywords(id, keywords);
      await loadBoard();
      toast.success(`Đã lưu ${saved.length} keyword.`);
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
      toast.error("Trình duyệt hiện tại chưa phát hiện Clickon Rank Checker extension.");
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
      toast.error("Không còn lượt giải captcha tự động.");
      return;
    }

    try {
      setRunning(true);
      const response = await createKeywordRankRun(id, {
        keywordIds: runKeywords.map((item) => item.id),
        captchaEnabled: autoCaptcha,
      });
      activeRunPublicIdRef.current = response.data.publicId;
      setStatusText("Đã gửi task sang extension.");

      window.postMessage(
        {
          source: "clickon-web",
          type: "CLICKON_RANK_RUN",
          payload: {
            runPublicId: response.data.publicId,
            websiteId: id,
            targetDomain: board.targetDomain,
            pages,
            delayMin,
            delayMax,
            googleHost: "https://www.google.com",
            hl: "vi",
            gl: "vn",
            autoCaptcha,
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
      const keywords = await parseKeywordFile(file);
      setKeywordsInput((current) => splitKeywords(`${current}\n${keywords.join("\n")}`).join("\n"));
      toast.success(`Đã import ${keywords.length} keyword.`);
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

      <Card>
        <CardHeader>
          <CardTitle>Cấu hình check rank</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <div className="grid gap-4 lg:grid-cols-[1fr_280px]">
            <div className="space-y-2">
              <Label htmlFor="keywords">Danh sách keyword</Label>
              <Textarea id="keywords" rows={10} value={keywordsInput} onChange={(event) => setKeywordsInput(event.target.value)} placeholder="Mỗi dòng một keyword" />
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
              </div>
              <label className="flex items-start gap-3 rounded-2xl border border-border bg-background/70 p-4 text-sm">
                <Checkbox checked={autoCaptcha} onChange={(event) => setAutoCaptcha(event.target.checked)} />
                <span>
                  <span className="block font-medium">Tự động giải captcha bằng 2captcha</span>
                  <span className="mt-1 block text-muted-foreground">Chỉ trừ 1 lượt khi 2captcha trả token thành công.</span>
                </span>
              </label>
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-4">
            <div className="space-y-2">
              <Label htmlFor="pages">Số trang Google</Label>
              <Input id="pages" min={1} max={10} type="number" value={pages} onChange={(event) => setPages(Math.max(1, Math.min(10, Number(event.target.value || 10))))} />
            </div>
            <div className="space-y-2">
              <Label htmlFor="delay-min">Delay min</Label>
              <Input id="delay-min" min={1} type="number" value={delayMin} onChange={(event) => setDelayMin(Math.max(1, Number(event.target.value || 1)))} />
            </div>
            <div className="space-y-2">
              <Label htmlFor="delay-max">Delay max</Label>
              <Input id="delay-max" min={1} type="number" value={delayMax} onChange={(event) => setDelayMax(Math.max(delayMin, Number(event.target.value || delayMin)))} />
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
            <input ref={inputFileRef} className="hidden" type="file" accept=".xlsx,.xls,.csv,.txt,text/plain" onChange={(event) => void handleImport(event.target.files?.[0])} />
          </div>
          <p className="text-sm text-muted-foreground">{statusText}</p>
        </CardContent>
      </Card>

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
