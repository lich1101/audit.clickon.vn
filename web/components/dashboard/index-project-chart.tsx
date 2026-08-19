import { formatNumber } from "@/lib/utils";
import type { IndexProperty, IndexUrlView } from "@/types";

export function IndexProjectChart({
  properties,
  onOpenView
}: {
  properties: IndexProperty[];
  onOpenView?: (view: IndexUrlView) => void;
}) {
  const totalIndexed = properties.reduce((sum, row) => sum + row.sentCount, 0);
  const totalPending = properties.reduce((sum, row) => sum + row.pendingCount + row.sendingCount, 0);
  const totalFailed = properties.reduce((sum, row) => sum + row.failedCount, 0);
  const total = Math.max(1, totalIndexed + totalPending + totalFailed);

  if (!properties.length) {
    return <p className="text-sm text-muted-foreground">Chưa có dữ liệu dự án để vẽ biểu đồ.</p>;
  }

  return (
    <div className="space-y-5">
      <div className="space-y-2">
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">Tổng từ khi tạo dự án</span>
          <span className="font-medium">{formatNumber(totalIndexed + totalPending + totalFailed)} URL</span>
        </div>
        <div className="flex h-4 overflow-hidden rounded-full bg-secondary">
          <div className="bg-emerald-500" style={{ width: `${(totalIndexed / total) * 100}%` }} />
          <div className="bg-amber-400" style={{ width: `${(totalPending / total) * 100}%` }} />
          <div className="bg-rose-500" style={{ width: `${(totalFailed / total) * 100}%` }} />
        </div>
        <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
          <button type="button" className="flex items-center gap-1.5 hover:text-foreground" onClick={() => onOpenView?.("indexed")}>
            <span className="size-2 rounded-full bg-emerald-500" />
            Đã lập chỉ mục {formatNumber(totalIndexed)}
          </button>
          <button type="button" className="flex items-center gap-1.5 hover:text-foreground" onClick={() => onOpenView?.("pending")}>
            <span className="size-2 rounded-full bg-amber-400" />
            Chưa lập chỉ mục {formatNumber(totalPending)}
          </button>
          <button type="button" className="flex items-center gap-1.5 hover:text-foreground" onClick={() => onOpenView?.("failed")}>
            <span className="size-2 rounded-full bg-rose-500" />
            Lỗi {formatNumber(totalFailed)}
          </button>
        </div>
      </div>

      <div className="max-h-64 space-y-3 overflow-y-auto pr-1">
        {properties.map((property) => {
          const indexed = property.sentCount;
          const pending = property.pendingCount + property.sendingCount;
          const failed = property.failedCount;
          const rowTotal = Math.max(1, indexed + pending + failed);

          return (
            <div key={property.id} className="space-y-1.5">
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className={`truncate font-medium ${property.isOwned ? "" : "text-red-600 dark:text-red-400"}`}>
                  {property.name}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                  {formatNumber(indexed)}/{formatNumber(indexed + pending + failed)} đã lập chỉ mục
                </span>
              </div>
              <div className="flex h-2.5 overflow-hidden rounded-full bg-secondary">
                <div className="bg-emerald-500" style={{ width: `${(indexed / rowTotal) * 100}%` }} />
                <div className="bg-amber-400" style={{ width: `${(pending / rowTotal) * 100}%` }} />
                <div className="bg-rose-500" style={{ width: `${(failed / rowTotal) * 100}%` }} />
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
