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
    <Card className="overflow-hidden hover:-translate-y-0.5 hover:shadow-md">
      <CardContent className="flex items-center justify-between gap-4 p-5">
        <div className="flex flex-col gap-1.5">
          <p className="text-xs font-medium text-muted-foreground">{title}</p>
          <div>
            <p className="text-2xl font-semibold tracking-normal">{value}</p>
            <p className="mt-1 line-clamp-2 text-xs leading-5 text-muted-foreground">{hint}</p>
          </div>
        </div>
        <div className="flex size-11 items-center justify-center rounded-full bg-secondary text-primary">
          <Icon className="size-5" />
        </div>
      </CardContent>
    </Card>
  );
}
