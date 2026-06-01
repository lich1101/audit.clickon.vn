"use client";

import { useEffect, useMemo, useState } from "react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { AUDIT_EXPORT_FIELD_DEFINITIONS, DEFAULT_AUDIT_EXPORT_COLUMN_KEYS, type AuditExportColumnKey } from "@/lib/audit-workbench-data";

export function AuditExportDialog({
  open,
  onOpenChange,
  onConfirm,
  exporting
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: (selectedColumns: AuditExportColumnKey[]) => void;
  exporting?: boolean;
}) {
  const [selectedColumns, setSelectedColumns] = useState<AuditExportColumnKey[]>(DEFAULT_AUDIT_EXPORT_COLUMN_KEYS);

  useEffect(() => {
    if (!open) {
      return;
    }

    setSelectedColumns(DEFAULT_AUDIT_EXPORT_COLUMN_KEYS);
  }, [open]);

  const allKeys = useMemo(
    () => AUDIT_EXPORT_FIELD_DEFINITIONS.map((field) => field.key),
    []
  );
  const allSelected = selectedColumns.length === allKeys.length;

  function toggleColumn(key: AuditExportColumnKey, checked: boolean) {
    setSelectedColumns((current) => {
      if (checked) {
        return current.includes(key) ? current : [...current, key];
      }

      return current.filter((value) => value !== key);
    });
  }

  function handleConfirm() {
    onConfirm(selectedColumns);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Chọn trường xuất Excel</DialogTitle>
          <DialogDescription>
            Chỉ những cột được tick mới xuất ra file. Cấu hình này chỉ áp dụng cho lần xuất hiện tại.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" onClick={() => setSelectedColumns(allKeys)}>
              Chọn tất cả
            </Button>
            <Button type="button" variant="outline" onClick={() => setSelectedColumns(DEFAULT_AUDIT_EXPORT_COLUMN_KEYS)}>
              Theo mặc định
            </Button>
            <Button type="button" variant="outline" onClick={() => setSelectedColumns([])}>
              Bỏ hết
            </Button>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            {AUDIT_EXPORT_FIELD_DEFINITIONS.map((field) => (
              <label key={field.key} className="flex items-start gap-3 rounded-xl border border-border/70 bg-secondary/20 px-3 py-3 text-sm">
                <Checkbox checked={selectedColumns.includes(field.key)} onChange={(event) => toggleColumn(field.key, event.target.checked)} />
                <span className="leading-5">{field.header}</span>
              </label>
            ))}
          </div>

          <div className="flex items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
              {allSelected ? "Đã chọn toàn bộ cột." : `Đã chọn ${selectedColumns.length}/${allKeys.length} cột.`}
            </p>
            <Button type="button" disabled={exporting || selectedColumns.length === 0} onClick={handleConfirm}>
              {exporting ? "Đang xuất..." : "Xuất Excel"}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
