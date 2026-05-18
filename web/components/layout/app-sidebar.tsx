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
  ReceiptText,
  Settings,
  ShieldCheck,
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
  { href: "/admin/credit-logs", label: "Credit Logs", icon: FolderSearch2 }
];

export function AppSidebar({ mobile = false }: { mobile?: boolean }) {
  const pathname = usePathname();
  const { profile } = useAuth();
  const items = profile?.role === "admin" ? [...userItems, ...adminItems] : userItems;

  return (
    <aside className={cn("flex h-full w-full flex-col bg-sidebar text-sidebar-foreground", !mobile && "w-72 border-r border-sidebar-border")}>
      <div className="flex items-center justify-between px-5 py-6">
        <div>
          <p className="text-xs uppercase tracking-[0.3em] text-sidebar-foreground/60">Clickon</p>
          <h1 className="mt-2 text-2xl font-semibold">Audit</h1>
        </div>
        <Badge variant="outline" className="border-white/15 bg-white/5 text-sidebar-foreground">
          {profile?.role ?? "user"}
        </Badge>
      </div>

      <ScrollArea className="flex-1 px-3 pb-5">
        <div className="flex flex-col gap-6">
          <div className="rounded-[28px] border border-white/10 bg-white/5 p-4">
            <p className="text-xs uppercase tracking-[0.18em] text-sidebar-foreground/50">Realtime credits</p>
            <div className="mt-3 flex items-center gap-3">
              <div className="flex size-12 items-center justify-center rounded-2xl bg-white/10">
                <ShieldCheck className="size-5" />
              </div>
              <div>
                <p className="text-2xl font-semibold">{profile?.credits ?? 0}</p>
                <p className="text-sm text-sidebar-foreground/60">Đồng bộ tức thì qua Firebase</p>
              </div>
            </div>
          </div>

          <nav className="flex flex-col gap-1">
            {items.map((item) => {
              const Icon = item.icon;
              const active = pathname === item.href || pathname.startsWith(`${item.href}/`);

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={cn(
                    "flex items-center gap-3 rounded-2xl px-3 py-3 text-sm transition",
                    active ? "bg-white text-slate-950 shadow-soft" : "text-sidebar-foreground/72 hover:bg-white/8 hover:text-sidebar-foreground"
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
