import { AppSidebar } from "@/components/layout/app-sidebar";
import { Topbar } from "@/components/layout/topbar";

export function AppShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-background">
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-[236px] border-r border-border/70 bg-sidebar lg:block">
        <AppSidebar />
      </aside>

      <div className="flex min-h-screen min-w-0 flex-col lg:pl-[236px]">
        <Topbar />
        <main className="app-canvas content-shell flex-1 px-3 py-5 sm:px-4 sm:py-6">{children}</main>
      </div>
    </div>
  );
}
