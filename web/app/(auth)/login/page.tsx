import { Suspense } from "react";

import { LoginForm } from "@/components/forms/login-form";
import { LoadingState } from "@/components/dashboard/loading-state";

export default function LoginPage() {
  return (
    <Suspense fallback={<LoadingState title="Đang tải..." description="Đang chuẩn bị biểu mẫu đăng nhập." />}>
      <LoginForm />
    </Suspense>
  );
}
