"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/utils";

export function WebsiteSectionTabs({ websiteId }: { websiteId: string }) {
  const pathname = usePathname();
  const items = [
    { href: `/websites/${websiteId}`, label: "Tổng quan" },
    { href: `/websites/${websiteId}/audit`, label: "Audit SEO" },
    { href: `/websites/${websiteId}/keyword-ranks`, label: "Thứ hạng keyword" },
  ];

  return (
    <div className="flex flex-wrap gap-2 rounded-2xl border border-border/70 bg-card/70 p-2">
      {items.map((item) => {
        const active = pathname === item.href;

        return (
          <Link
            key={item.href}
            className={cn(
              "rounded-xl px-3 py-2 text-sm font-medium text-muted-foreground transition hover:bg-secondary hover:text-foreground",
              active && "bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground"
            )}
            href={item.href}
          >
            {item.label}
          </Link>
        );
      })}
    </div>
  );
}
