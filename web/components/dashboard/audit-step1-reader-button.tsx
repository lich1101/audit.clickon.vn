"use client";

import { ExternalLink, LoaderCircle } from "lucide-react";
import { useCallback, useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { fetchAuditStep1Content, type AuditStep1Content } from "@/lib/audit-runs";

function hasStep1PreviewData(input: {
  pageTitle?: string | null;
  metaDescription?: string | null;
  contentExcerpt?: string | null;
  contentSource?: string | null;
  contentError?: string | null;
}) {
  return Boolean(
    input.pageTitle?.trim() ||
      input.metaDescription?.trim() ||
      input.contentExcerpt?.trim() ||
      input.contentSource?.trim() ||
      input.contentError?.trim()
  );
}

function formatHeadings(headings?: AuditStep1Content["headings"]) {
  if (!headings) {
    return null;
  }

  const sections = [
    { label: "H1", values: headings.h1 ?? [] },
    { label: "H2", values: headings.h2 ?? [] },
    { label: "H3", values: headings.h3 ?? [] }
  ].filter((section) => section.values.length > 0);

  if (sections.length === 0) {
    return null;
  }

  return sections;
}

function formatMetrics(metrics?: AuditStep1Content["metrics"]) {
  if (!metrics || Object.keys(metrics).length === 0) {
    return null;
  }

  return Object.entries(metrics).filter(([, value]) => value !== null && value !== undefined && value !== "");
}

export function AuditStep1ReaderButton({
  websiteId,
  targetUrl,
  itemPublicId,
  preview
}: {
  websiteId: string;
  targetUrl: string;
  itemPublicId?: string | null;
  preview?: {
    pageTitle?: string | null;
    metaDescription?: string | null;
    contentExcerpt?: string | null;
    contentSource?: string | null;
    contentError?: string | null;
  };
}) {
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [content, setContent] = useState<AuditStep1Content | null>(null);

  const canOpen = hasStep1PreviewData(preview ?? {});

  const loadContent = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const data = await fetchAuditStep1Content({
        websiteId,
        targetUrl,
        itemPublicId: itemPublicId ?? undefined
      });
      setContent(data);
    } catch (loadError) {
      setContent(null);
      setError(loadError instanceof Error ? loadError.message : "Không tải được nội dung bước 1.");
    } finally {
      setLoading(false);
    }
  }, [itemPublicId, targetUrl, websiteId]);

  const handleOpenChange = (nextOpen: boolean) => {
    setOpen(nextOpen);

    if (nextOpen) {
      void loadContent();
    } else {
      setError(null);
    }
  };

  if (!canOpen) {
    return null;
  }

  const headingSections = formatHeadings(content?.headings);
  const metricEntries = formatMetrics(content?.metrics);

  return (
    <>
      <button
        className="underline underline-offset-2 hover:text-foreground"
        onClick={() => handleOpenChange(true)}
        type="button"
      >
        Reader
      </button>

      <Dialog onOpenChange={handleOpenChange} open={open}>
        <DialogContent className="max-h-[min(88vh,960px)] max-w-4xl overflow-hidden p-0">
          <div className="flex max-h-[min(88vh,960px)] flex-col">
            <DialogHeader className="space-y-3 border-b border-border px-6 py-5 pr-14">
              <DialogTitle>Nội dung bước 1 (Firecrawl)</DialogTitle>
              <DialogDescription className="break-all">{targetUrl}</DialogDescription>
              {content?.contentSource ? (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                  <span className="rounded-full bg-secondary/70 px-2.5 py-1 text-secondary-foreground">Nguồn: {content.contentSource}</span>
                  {content.updatedAt ? <span className="text-muted-foreground">Cập nhật: {new Date(content.updatedAt).toLocaleString("vi-VN")}</span> : null}
                </div>
              ) : null}
            </DialogHeader>

            <div className="flex-1 overflow-y-auto px-6 py-5">
              {loading ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <LoaderCircle className="size-4 animate-spin" />
                  Đang tải nội dung từ database...
                </div>
              ) : error ? (
                <p className="text-sm text-red-600 dark:text-red-300">{error}</p>
              ) : content ? (
                <div className="space-y-5">
                  <section className="space-y-2">
                    <h3 className="text-sm font-semibold">Tiêu đề</h3>
                    <p className="text-sm">{content.pageTitle?.trim() || "—"}</p>
                  </section>

                  <section className="space-y-2">
                    <h3 className="text-sm font-semibold">Meta description</h3>
                    <p className="whitespace-pre-wrap text-sm text-muted-foreground">{content.metaDescription?.trim() || "—"}</p>
                  </section>

                  {content.canonicalUrl ? (
                    <section className="space-y-2">
                      <h3 className="text-sm font-semibold">Canonical</h3>
                      <p className="break-all text-sm text-muted-foreground">{content.canonicalUrl}</p>
                    </section>
                  ) : null}

                  {headingSections ? (
                    <section className="space-y-3">
                      <h3 className="text-sm font-semibold">Heading</h3>
                      <div className="grid gap-3 md:grid-cols-3">
                        {headingSections.map((section) => (
                          <div className="rounded-2xl border border-border bg-secondary/20 p-3" key={section.label}>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{section.label}</p>
                            <ul className="space-y-1 text-sm">
                              {section.values.map((value) => (
                                <li className="break-words" key={`${section.label}-${value}`}>
                                  {value}
                                </li>
                              ))}
                            </ul>
                          </div>
                        ))}
                      </div>
                    </section>
                  ) : null}

                  {metricEntries ? (
                    <section className="space-y-2">
                      <h3 className="text-sm font-semibold">Chỉ số trang</h3>
                      <div className="flex flex-wrap gap-2">
                        {metricEntries.map(([key, value]) => (
                          <span className="rounded-full bg-secondary/60 px-2.5 py-1 text-xs" key={key}>
                            {key}: {String(value)}
                          </span>
                        ))}
                      </div>
                    </section>
                  ) : null}

                  {content.contentError ? (
                    <section className="space-y-2">
                      <h3 className="text-sm font-semibold text-amber-700 dark:text-amber-300">Lỗi crawl</h3>
                      <p className="whitespace-pre-wrap text-sm text-amber-700 dark:text-amber-300">{content.contentError}</p>
                    </section>
                  ) : null}

                  <section className="space-y-2">
                    <h3 className="text-sm font-semibold">Nội dung đã lưu</h3>
                    {content.contentExcerpt?.trim() ? (
                      <pre className="max-h-[420px] overflow-auto whitespace-pre-wrap break-words rounded-2xl border border-border bg-secondary/20 p-4 text-sm leading-6">
                        {content.contentExcerpt}
                      </pre>
                    ) : (
                      <p className="text-sm text-muted-foreground">Chưa có nội dung text.</p>
                    )}
                  </section>
                </div>
              ) : null}
            </div>

            <div className="flex items-center justify-between gap-3 border-t border-border px-6 py-4">
              <Button asChild size="sm" variant="outline">
                <a href={targetUrl} rel="noreferrer" target="_blank">
                  <ExternalLink className="size-4" />
                  Mở bài gốc
                </a>
              </Button>
              <Button onClick={() => handleOpenChange(false)} size="sm" type="button" variant="secondary">
                Đóng
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
