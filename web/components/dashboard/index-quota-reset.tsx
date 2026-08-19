"use client";

import { useEffect, useMemo, useState } from "react";

function nextPacificMidnightIso() {
  const now = new Date();
  const pacificNow = new Date(now.toLocaleString("en-US", { timeZone: "America/Los_Angeles" }));
  const nextMidnight = new Date(pacificNow);
  nextMidnight.setHours(24, 0, 0, 0);
  return new Date(now.getTime() + (nextMidnight.getTime() - pacificNow.getTime())).toISOString();
}

function pad(value: number) {
  return String(value).padStart(2, "0");
}

function formatClock(iso: string, timeZone: string) {
  return new Intl.DateTimeFormat("vi-VN", {
    timeZone,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false
  }).format(new Date(iso));
}

export function IndexQuotaReset({ resetsAt }: { resetsAt?: string | null }) {
  const targetIso = resetsAt || nextPacificMidnightIso();
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  const remainingMs = Math.max(0, new Date(targetIso).getTime() - now);
  const hours = Math.floor(remainingMs / 3_600_000);
  const minutes = Math.floor((remainingMs % 3_600_000) / 60_000);
  const seconds = Math.floor((remainingMs % 60_000) / 1000);
  const countdown = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;

  const labels = useMemo(
    () => ({
      vn: formatClock(targetIso, "Asia/Ho_Chi_Minh"),
      us: formatClock(targetIso, "America/Los_Angeles")
    }),
    [targetIso]
  );

  return (
    <div className="min-w-0 text-right text-[11px] leading-tight text-muted-foreground">
      <p className="font-medium tabular-nums text-foreground">{remainingMs === 0 ? "Đã reset" : `Còn ${countdown}`}</p>
      <p>VN {labels.vn} (GMT+7)</p>
      <p>Mỹ {labels.us} (PT)</p>
    </div>
  );
}
