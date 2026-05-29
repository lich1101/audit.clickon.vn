"use client";

import { FileText, Loader2, Trash2, Upload } from "lucide-react";
import { useRef, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { deleteAdminGeminiPdf, uploadAdminGeminiPdf, type GeminiPdfAttachment } from "@/lib/audit-settings";
import type { AiProvider, JsonFormatterProvider } from "@/types";

type GeminiPdfUploadProps = {
  slot: string;
  label: string;
  provider: AiProvider | JsonFormatterProvider;
  attachment?: GeminiPdfAttachment | null;
  onChange: (attachment: GeminiPdfAttachment | null) => void;
};

function isGeminiProvider(provider: AiProvider | JsonFormatterProvider): boolean {
  return provider === "gemini" || provider === "gemini_deep_research";
}

export function GeminiPdfUpload({ slot, label, provider, attachment, onChange }: GeminiPdfUploadProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);
  const [deleting, setDeleting] = useState(false);

  if (!isGeminiProvider(provider)) {
    return null;
  }

  async function handleUpload(file: File) {
    try {
      setUploading(true);
      const uploaded = await uploadAdminGeminiPdf(slot, file);
      onChange(uploaded);
      toast.success("Đã upload PDF.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể upload PDF.");
    } finally {
      setUploading(false);
      if (inputRef.current) {
        inputRef.current.value = "";
      }
    }
  }

  async function handleDelete() {
    try {
      setDeleting(true);
      await deleteAdminGeminiPdf(slot);
      onChange(null);
      toast.success("Đã xóa PDF.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể xóa PDF.");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <Label>{label}</Label>
      <div className="flex flex-wrap items-center gap-2">
        <input
          ref={inputRef}
          type="file"
          accept="application/pdf,.pdf"
          className="hidden"
          onChange={(event) => {
            const file = event.target.files?.[0];

            if (file) {
              void handleUpload(file);
            }
          }}
        />
        <Button type="button" variant="outline" size="sm" disabled={uploading || deleting} onClick={() => inputRef.current?.click()}>
          {uploading ? <Loader2 className="size-4 animate-spin" /> : <Upload className="size-4" />}
          PDF
        </Button>
        {attachment ? (
          <>
            <span className="inline-flex max-w-[220px] items-center gap-1 truncate text-sm">
              <FileText className="size-4 shrink-0" />
              {attachment.originalName}
            </span>
            <Button type="button" variant="ghost" size="sm" disabled={uploading || deleting} onClick={() => void handleDelete()}>
              {deleting ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
            </Button>
          </>
        ) : null}
      </div>
    </div>
  );
}
