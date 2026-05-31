"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";

import { addCredits, subtractCredits } from "@/lib/credits";
import { creditMutationSchema, type CreditMutationValues } from "@/lib/validators";
import { formatUsd } from "@/lib/utils";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function CreditAdjustmentForm({
  userId,
  type,
  onMutated
}: {
  userId: string;
  type: "add" | "subtract";
  onMutated?: () => void | Promise<void>;
}) {
  const { profile } = useAuth();
  const [submitting, setSubmitting] = useState(false);
  const creditsPerUsd = Math.max(1, profile?.legacyCreditsPerUsd ?? 100);
  const form = useForm<CreditMutationValues>({
    resolver: zodResolver(creditMutationSchema),
    defaultValues: {
      userId,
      credits: 1,
      reason: ""
    }
  });
  const credits = Number(form.watch("credits") || 0);
  const estimatedUsd = credits > 0 ? credits / creditsPerUsd : 0;

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      if (type === "add") {
        await addCredits(values);
      } else {
        await subtractCredits(values);
      }
      form.reset({
        userId,
        credits: 1,
        reason: ""
      });
      await onMutated?.();
      toast.success(type === "add" ? "Đã cộng credit." : "Đã trừ credit.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể cập nhật số dư.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>{type === "add" ? "Cộng credit" : "Trừ credit"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-4" onSubmit={onSubmit}>
          <input type="hidden" {...form.register("userId")} />
          <div className="flex flex-col gap-2">
            <Label htmlFor={`${type}-amount`}>Số credit</Label>
            <Input id={`${type}-amount`} type="number" min={1} step={1} {...form.register("credits")} />
            {form.formState.errors.credits ? <p className="text-sm text-destructive">{form.formState.errors.credits.message}</p> : null}
            <p className="text-xs text-muted-foreground">
              Tương ứng {formatUsd(estimatedUsd, 4)} theo tỷ lệ {creditsPerUsd.toLocaleString("vi-VN")} credit = $1.
            </p>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor={`${type}-reason`}>Lý do</Label>
            <Input id={`${type}-reason`} placeholder="Manual adjustment từ admin" {...form.register("reason")} />
            {form.formState.errors.reason ? <p className="text-sm text-destructive">{form.formState.errors.reason.message}</p> : null}
          </div>
          <Button type="submit" variant={type === "add" ? "default" : "destructive"} disabled={submitting}>
            {submitting ? "Đang xử lý..." : type === "add" ? "Cộng credit" : "Trừ credit"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
