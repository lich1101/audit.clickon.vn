"use client";

import { FileUp, Plus, Trash2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { parseUrlFile } from "@/lib/audit-files";
import { cn } from "@/lib/utils";

function uniqueUrls(urls: string[]) {
  const seen = new Set<string>();
  return urls
    .map((url) => url.trim())
    .filter((url) => {
      if (!url || seen.has(url)) {
        return false;
      }

      seen.add(url);
      return true;
    });
}

export function AuditTargetUrlEditor({
  urls,
  onChange,
  selectedUrls,
  onSelectedChange,
  className,
  emptyHint = "Chưa có URL mục tiêu. Thêm URL hoặc nạp file."
}: {
  urls: string[];
  onChange: (urls: string[]) => void;
  selectedUrls: string[];
  onSelectedChange: (selected: string[]) => void;
  className?: string;
  emptyHint?: string;
}) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [newUrl, setNewUrl] = useState("");
  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkInput, setBulkInput] = useState("");

  const selectedSet = useMemo(() => new Set(selectedUrls), [selectedUrls]);

  useEffect(() => {
    const valid = selectedUrls.filter((url) => urls.includes(url));

    if (valid.length !== selectedUrls.length) {
      onSelectedChange(valid);
    }
  }, [urls, selectedUrls, onSelectedChange]);

  useEffect(() => {
    if (urls.length > 0 && selectedUrls.length === 0) {
      onSelectedChange([...urls]);
    }
  }, [urls, selectedUrls.length, onSelectedChange]);

  const allSelected = urls.length > 0 && selectedUrls.length === urls.length;

  function toggleUrl(url: string, checked: boolean) {
    if (checked) {
      onSelectedChange(uniqueUrls([...selectedUrls, url]));
      return;
    }

    onSelectedChange(selectedUrls.filter((item) => item !== url));
  }

  function toggleAll(checked: boolean) {
    onSelectedChange(checked ? [...urls] : []);
  }

  function removeUrls(toRemove: string[]) {
    const removeSet = new Set(toRemove);
    onChange(urls.filter((url) => !removeSet.has(url)));
    onSelectedChange(selectedUrls.filter((url) => !removeSet.has(url)));
  }

  function addUrl(raw: string) {
    const value = raw.trim();

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
      toast.error("URL đã có trong danh sách.");
      return;
    }

    onChange([...urls, value]);
    onSelectedChange(uniqueUrls([...selectedUrls, value]));
    setNewUrl("");
  }

  function applyBulkPaste() {
    const next = uniqueUrls([
      ...urls,
      ...bulkInput
        .split(/\r\n|\r|\n/g)
        .map((line) => line.trim())
        .filter(Boolean)
    ]);

    onChange(next);
    onSelectedChange(next);
    setBulkInput("");
    setBulkOpen(false);
    toast.success(`Đã thêm URL. Tổng ${next.length} dòng.`);
  }

  return (
    <div className={cn("flex flex-col gap-3", className)}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Checkbox
            id="select-all-urls"
            checked={allSelected}
            onChange={(event) => toggleAll(event.target.checked)}
            disabled={urls.length === 0}
          />
          <Label htmlFor="select-all-urls" className="text-sm font-normal">
            Chọn tất cả ({selectedUrls.length}/{urls.length})
          </Label>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" size="sm" variant="outline" onClick={() => fileInputRef.current?.click()}>
            <FileUp className="size-4" />
            Nạp file
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => setBulkOpen((current) => !current)}>
            Dán hàng loạt
          </Button>
          <Button
            type="button"
            size="sm"
            variant="destructive"
            disabled={selectedUrls.length === 0}
            onClick={() => removeUrls(selectedUrls)}
          >
            <Trash2 className="size-4" />
            Xóa đã chọn
          </Button>
        </div>
      </div>

      <input
        ref={fileInputRef}
        accept=".txt,.csv,.xlsx,.xls"
        className="hidden"
        type="file"
        onChange={async (event) => {
          const file = event.target.files?.[0];
          event.currentTarget.value = "";

          if (!file) {
            return;
          }

          try {
            const imported = await parseUrlFile(file);
            const next = uniqueUrls([...urls, ...imported]);
            onChange(next);
            onSelectedChange(next);
            toast.success(`Đã nạp ${imported.length} URL từ file.`);
          } catch (error) {
            toast.error(error instanceof Error ? error.message : "Không thể đọc file URL.");
          }
        }}
      />

      {bulkOpen ? (
        <div className="rounded-xl border border-border/70 bg-secondary/25 p-3">
          <Textarea
            rows={5}
            placeholder={"https://example.com/bai-viet-1\nhttps://example.com/bai-viet-2"}
            value={bulkInput}
            onChange={(event) => setBulkInput(event.target.value)}
          />
          <div className="mt-2 flex justify-end gap-2">
            <Button type="button" size="sm" variant="outline" onClick={() => setBulkOpen(false)}>
              Huỷ
            </Button>
            <Button type="button" size="sm" onClick={applyBulkPaste}>
              Thêm vào danh sách
            </Button>
          </div>
        </div>
      ) : null}

      <div className="flex gap-2">
        <Input
          placeholder="https://example.com/bai-viet-moi"
          value={newUrl}
          onChange={(event) => setNewUrl(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter") {
              event.preventDefault();
              addUrl(newUrl);
            }
          }}
        />
        <Button type="button" variant="secondary" onClick={() => addUrl(newUrl)}>
          <Plus className="size-4" />
          Thêm
        </Button>
      </div>

      <div className="max-h-[320px] overflow-auto rounded-xl border border-border/70">
        {urls.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-muted-foreground">{emptyHint}</p>
        ) : (
          <ul className="divide-y divide-border/70">
            {urls.map((url, index) => (
              <li key={url} className="flex items-start gap-3 px-3 py-2.5 hover:bg-secondary/30">
                <Checkbox
                  checked={selectedSet.has(url)}
                  onChange={(event) => toggleUrl(url, event.target.checked)}
                  className="mt-0.5"
                />
                <div className="min-w-0 flex-1">
                  <p className="text-xs text-muted-foreground">#{index + 1}</p>
                  <p className="break-all text-sm">{url}</p>
                </div>
                <Button type="button" size="icon" variant="ghost" className="size-8 shrink-0" onClick={() => removeUrls([url])}>
                  <Trash2 className="size-4" />
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

export function urlsToInput(urls: string[]) {
  return urls.join("\n");
}

export function inputToUrls(input: string) {
  return uniqueUrls(input.split(/\r\n|\r|\n/g));
}
