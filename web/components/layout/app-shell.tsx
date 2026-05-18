import { AppSidebar } from "@/components/layout/app-sidebar";
import { Topbar } from "@/components/layout/topbar";

export function AppShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[288px_1fr]">
      <div className="hidden lg:block">
        <AppSidebar />
      </div>
      <div className="min-w-0">
        <Topbar />
        <main className="content-shell py-8">{children}</main>
      </div>
    </div>
  );
}
