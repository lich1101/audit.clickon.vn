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
  ShieldCheck,
  SlidersHorizontal,
  Users2
} from "lucide-react";

import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
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
      <div className="flex h-16 shrink-0 items-center justify-between border-b border-border/60 px-5">
        <div>
          <p className="text-[11px] font-medium uppercase tracking-[0.28em] text-muted-foreground">Clickon</p>
          <h1 className="text-xl font-semibold tracking-tight">{isAdminMode ? "Admin" : "Audit"}</h1>
        </div>
        <Badge variant="outline" className="bg-card text-sidebar-foreground">
          {isAdminMode ? "admin" : profile?.role ?? "user"}
        </Badge>
      </div>

      {!isAdminMode ? (
        <div className="shrink-0 px-4 py-4">
          <Button asChild className="h-11 w-full rounded-xl shadow-sm" size="lg">
            <Link href="/websites/create">
              <Plus className="size-4" />
              Tạo audit website
            </Link>
          </Button>
        </div>
      ) : (
        <div className="shrink-0 px-4 py-4">
          <div className="rounded-2xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-muted-foreground">
            <div className="flex items-center gap-2 font-medium text-foreground">
              <ShieldCheck className="size-4 text-primary" />
              Bảng điều khiển quản trị
            </div>
            <p className="mt-2 text-xs leading-5">Cấu hình AI, song song xử lý và vận hành hệ thống.</p>
          </div>
        </div>
      )}

      <ScrollArea className="min-h-0 flex-1 px-3 pb-5">
        <div className="flex flex-col gap-4">
          {!isAdminMode ? (
            <div className="rounded-2xl bg-card px-4 py-3 shadow-sm">
              <p className="text-[11px] font-medium uppercase tracking-[0.16em] text-muted-foreground">Credit</p>
              <div className="mt-3 flex items-center gap-3">
                <div className="flex size-10 items-center justify-center rounded-full bg-secondary text-primary">
                  <ShieldCheck className="size-4" />
                </div>
                <div>
                  <p className="text-xl font-semibold">{profile?.credits ?? 0}</p>
                  <p className="text-xs text-muted-foreground">Số dư hiện tại</p>
                </div>
              </div>
            </div>
          ) : null}

          <nav className="flex flex-col gap-0.5">
            {items.map((item) => {
              const Icon = item.icon;
              const active = pathname === item.href || pathname.startsWith(`${item.href}/`);

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    "flex h-10 items-center gap-3 rounded-xl px-4 text-sm transition",
                    active ? "bg-primary/10 font-medium text-primary" : "text-sidebar-foreground/75 hover:bg-secondary hover:text-sidebar-foreground"
                  )}
                >
                  <Icon className="size-4" />
                  {item.label}
                </Link>
              );
            })}
          </nav>
        </div>
      </ScrollArea>
    </aside>
  );
}
