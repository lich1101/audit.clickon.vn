"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { createUserWithEmailAndPassword, updateProfile } from "firebase/auth";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { toast } from "sonner";

import { auth } from "@/lib/firebase";
import { syncClientSession } from "@/lib/session-client";
import { registerSchema, type RegisterValues } from "@/lib/validators";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function RegisterForm() {
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const form = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      displayName: "",
      email: "",
      password: ""
    }
  });

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      const credential = await createUserWithEmailAndPassword(auth, values.email, values.password);
      await updateProfile(credential.user, {
        displayName: values.displayName
      });
      await syncClientSession(await credential.user.getIdToken(true));
      toast.success("Tài khoản đã được tạo.");
      router.replace("/dashboard");
      router.refresh();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tạo tài khoản.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="w-full max-w-lg overflow-hidden">
      <CardHeader className="space-y-4">
        <div className="inline-flex w-fit rounded-full border border-emerald-500/15 bg-emerald-500/10 px-4 py-1 text-xs uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-300">
          Clickon Audit
        </div>
        <div className="space-y-2">
          <CardTitle className="text-3xl">Tạo tài khoản</CardTitle>
          <CardDescription>Đăng ký để tạo website, theo dõi credit và gửi audit về đúng danh mục.</CardDescription>
        </div>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-5" onSubmit={onSubmit}>
          <div className="flex flex-col gap-2">
            <Label htmlFor="displayName">Tên hiển thị</Label>
            <Input id="displayName" autoComplete="name" placeholder="Nguyen Van A" {...form.register("displayName")} />
            {form.formState.errors.displayName ? <p className="text-sm text-destructive">{form.formState.errors.displayName.message}</p> : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="register-email">Email</Label>
            <Input id="register-email" type="email" autoComplete="email" placeholder="you@company.com" {...form.register("email")} />
            {form.formState.errors.email ? <p className="text-sm text-destructive">{form.formState.errors.email.message}</p> : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="register-password">Mật khẩu</Label>
            <Input id="register-password" type="password" autoComplete="new-password" placeholder="••••••••" {...form.register("password")} />
            {form.formState.errors.password ? <p className="text-sm text-destructive">{form.formState.errors.password.message}</p> : null}
          </div>

          <Button disabled={submitting} type="submit">
            {submitting ? "Đang tạo tài khoản..." : "Tạo tài khoản"}
          </Button>

          <p className="text-sm text-muted-foreground">
            Đã có tài khoản?{" "}
            <Link className="font-medium text-primary" href="/login">
              Đăng nhập ngay
            </Link>
          </p>
        </form>
      </CardContent>
    </Card>
  );
}
