"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  CreditCard,
  FolderSearch2,
  Gauge,
  Globe,
  History,
  LayoutDashboard,
  MessageSquareText,
  Plus,
  ReceiptText,
  Settings,
  SlidersHorizontal,
  Users2
} from "lucide-react";

import { cn, formatUsd } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import { useAuth } from "@/hooks/use-auth";
import { useDashboardMode } from "@/hooks/use-dashboard-mode";

const userItems = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
  { href: "/websites", label: "Websites", icon: Globe },
  { href: "/billing", label: "Billing", icon: CreditCard },
  { href: "/credit-history", label: "Credit History", icon: History },
  { href: "/settings", label: "Settings", icon: Settings }
];

const adminItems = [
  { href: "/admin", label: "Admin Dashboard", icon: Gauge },
  { href: "/admin/users", label: "Users", icon: Users2 },
  { href: "/admin/plans", label: "Plans", icon: ReceiptText },
  { href: "/admin/audit-prompts", label: "Audit Prompts", icon: MessageSquareText },
  { href: "/admin/settings", label: "Audit Settings", icon: SlidersHorizontal },
  { href: "/admin/credit-logs", label: "Credit Logs", icon: FolderSearch2 }
];

export function AppSidebar({ mobile = false }: { mobile?: boolean }) {
  const pathname = usePathname();
  const { profile } = useAuth();
  const { mode } = useDashboardMode();
  const isAdminMode = profile?.role === "admin" && mode === "admin";
  const items = isAdminMode ? adminItems : userItems;

  return (
    <aside className={cn("flex h-full w-full flex-col bg-sidebar text-sidebar-foreground", mobile ? "h-screen" : "h-full")}>
      <div className="flex h-16 shrink-0 items-center border-b border-border/60 px-5">
        <div className="min-w-0">
          <p className="text-[11px] font-medium uppercase tracking-[0.24em] text-muted-foreground">Clickon</p>
          <h1 className="truncate text-lg font-semibold tracking-tight">{isAdminMode ? "Admin" : "Audit"}</h1>
        </div>
      </div>

      <div className="shrink-0 px-4 py-4">
        {!isAdminMode ? (
          <Button asChild className="h-11 w-full rounded-xl shadow-sm" size="lg">
            <Link href="/websites/create">
              <Plus className="size-4" />
              Tạo audit website
            </Link>
          </Button>
        ) : (
          <div className="rounded-xl border border-border/70 bg-card/60 px-3 py-2 text-xs text-muted-foreground">
            Chế độ quản trị
          </div>
        )}
      </div>

      <ScrollArea className="min-h-0 flex-1 px-3 pb-4">
        <div className="flex h-full flex-col gap-3">
          <nav className="flex flex-col gap-1">
            {items.map((item) => {
              const Icon = item.icon;
              const active = pathname === item.href || pathname.startsWith(`${item.href}/`);

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    "flex h-10 items-center gap-3 rounded-xl px-3 text-sm transition",
                    active ? "bg-primary/10 font-medium text-primary" : "text-sidebar-foreground/75 hover:bg-secondary hover:text-sidebar-foreground"
                  )}
                >
                  <Icon className="size-4" />
                  {item.label}
                </Link>
              );
            })}
          </nav>

          <div className="mt-auto rounded-xl border border-border/70 bg-card/60 px-3 py-2 text-xs text-muted-foreground">
            {isAdminMode ? "Admin mode" : formatUsd(profile?.balanceUsd ?? 0, 4)}
          </div>
        </div>
      </ScrollArea>
    </aside>
  );
}
