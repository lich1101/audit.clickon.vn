import { Inbox } from "lucide-react";
import Link from "next/link";

import { Button } from "@/components/ui/button";

export function EmptyState({
  title,
  description,
  action
}: {
  title: string;
  description: string;
  action?: { label: string; href: string };
}) {
  return (
    <div className="flex flex-col items-center gap-4 rounded-[22px] border border-dashed border-border bg-secondary/25 px-6 py-12 text-center">
      <div className="flex size-14 items-center justify-center rounded-full bg-card text-muted-foreground shadow-sm">
        <Inbox className="size-6" />
      </div>
      <div className="flex flex-col gap-1">
        <h3 className="text-base font-semibold">{title}</h3>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">{description}</p>
      </div>
      {action ? (
        <Button asChild>
          <Link href={action.href}>{action.label}</Link>
        </Button>
      ) : null}
    </div>
  );
}
