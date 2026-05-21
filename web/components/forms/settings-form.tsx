"use client";

import { useState } from "react";
import { updateProfile } from "firebase/auth";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { toast } from "sonner";

import { auth } from "@/lib/firebase";
import { useAuth } from "@/hooks/use-auth";
import { updateMe } from "@/lib/account";
import { settingsSchema, type SettingsValues } from "@/lib/validators";
import type { AppUser } from "@/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function SettingsForm({ profile }: { profile: AppUser }) {
  const { refreshProfile } = useAuth();
  const [submitting, setSubmitting] = useState(false);
  const form = useForm<SettingsValues>({
    resolver: zodResolver(settingsSchema),
    defaultValues: {
      displayName: profile.displayName ?? ""
    }
  });

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);

      if (auth.currentUser) {
        await updateProfile(auth.currentUser, {
          displayName: values.displayName
        });
      }

      await updateMe({ displayName: values.displayName });
      await refreshProfile();

      toast.success("Thông tin hồ sơ đã được cập nhật.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể cập nhật hồ sơ.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="max-w-4xl">
      <CardHeader>
        <CardTitle>Hồ sơ tài khoản</CardTitle>
        <CardDescription>Thông tin này được đồng bộ vào Firebase Authentication và hồ sơ MySQL qua Laravel API.</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-5" onSubmit={onSubmit}>
          <div className="grid gap-5 md:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label>Email</Label>
              <Input value={profile.email} disabled />
            </div>
            <div className="flex flex-col gap-2">
              <Label>UID</Label>
              <Input value={profile.uid} disabled />
            </div>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="settings-display-name">Tên hiển thị</Label>
            <Input id="settings-display-name" {...form.register("displayName")} />
            {form.formState.errors.displayName ? <p className="text-sm text-destructive">{form.formState.errors.displayName.message}</p> : null}
          </div>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Đang cập nhật..." : "Lưu thay đổi"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
