"use client";

import { cn } from "@/lib/utils";

const widthClasses = [
  "w-0",
  "w-1/12",
  "w-2/12",
  "w-3/12",
  "w-4/12",
  "w-5/12",
  "w-6/12",
  "w-7/12",
  "w-8/12",
  "w-9/12",
  "w-10/12",
  "w-11/12",
  "w-full"
] as const;

function getWidthClass(value: number) {
  const safeValue = Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
  return widthClasses[Math.round((safeValue / 100) * 12)];
}

export function ProgressBar({
  value,
  className
}: {
  value: number;
  className?: string;
}) {
  return (
    <div className={cn("h-2 overflow-hidden rounded-full bg-secondary", className)} aria-valuemax={100} aria-valuemin={0} aria-valuenow={value} role="progressbar">
      <div className={cn("h-full rounded-full bg-primary transition-all duration-500 ease-out", getWidthClass(value))} />
    </div>
  );
}
