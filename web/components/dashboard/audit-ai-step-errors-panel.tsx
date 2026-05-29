"use client";

import type { AuditAiStepError } from "@/types";

function statusTone(status?: string | null) {
  if (status === "needs_json_formatter") {
    return "border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-200";
  }

  if (status === "recovery_redispatch" || status === "watchdog_stale_detected") {
    return "border-sky-500/30 bg-sky-500/10 text-sky-900 dark:text-sky-100";
  }

  return "border-destructive/30 bg-destructive/10 text-destructive";
}

export function AuditAiStepErrorsPanel({ errors }: { errors: AuditAiStepError[] }) {
  if (!errors.length) {
    return null;
  }

  return (
    <div className="space-y-3 rounded-[20px] border border-border/70 bg-card/60 px-4 py-4">
      <div>
        <p className="text-sm font-medium">Lỗi / trạng thái gọi AI theo batch</p>
        <p className="mt-1 text-xs text-muted-foreground">
          Chi tiết từ bước 2, 2.5 (formatter), 3 và 3.5 (formatter). Mỗi dòng tương ứng một lần gọi AI theo nhóm URL.
        </p>
      </div>

      <div className="space-y-2">
        {errors.map((entry) => {
          const detail = entry.errorMessage || entry.parseError;
          const range =
            entry.positionFrom != null && entry.positionTo != null
              ? `URL #${entry.positionFrom}–#${entry.positionTo}`
              : null;

          return (
            <div key={entry.stepKey} className={`rounded-2xl border px-3 py-3 text-sm ${statusTone(entry.status)}`}>
              <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">{entry.stepLabel}</p>
                {entry.status ? (
                  <span className="rounded-full bg-background/70 px-2 py-0.5 text-xs uppercase tracking-wide">{entry.status}</span>
                ) : null}
                {range ? <span className="text-xs opacity-80">{range}</span> : null}
              </div>

              {detail ? <p className="mt-2 whitespace-pre-wrap break-words">{detail}</p> : null}

              {entry.provider || entry.model ? (
                <p className="mt-2 text-xs opacity-80">
                  {[entry.provider, entry.model].filter(Boolean).join(" · ")}
                </p>
              ) : null}
            </div>
          );
        })}
      </div>
    </div>
  );
}
