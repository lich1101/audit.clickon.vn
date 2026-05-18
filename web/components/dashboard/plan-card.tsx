import { CheckCircle2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { CreditBadge } from "@/components/dashboard/credit-badge";
import { formatCurrency } from "@/lib/utils";
import type { Plan } from "@/types";

export function PlanCard({
  plan,
  onSelect,
  loading
}: {
  plan: Plan;
  onSelect?: (plan: Plan) => void;
  loading?: boolean;
}) {
  return (
    <Card className="h-full">
      <CardHeader>
        <div className="flex items-start justify-between gap-4">
          <div className="space-y-2">
            <CardTitle>{plan.name}</CardTitle>
            <CardDescription>Phù hợp cho luồng audit website cần cấp credit theo batch.</CardDescription>
          </div>
          <CreditBadge credits={plan.credits} />
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-3xl font-semibold">{formatCurrency(plan.price)}</p>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <CheckCircle2 className="size-4 text-emerald-500" />
          Kích hoạt thủ công sau khi admin duyệt
        </div>
      </CardContent>
      <CardFooter>
        <Button className="w-full" disabled={!plan.isActive || loading} onClick={() => onSelect?.(plan)}>
          Đăng ký gói cước
        </Button>
      </CardFooter>
    </Card>
  );
}
