"use client";

import { useState } from "react";
import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";

import { saveWebsiteAudit } from "@/lib/firestore";
import { auditFormSchema, parseArticleUrls, parseCategories, type AuditFormValues } from "@/lib/validators";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

export function AuditForm({
  auditId,
  websiteId,
  userId,
  defaultArticleUrls,
  defaultCategories,
  redirectTo,
  onSaved
}: {
  auditId?: string;
  websiteId: string;
  userId: string;
  defaultArticleUrls?: string[];
  defaultCategories?: Array<{ name: string; url: string }>;
  redirectTo?: string;
  onSaved?: (payload: { auditId: string; articleUrls: string[]; categories: Array<{ name: string; url: string }> }) => void;
}) {
  const [submitting, setSubmitting] = useState(false);
  const form = useForm<AuditFormValues>({
    resolver: zodResolver(auditFormSchema),
    defaultValues: {
      articleUrlsInput: defaultArticleUrls?.join("\n") ?? "",
      categoriesInput:
        defaultCategories?.map((item) => `${item.name}-${item.url}`).join("\n") ?? ""
    }
  });

  useEffect(() => {
    form.reset({
      articleUrlsInput: defaultArticleUrls?.join("\n") ?? "",
      categoriesInput: defaultCategories?.map((item) => `${item.name}-${item.url}`).join("\n") ?? ""
    });
  }, [defaultArticleUrls, defaultCategories, form]);

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      const nextAuditId = await saveWebsiteAudit({
        auditId,
        websiteId,
        userId,
        articleUrlsInput: values.articleUrlsInput,
        categoriesInput: values.categoriesInput
      });
      const articleUrls = parseArticleUrls(values.articleUrlsInput);
      const categories = parseCategories(values.categoriesInput);

      toast.success("Audit đã được lưu.");
      onSaved?.({
        auditId: nextAuditId,
        articleUrls,
        categories
      });

      if (redirectTo) {
        window.location.assign(redirectTo);
      }
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu audit.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <CardTitle>Audit website</CardTitle>
        <CardDescription>Nhập mỗi URL một dòng. Danh mục phải theo format `Tên danh mục-https://url`.</CardDescription>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-5" onSubmit={onSubmit}>
          <div className="flex flex-col gap-2">
            <Label htmlFor="articleUrlsInput">Article URLs</Label>
            <Textarea
              id="articleUrlsInput"
              rows={10}
              placeholder={"https://example.com/post-1\nhttps://example.com/post-2"}
              {...form.register("articleUrlsInput")}
            />
            {form.formState.errors.articleUrlsInput ? <p className="text-sm text-destructive">{form.formState.errors.articleUrlsInput.message}</p> : null}
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="categoriesInput">Categories</Label>
            <Textarea
              id="categoriesInput"
              rows={10}
              placeholder={"Tin tức-https://example.com/tin-tuc\nSức khỏe-https://example.com/suc-khoe"}
              {...form.register("categoriesInput")}
            />
            {form.formState.errors.categoriesInput ? <p className="text-sm text-destructive">{form.formState.errors.categoriesInput.message}</p> : null}
          </div>
          <div className="flex items-center justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => form.reset()}>
              Khôi phục
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Đang lưu..." : "Lưu audit"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
