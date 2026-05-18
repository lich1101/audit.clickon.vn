import { AppShell } from "@/components/layout/app-shell";
import { AdminRoute } from "@/components/layout/admin-route";
import { ProtectedRoute } from "@/components/layout/protected-route";

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return (
    <ProtectedRoute>
      <AdminRoute>
        <AppShell>{children}</AppShell>
      </AdminRoute>
    </ProtectedRoute>
  );
}
