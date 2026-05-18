import Link from "next/link";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export default function UnauthorizedPage() {
  return (
    <main className="content-shell flex min-h-screen items-center justify-center py-10">
      <Card className="max-w-lg">
        <CardHeader>
          <CardTitle>Không có quyền truy cập</CardTitle>
          <CardDescription>Tài khoản hiện tại không có quyền truy cập khu vực quản trị.</CardDescription>
        </CardHeader>
        <CardContent className="flex gap-3">
          <Button asChild variant="outline">
            <Link href="/dashboard">Về dashboard</Link>
          </Button>
          <Button asChild>
            <Link href="/billing">Xem gói cước</Link>
          </Button>
        </CardContent>
      </Card>
    </main>
  );
}
