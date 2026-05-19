"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";

import { createPlan, updatePlan } from "@/lib/firestore";
import { planSchema, type PlanValues } from "@/lib/validators";
import type { Plan } from "@/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function PlanForm({ plan }: { plan?: Plan | null }) {
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const form = useForm<PlanValues>({
    resolver: zodResolver(planSchema),
    defaultValues: {
      name: plan?.name ?? "",
      price: plan?.price ?? 0,
      credits: plan?.credits ?? 0,
      isActive: plan?.isActive ?? true
    }
  });

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      setSubmitting(true);
      if (plan) {
        await updatePlan(plan.id, values);
        toast.success("Gói cước đã được cập nhật.");
      } else {
        await createPlan(values);
        toast.success("Gói cước mới đã được tạo.");
      }
      router.replace("/admin/plans");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể lưu gói cước.");
    } finally {
      setSubmitting(false);
    }
  });

  return (
    <Card className="max-w-3xl">
      <CardHeader>
        <CardTitle>{plan ? "Cập nhật gói cước" : "Tạo gói cước"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="flex flex-col gap-5" onSubmit={onSubmit}>
          <div className="flex flex-col gap-2">
            <Label htmlFor="plan-name">Tên gói cước</Label>
            <Input id="plan-name" placeholder="Starter Audit" {...form.register("name")} />
            {form.formState.errors.name ? <p className="text-sm text-destructive">{form.formState.errors.name.message}</p> : null}
          </div>
          <div className="grid gap-5 md:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label htmlFor="plan-price">Giá trị gói cước</Label>
              <Input id="plan-price" type="number" min={0} {...form.register("price")} />
              {form.formState.errors.price ? <p className="text-sm text-destructive">{form.formState.errors.price.message}</p> : null}
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="plan-credits">Số credit</Label>
              <Input id="plan-credits" type="number" min={1} {...form.register("credits")} />
              {form.formState.errors.credits ? <p className="text-sm text-destructive">{form.formState.errors.credits.message}</p> : null}
            </div>
          </div>
          <label className="flex items-center gap-3 rounded-xl border border-border bg-background/70 px-4 py-3 text-sm">
            <input className="size-4 accent-indigo-600" type="checkbox" {...form.register("isActive")} />
            Gói cước đang active
          </label>
          <div className="flex items-center justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => router.back()}>
              Huỷ
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Đang lưu..." : plan ? "Cập nhật" : "Tạo gói cước"}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
