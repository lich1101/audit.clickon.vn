import { Badge } from "@/components/ui/badge";
import type { AuditRunItemStatus, AuditRunStatus } from "@/types";

const labelMap: Record<AuditRunStatus | AuditRunItemStatus, string> = {
  queued: "Chờ xử lý",
  processing: "Đang chạy",
  fetching: "Đang lấy nội dung",
  analyzing: "Đang phân tích",
  completed: "Hoàn tất",
  partial: "Hoàn tất một phần",
  failed: "Thất bại"
};

const variantMap: Record<AuditRunStatus | AuditRunItemStatus, "default" | "warning" | "success" | "destructive"> = {
  queued: "default",
  processing: "warning",
  fetching: "warning",
  analyzing: "warning",
  completed: "success",
  partial: "warning",
  failed: "destructive"
};

export function AuditStatusBadge({ status }: { status: AuditRunStatus | AuditRunItemStatus }) {
  return <Badge variant={variantMap[status]}>{labelMap[status]}</Badge>;
}

