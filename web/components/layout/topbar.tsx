"use client";

import { LayoutDashboard, Menu, Moon, Search, ShieldCheck, Sun } from "lucide-react";
import { signOut } from "firebase/auth";
import { useRouter } from "next/navigation";
import { useTheme } from "next-themes";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { auth } from "@/lib/firebase";
import { stopImpersonation } from "@/lib/impersonation";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { AppSidebar } from "@/components/layout/app-sidebar";
import { useAuth } from "@/hooks/use-auth";
import { useDashboardMode } from "@/hooks/use-dashboard-mode";

export function Topbar() {
  const router = useRouter();
  const { profile, refreshProfile } = useAuth();
  const { mode, isAdmin, isImpersonating, setDashboardMode } = useDashboardMode();
  const { resolvedTheme, setTheme } = useTheme();
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  const isDark = mounted && resolvedTheme === "dark";
  const displayModeLabel = profile?.isImpersonating
    ? "đăng nhập nhanh"
    : isAdmin
      ? mode === "admin"
        ? "admin mode"
        : "user mode"
      : profile?.role ?? "user";

  async function handleReturnToAdmin() {
    try {
      await stopImpersonation();
      await refreshProfile();
      setDashboardMode("admin");
      router.push("/admin");
      router.refresh();
      toast.success("Đã quay lại tài khoản admin.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể thoát đăng nhập nhanh.");
    }
  }

  return (
    <header className="sticky top-0 z-30 border-b border-border/70 bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
      <div className="flex h-16 items-center gap-3 px-3 sm:px-4">
        <Sheet>
          <SheetTrigger asChild>
            <Button variant="outline" size="icon" className="lg:hidden">
              <Menu className="size-4" />
            </Button>
          </SheetTrigger>
          <SheetContent className="w-[320px] p-0">
            <SheetHeader>
              <SheetTitle className="sr-only">Navigation</SheetTitle>
            </SheetHeader>
            <div className="h-full">
              <AppSidebar mobile />
            </div>
          </SheetContent>
        </Sheet>

        <div className="flex h-11 flex-1 items-center gap-3 rounded-full bg-secondary/80 px-4 shadow-sm transition focus-within:bg-card focus-within:shadow-md md:max-w-2xl">
          <Search className="size-4 text-muted-foreground" />
          <span className="text-sm text-muted-foreground">Tìm nhanh</span>
        </div>

        <div className="ml-auto flex items-center gap-3">
          {profile?.isImpersonating ? (
            <Button type="button" variant="secondary" className="hidden rounded-full px-4 md:inline-flex" onClick={() => void handleReturnToAdmin()}>
              <ShieldCheck className="size-4" />
              Trở về admin
            </Button>
          ) : null}
          <Button
            variant="outline"
            size="icon"
            aria-label={isDark ? "Chuyển sang giao diện sáng" : "Chuyển sang giao diện tối"}
            disabled={!mounted}
            onClick={() => setTheme(isDark ? "light" : "dark")}
          >
            {isDark ? <Sun className="size-4" /> : <Moon className="size-4" />}
          </Button>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="flex h-11 items-center gap-2 rounded-full border border-border bg-card px-2 py-1 shadow-sm transition hover:bg-secondary/70 sm:px-3">
                <Avatar>
                  <AvatarFallback>{profile?.email?.slice(0, 2).toUpperCase() ?? "CA"}</AvatarFallback>
                </Avatar>
                <div className="hidden text-left lg:block">
                  <p className="max-w-[140px] truncate text-sm font-medium">{profile?.displayName ?? profile?.email ?? "Guest"}</p>
                  <p className="text-[11px] text-muted-foreground">
                    {displayModeLabel}
                  </p>
                </div>
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {profile?.isImpersonating ? (
                <DropdownMenuItem
                  onSelect={() => {
                    void handleReturnToAdmin();
                  }}
                >
                  <ShieldCheck className="size-4" />
                  Thoát đăng nhập nhanh
                </DropdownMenuItem>
              ) : null}
              {isAdmin && mode === "user" ? (
                <DropdownMenuItem
                  onSelect={() => {
                    setDashboardMode("admin");
                    router.push("/admin");
                    router.refresh();
                  }}
                >
                  <ShieldCheck className="size-4" />
                  Chế độ quản trị
                </DropdownMenuItem>
              ) : null}
              {isAdmin && mode === "admin" && !isImpersonating ? (
                <DropdownMenuItem
                  onSelect={() => {
                    setDashboardMode("user");
                    router.push("/dashboard");
                    router.refresh();
                  }}
                >
                  <LayoutDashboard className="size-4" />
                  Chế độ người dùng
                </DropdownMenuItem>
              ) : null}
              {isAdmin ? <DropdownMenuSeparator /> : null}
              <DropdownMenuItem onSelect={() => signOut(auth)}>Đăng xuất</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </header>
  );
}
