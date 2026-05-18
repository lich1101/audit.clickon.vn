"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { signInWithEmailAndPassword } from "firebase/auth";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { toast } from "sonner";

import { auth } from "@/lib/firebase";
import { loginSchema, type LoginValues } from "@/lib/validators";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [submitting, setSubmitting] = useState(false);
  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      email: "",
      password: ""
    }
  });

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      await signInWithEmailAndPassword(auth, values.email, values.password);
      toast.success("Đăng nhập thành công.");
      router.replace(searchParams.get("redirect") || "/dashboard");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Đăng nhập thất bại.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="w-full max-w-lg overflow-hidden">
      <CardHeader className="space-y-4">
        <div className="inline-flex w-fit rounded-full border border-primary/15 bg-primary/10 px-4 py-1 text-xs uppercase tracking-[0.2em] text-primary">
          Clickon Audit
        </div>
        <div className="space-y-2">
          <CardTitle className="text-3xl">Đăng nhập</CardTitle>
          <CardDescription>Truy cập dashboard credit và quản lý audit website theo thời gian thực.</CardDescription>
        </div>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-5" onSubmit={onSubmit}>
          <div className="flex flex-col gap-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" placeholder="you@company.com" {...form.register("email")} />
            {form.formState.errors.email ? <p className="text-sm text-destructive">{form.formState.errors.email.message}</p> : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="password">Mật khẩu</Label>
            <Input id="password" type="password" placeholder="••••••••" {...form.register("password")} />
            {form.formState.errors.password ? <p className="text-sm text-destructive">{form.formState.errors.password.message}</p> : null}
          </div>

          <Button disabled={submitting} type="submit">
            {submitting ? "Đang đăng nhập..." : "Đăng nhập"}
          </Button>

          <p className="text-sm text-muted-foreground">
            Chưa có tài khoản?{" "}
            <Link className="font-medium text-primary" href="/register">
              Tạo tài khoản mới
            </Link>
          </p>
        </form>
      </CardContent>
    </Card>
  );
}
