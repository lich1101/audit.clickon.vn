import { AppSidebar } from "@/components/layout/app-sidebar";
import { Topbar } from "@/components/layout/topbar";

export function AppShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[264px_1fr]">
      <div className="hidden lg:block">
        <AppSidebar />
      </div>
      <div className="min-w-0 pb-3 pr-3 lg:pb-4 lg:pr-4">
        <Topbar />
        <main className="app-canvas content-shell min-h-[calc(100vh-84px)] py-5 sm:py-6">{children}</main>
      </div>
    </div>
  );
}
