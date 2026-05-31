"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";

import { createProduct, updateProduct } from "@/lib/account";
import { productSchema, type ProductValues } from "@/lib/validators";
import { formatUsd } from "@/lib/utils";
import { useAuth } from "@/hooks/use-auth";
import type { Product } from "@/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

export function ProductForm({ product }: { product?: Product | null }) {
  const router = useRouter();
  const { profile } = useAuth();
  const [submitting, setSubmitting] = useState(false);
  const creditsPerUsd = Math.max(1, profile?.legacyCreditsPerUsd ?? 100);
  const form = useForm<ProductValues>({
    resolver: zodResolver(productSchema),
    defaultValues: {
      name: product?.name ?? "",
      type: product?.type ?? "captcha_pack",
      price: product?.price ?? 0,
      captchaCredits: product?.captchaCredits ?? 10,
      balanceUsd: product?.balanceUsd ?? 0,
      credits: product?.credits ?? 0,
      isActive: product?.isActive ?? true
    }
  });
  const productType = form.watch("type");
  const credits = Number(form.watch("credits") || 0);
  const estimatedUsd = credits > 0 ? credits / creditsPerUsd : 0;

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      const payload = {
        name: values.name,
        type: values.type,
        price: values.price,
        isActive: values.isActive,
        captchaCredits: values.type === "captcha_pack" ? values.captchaCredits ?? 0 : 0,
        balanceUsd: values.type === "audit_credit" ? values.balanceUsd ?? 0 : 0,
        credits: values.type === "audit_credit" ? values.credits ?? 0 : 0
      };

      if (product) {
        await updateProduct(product.id, payload);
        toast.success("Sản phẩm đã được cập nhật.");
      } else {
        await createProduct(payload);
        toast.success("Sản phẩm mới đã được tạo.");
      }
      router.replace("/admin/products");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu sản phẩm.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="max-w-3xl">
      <CardHeader>
        <CardTitle>{product ? "Cập nhật sản phẩm" : "Tạo sản phẩm"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-5" onSubmit={onSubmit}>
          <div className="flex flex-col gap-2">
            <Label htmlFor="product-name">Tên sản phẩm</Label>
            <Input id="product-name" placeholder="Gói 50 lượt captcha" {...form.register("name")} />
            {form.formState.errors.name ? <p className="text-sm text-destructive">{form.formState.errors.name.message}</p> : null}
          </div>

          <div className="grid gap-5 md:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label htmlFor="product-type">Loại sản phẩm</Label>
              <Select
                value={productType}
                onValueChange={(value) => form.setValue("type", value as ProductValues["type"], { shouldValidate: true })}
              >
                <SelectTrigger id="product-type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="captcha_pack">Gói lượt captcha</SelectItem>
                  <SelectItem value="audit_credit">Credit audit (USD)</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="product-price">Giá bán (VND)</Label>
              <Input id="product-price" type="number" min={0} {...form.register("price")} />
              {form.formState.errors.price ? <p className="text-sm text-destructive">{form.formState.errors.price.message}</p> : null}
            </div>
          </div>

          {productType === "captcha_pack" ? (
            <div className="flex flex-col gap-2">
              <Label htmlFor="product-captcha-credits">Số lượt captcha cộng vào tài khoản</Label>
              <Input id="product-captcha-credits" type="number" min={1} step={1} {...form.register("captchaCredits")} />
              {form.formState.errors.captchaCredits ? (
                <p className="text-sm text-destructive">{form.formState.errors.captchaCredits.message}</p>
              ) : null}
            </div>
          ) : (
            <div className="grid gap-5 md:grid-cols-2">
              <div className="flex flex-col gap-2">
                <Label htmlFor="product-balance-usd">Số dư USD cộng vào tài khoản</Label>
                <Input id="product-balance-usd" type="number" min={0} step="0.01" {...form.register("balanceUsd")} />
                {form.formState.errors.balanceUsd ? (
                  <p className="text-sm text-destructive">{form.formState.errors.balanceUsd.message}</p>
                ) : null}
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="product-credits">Credit audit (tuỳ chọn, tự quy đổi nếu để trống USD)</Label>
                <Input id="product-credits" type="number" min={0} step={1} {...form.register("credits")} />
                <p className="text-xs text-muted-foreground">
                  {credits > 0 ? `Tương ứng ${formatUsd(estimatedUsd, 4)} theo tỷ lệ ${creditsPerUsd.toLocaleString("vi-VN")} credit = $1.` : null}
                </p>
              </div>
            </div>
          )}

          <label className="flex items-center gap-3 rounded-xl border border-border bg-background/70 px-4 py-3 text-sm">
            <input className="size-4 accent-indigo-600" type="checkbox" {...form.register("isActive")} />
            Sản phẩm đang active
          </label>

          <div className="flex items-center justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => router.back()}>
              Huỷ
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Đang lưu..." : product ? "Cập nhật" : "Tạo sản phẩm"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
