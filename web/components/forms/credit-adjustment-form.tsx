"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";

import { addCredits, subtractCredits } from "@/lib/credits";
import { creditMutationSchema, type CreditMutationValues } from "@/lib/validators";
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
  const [submitting, setSubmitting] = useState(false);
  const form = useForm<CreditMutationValues>({
    resolver: zodResolver(creditMutationSchema),
    defaultValues: {
      userId,
      amountUsd: 0,
      reason: ""
    }
  });

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
        amountUsd: 0,
        reason: ""
      });
      await onMutated?.();
      toast.success(type === "add" ? "Đã cộng số dư USD." : "Đã trừ số dư USD.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể cập nhật số dư.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>{type === "add" ? "Cộng số dư USD" : "Trừ số dư USD"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-4" onSubmit={onSubmit}>
          <input type="hidden" {...form.register("userId")} />
          <div className="flex flex-col gap-2">
            <Label htmlFor={`${type}-amount`}>Số tiền USD ($)</Label>
            <Input id={`${type}-amount`} type="number" min={0.000001} step={0.01} {...form.register("amountUsd")} />
            {form.formState.errors.amountUsd ? <p className="text-sm text-destructive">{form.formState.errors.amountUsd.message}</p> : null}
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor={`${type}-reason`}>Lý do</Label>
            <Input id={`${type}-reason`} placeholder="Manual adjustment từ admin" {...form.register("reason")} />
            {form.formState.errors.reason ? <p className="text-sm text-destructive">{form.formState.errors.reason.message}</p> : null}
          </div>
          <Button type="submit" variant={type === "add" ? "default" : "destructive"} disabled={submitting}>
            {submitting ? "Đang xử lý..." : type === "add" ? "Cộng USD" : "Trừ USD"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
