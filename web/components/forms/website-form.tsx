"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { toast } from "sonner";

import { createWebsite } from "@/lib/firestore";
import { websiteSchema, type WebsiteValues } from "@/lib/validators";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function WebsiteForm({ userId }: { userId: string }) {
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const form = useForm<WebsiteValues>({
    resolver: zodResolver(websiteSchema),
    defaultValues: {
      name: "",
      url: ""
    }
  });

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      const id = await createWebsite(userId, values);
      toast.success("Website đã được tạo.");
      router.replace(`/websites/${id}`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tạo website.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="max-w-2xl">
      <CardHeader>
        <CardTitle>Tạo website mới</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-5" onSubmit={onSubmit}>
          <div className="flex flex-col gap-2">
            <Label htmlFor="website-name">Tên website</Label>
            <Input id="website-name" placeholder="Clickon Blog" {...form.register("name")} />
            {form.formState.errors.name ? <p className="text-sm text-destructive">{form.formState.errors.name.message}</p> : null}
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="website-url">Đường dẫn website</Label>
            <Input id="website-url" placeholder="https://example.com" {...form.register("url")} />
            {form.formState.errors.url ? <p className="text-sm text-destructive">{form.formState.errors.url.message}</p> : null}
          </div>
          <div className="flex items-center justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => router.back()}>
              Huỷ
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Đang tạo..." : "Tạo website"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
