"use client";

import { Menu, Moon, Search, Sun } from "lucide-react";
import { signOut } from "firebase/auth";
import { useTheme } from "next-themes";

import { auth } from "@/lib/firebase";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { AppSidebar } from "@/components/layout/app-sidebar";
import { useAuth } from "@/hooks/use-auth";

export function Topbar() {
  const { profile } = useAuth();
  const { theme, setTheme } = useTheme();

  return (
    <header className="sticky top-0 z-30 border-b border-border/70 bg-background/80 backdrop-blur-xl">
      <div className="content-shell flex h-20 items-center gap-3">
        <Sheet>
          <SheetTrigger asChild>
            <Button variant="outline" size="icon" className="lg:hidden">
              <Menu className="size-4" />
            </Button>
          </SheetTrigger>
          <SheetContent>
            <SheetHeader>
              <SheetTitle>Navigation</SheetTitle>
            </SheetHeader>
            <div className="mt-6 flex-1 overflow-hidden rounded-[28px] border border-white/10">
              <AppSidebar mobile />
            </div>
          </SheetContent>
        </Sheet>

        <div className="hidden flex-1 items-center gap-3 rounded-2xl border border-border bg-card/70 px-4 py-3 shadow-sm md:flex">
          <Search className="size-4 text-muted-foreground" />
          <span className="text-sm text-muted-foreground">Tìm user, website, plan hoặc credit log</span>
        </div>

        <div className="ml-auto flex items-center gap-3">
          <Button variant="outline" size="icon" onClick={() => setTheme(theme === "dark" ? "light" : "dark")}>
            {theme === "dark" ? <Sun className="size-4" /> : <Moon className="size-4" />}
          </Button>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button className="flex items-center gap-3 rounded-2xl border border-border bg-card/70 px-3 py-2 shadow-sm">
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
