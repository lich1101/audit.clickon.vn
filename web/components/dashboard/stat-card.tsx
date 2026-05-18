import { ArrowUpRight } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";

export function StatCard({
  title,
  value,
  hint,
  icon: Icon
}: {
  title: string;
  value: string;
  hint: string;
  icon: LucideIcon;
}) {
  return (
    <Card className="overflow-hidden">
      <CardContent className="flex items-start justify-between gap-4 p-6">
        <div className="space-y-3">
          <p className="text-sm text-muted-foreground">{title}</p>
          <div>
            <p className="text-3xl font-semibold tracking-tight">{value}</p>
            <p className="mt-2 text-sm text-muted-foreground">{hint}</p>
          </div>
        </div>
        <div className="flex size-14 items-center justify-center rounded-3xl bg-primary/10 text-primary">
          <Icon className="size-5" />
        </div>
      </CardContent>
      <div className="flex items-center justify-end gap-2 border-t border-border/70 px-6 py-3 text-xs uppercase tracking-[0.2em] text-muted-foreground">
        Live
        <ArrowUpRight className="size-3.5" />
      </div>
    </Card>
  );
}
