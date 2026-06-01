"use client";

import { Trash2 } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { deleteWebsite } from "@/lib/websites";
import type { Website } from "@/types";

type DeleteWebsiteDialogProps = {
  website: Pick<Website, "id" | "name" | "url">;
  triggerVariant?: "default" | "destructive" | "outline" | "secondary" | "ghost";
  triggerSize?: "default" | "sm" | "icon";
  triggerLabel?: string;
  onDeleted?: () => void;
};

export function DeleteWebsiteDialog({
  website,
  triggerVariant = "outline",
  triggerSize = "sm",
  triggerLabel = "Xóa website",
  onDeleted
}: DeleteWebsiteDialogProps) {
  const [open, setOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  async function handleConfirm() {
    try {
      setDeleting(true);
      const result = await deleteWebsite(website.id);
      toast.success(result.message ?? "Đã xóa website.");
      setOpen(false);
      onDeleted?.();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể xóa website.");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <AlertDialogTrigger asChild>
        <Button
          type="button"
          variant={triggerVariant}
          size={triggerSize}
          className={triggerVariant === "destructive" ? "gap-2" : "gap-2 text-destructive hover:text-destructive"}
        >
          <Trash2 className="size-4 shrink-0" />
          {triggerSize === "icon" ? <span className="sr-only">{triggerLabel}</span> : triggerLabel}
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Xóa website &quot;{website.name}&quot;?</AlertDialogTitle>
          <AlertDialogDescription asChild>
            <div className="space-y-2 text-sm text-muted-foreground">
              <p>
                Thao tác này <strong className="text-foreground">không thể hoàn tác</strong>. Hệ thống sẽ xóa vĩnh viễn:
              </p>
              <ul className="list-disc space-y-1 pl-5">
                <li>Website và cấu hình audit (URL, danh mục, checklist)</li>
                <li>Toàn bộ lịch sử audit run và kết quả từng URL</li>
                <li>Keyword rank và lịch sử kiểm tra thứ hạng</li>
              </ul>
              <p className="break-all text-xs">
                URL: <span className="text-foreground">{website.url}</span>
              </p>
              <p>Nếu đang có audit hoặc kiểm tra keyword chạy, hãy chờ xong hoặc dừng trước khi xóa.</p>
            </div>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={deleting}>Hủy</AlertDialogCancel>
          <AlertDialogAction
            disabled={deleting}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            onClick={(event) => {
              event.preventDefault();
              void handleConfirm();
            }}
          >
            {deleting ? "Đang xóa..." : "Xóa vĩnh viễn"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
