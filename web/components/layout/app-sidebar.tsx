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
  ReceiptText,
  Settings,
  ShieldCheck,
  Sparkles,
  Users2
} from "lucide-react";

import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { ScrollArea } from "@/components/ui/scroll-area";
import { useAuth } from "@/hooks/use-auth";

const userItems = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
  { href: "/websites", label: "Websites", icon: Globe },
  { href: "/billing", label: "Billing", icon: CreditCard },
  { href: "/credit-history", label: "Credit History", icon: History },
  { href: "/settings", label: "Settings", icon: Settings }
];

const adminItems = [
  { href: "/admin", label: "Admin Home", icon: Gauge },
  { href: "/admin/users", label: "Users", icon: Users2 },
  { href: "/admin/plans", label: "Plans", icon: ReceiptText },
  { href: "/admin/audit-prompts", label: "Audit Prompts", icon: MessageSquareText },
  { href: "/admin/credit-logs", label: "Credit Logs", icon: FolderSearch2 }
];

export function AppSidebar({ mobile = false }: { mobile?: boolean }) {
  const pathname = usePathname();
  const { profile } = useAuth();
  const items = profile?.role === "admin" ? [...userItems, ...adminItems] : userItems;

  return (
    <aside className={cn("flex h-full w-full flex-col bg-sidebar text-sidebar-foreground", !mobile && "w-[264px]")}>
      <div className="flex h-16 items-center justify-between px-5">
        <div>
          <p className="text-[11px] font-medium uppercase tracking-[0.28em] text-muted-foreground">Clickon</p>
          <h1 className="text-xl font-semibold tracking-tight">Audit</h1>
        </div>
        <Badge variant="outline" className="bg-card text-sidebar-foreground">
          {profile?.role ?? "user"}
        </Badge>
      </div>

      <div className="px-3 pb-3">
        <Link
          href="/websites/create"
          className="flex h-12 items-center gap-3 rounded-2xl bg-primary px-5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90"
        >
          <Sparkles className="size-4" />
          Tạo website
        </Link>
      </div>

      <ScrollArea className="flex-1 px-3 pb-5">
        <div className="flex flex-col gap-4">
          <div className="rounded-2xl bg-card px-4 py-3 shadow-sm">
            <p className="text-[11px] font-medium uppercase tracking-[0.16em] text-muted-foreground">Realtime credits</p>
            <div className="mt-3 flex items-center gap-3">
              <div className="flex size-10 items-center justify-center rounded-full bg-secondary text-primary">
                <ShieldCheck className="size-4" />
              </div>
              <div>
                <p className="text-xl font-semibold">{profile?.credits ?? 0}</p>
                <p className="text-xs text-muted-foreground">Đồng bộ qua Firebase</p>
              </div>
            </div>
          </div>

          <nav className="flex flex-col gap-0.5">
            {items.map((item) => {
              const Icon = item.icon;
              const active = pathname === item.href || pathname.startsWith(`${item.href}/`);

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    "flex h-10 items-center gap-3 rounded-r-full rounded-l-lg px-4 text-sm transition",
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
