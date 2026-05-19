"use client";

import { HelpCircle, Menu, Moon, Search, Settings2, Sun } from "lucide-react";
import { signOut } from "firebase/auth";
import { useTheme } from "next-themes";
import { useEffect, useState } from "react";

import { auth } from "@/lib/firebase";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { AppSidebar } from "@/components/layout/app-sidebar";
import { useAuth } from "@/hooks/use-auth";

export function Topbar() {
  const { profile } = useAuth();
  const { resolvedTheme, setTheme } = useTheme();
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  const isDark = mounted && resolvedTheme === "dark";

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

        <div className="flex h-12 flex-1 items-center gap-3 rounded-full bg-secondary px-4 shadow-sm transition focus-within:bg-card focus-within:shadow-md md:max-w-4xl">
          <Search className="size-4 text-muted-foreground" />
          <span className="hidden text-sm text-muted-foreground sm:inline">Tìm user, website, plan hoặc credit log</span>
          <span className="text-sm text-muted-foreground sm:hidden">Tìm kiếm</span>
        </div>

        <div className="ml-auto flex items-center gap-3">
          <Button variant="ghost" size="icon" aria-label="Help">
            <HelpCircle className="size-4" />
          </Button>
          <Button variant="ghost" size="icon" aria-label="Settings">
            <Settings2 className="size-4" />
          </Button>
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
              <button className="flex h-12 items-center gap-3 rounded-full border border-border bg-card px-2 py-1 shadow-sm transition hover:bg-secondary/70 sm:px-3">
                <Avatar>
                  <AvatarFallback>{profile?.email?.slice(0, 2).toUpperCase() ?? "CA"}</AvatarFallback>
                </Avatar>
                <div className="hidden text-left sm:block">
                  <p className="text-sm font-medium">{profile?.displayName ?? profile?.email ?? "Guest"}</p>
                  <p className="text-xs text-muted-foreground">{profile?.role ?? "user"}</p>
                </div>
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => signOut(auth)}>Đăng xuất</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </header>
  );
}
