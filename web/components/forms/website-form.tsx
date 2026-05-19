"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { ChevronDown, Globe2, Plus, Sparkles } from "lucide-react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";

import { AiModelSelect } from "@/components/forms/ai-model-select";
import { AuditTargetUrlEditor, urlsToInput } from "@/components/forms/audit-target-url-editor";
import { createWebsite, saveWebsiteAudit } from "@/lib/firestore";
import { createWebsiteSchema, parseCategories, type CreateWebsiteValues } from "@/lib/validators";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { cn } from "@/lib/utils";

export function WebsiteForm({ userId }: { userId: string }) {
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const [auditOpen, setAuditOpen] = useState(true);
  const [urlList, setUrlList] = useState<string[]>([]);
  const [selectedUrls, setSelectedUrls] = useState<string[]>([]);
  const form = useForm<CreateWebsiteValues>({
    resolver: zodResolver(createWebsiteSchema),
    defaultValues: {
      name: "",
      url: "",
      articleUrlsInput: "",
      categoriesInput: "",
      checklistText: "",
      aiProvider: "openai",
      aiModel: ""
    }
  });

  const aiProvider = form.watch("aiProvider");

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      const id = await createWebsite(userId, { name: values.name, url: values.url });

      if (urlList.length > 0) {
        if (!values.categoriesInput?.trim()) {
          toast.error("Khi thêm URL audit, cần nhập ít nhất một danh mục.");
          router.replace(`/websites/${id}/audit`);
          return;
        }

        parseCategories(values.categoriesInput);
        await saveWebsiteAudit({
          websiteId: id,
          userId,
          articleUrlsInput: urlsToInput(urlList),
          categoriesInput: values.categoriesInput,
          checklistText: values.checklistText,
          aiProvider: values.aiProvider,
          aiModel: values.aiModel
        });
      }

      toast.success(urlList.length ? "Đã tạo website và lưu cấu hình audit." : "Website audit đã được tạo.");
      router.replace(`/websites/${id}/audit`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tạo website audit.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <form className="flex max-w-4xl flex-col gap-6" onSubmit={onSubmit}>
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Globe2 className="size-5 text-primary" />
            Thông tin website
          </CardTitle>
          <CardDescription>Khai báo tên và URL gốc của website cần audit SEO.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-5 md:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor="website-name">Tên website</Label>
            <Input id="website-name" placeholder="VD: Học tiếng Anh Philippines" {...form.register("name")} />
            {form.formState.errors.name ? <p className="text-sm text-destructive">{form.formState.errors.name.message}</p> : null}
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="website-url">URL website</Label>
            <Input id="website-url" placeholder="https://example.com" {...form.register("url")} />
            {form.formState.errors.url ? <p className="text-sm text-destructive">{form.formState.errors.url.message}</p> : null}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="cursor-pointer" onClick={() => setAuditOpen((current) => !current)}>
          <div className="flex items-start justify-between gap-3">
            <div>
              <CardTitle className="flex items-center gap-2">
                <Sparkles className="size-5 text-primary" />
                Cấu hình audit ban đầu
                <span className="text-xs font-normal text-muted-foreground">(tuỳ chọn)</span>
              </CardTitle>
              <CardDescription className="mt-1">
                Thêm URL mục tiêu, danh mục và AI provider ngay khi tạo. Có thể bỏ qua và cấu hình sau.
              </CardDescription>
            </div>
            <ChevronDown className={cn("size-5 shrink-0 text-muted-foreground transition", auditOpen && "rotate-180")} />
          </div>
        </CardHeader>
        {auditOpen ? (
          <CardContent className="flex flex-col gap-5 border-t border-border/70 pt-5">
            <div className="flex flex-col gap-2">
              <Label>URL mục tiêu</Label>
              <AuditTargetUrlEditor
                urls={urlList}
                onChange={(next) => {
                  setUrlList(next);
                  setSelectedUrls(next);
                  form.setValue("articleUrlsInput", urlsToInput(next));
                }}
                selectedUrls={selectedUrls}
                onSelectedChange={setSelectedUrls}
                emptyHint="Thêm URL bài viết cần audit. Có thể bỏ trống và cấu hình sau."
              />
            </div>

            <div className="grid gap-5 lg:grid-cols-2">
              <div className="flex flex-col gap-2">
                <Label htmlFor="categoriesInput">Danh mục chuẩn</Label>
                <Textarea
                  id="categoriesInput"
                  rows={8}
                  placeholder={"`Tên danh mục` - `https://url-danh-muc`"}
                  {...form.register("categoriesInput")}
                />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="checklistText">Checklist SEO</Label>
                <Textarea
                  id="checklistText"
                  rows={8}
                  placeholder="Để trống để dùng checklist mặc định Clickon."
                  {...form.register("checklistText")}
                />
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="flex flex-col gap-2">
                <Label htmlFor="create-aiProvider">AI provider</Label>
                <Select
                  value={aiProvider}
                  onValueChange={(value) => {
                    form.setValue("aiProvider", value as CreateWebsiteValues["aiProvider"]);
                    form.setValue("aiModel", "");
                  }}
                >
                  <SelectTrigger id="create-aiProvider">
                    <SelectValue placeholder="Chọn provider" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="openai">OpenAI</SelectItem>
                    <SelectItem value="gemini">Gemini</SelectItem>
                    <SelectItem value="gemini_deep_research">Gemini Deep Research</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <AiModelSelect
                key={aiProvider ?? "openai"}
                provider={aiProvider ?? "openai"}
                value={form.watch("aiModel")}
                onChange={(model) => form.setValue("aiModel", model)}
              />
            </div>
          </CardContent>
        ) : null}
      </Card>

      <div className="flex items-center justify-end gap-3">
        <Button type="button" variant="outline" onClick={() => router.back()}>
          Huỷ
        </Button>
        <Button type="submit" disabled={submitting} size="lg">
          <Plus className="size-4" />
          {submitting ? "Đang tạo..." : "Tạo audit website"}
        </Button>
      </div>
    </form>
  );
}
