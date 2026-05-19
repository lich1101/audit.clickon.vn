import { ChevronRight } from "lucide-react";
import Link from "next/link";

import { Button } from "@/components/ui/button";

type BreadcrumbItem = {
  label: string;
  href?: string;
};

export function PageHeader({
  title,
  description,
  breadcrumbs,
  action
}: {
  title: string;
  description: string;
  breadcrumbs: BreadcrumbItem[];
  action?: { label: string; href: string };
}) {
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
        {breadcrumbs.map((item, index) => (
          <div key={`${item.label}-${index}`} className="flex items-center gap-2">
            {item.href ? (
              <Link href={item.href} className="transition hover:text-foreground">
                {item.label}
              </Link>
            ) : (
              <span className="text-foreground">{item.label}</span>
            )}
            {index < breadcrumbs.length - 1 ? <ChevronRight className="size-3.5" /> : null}
          </div>
        ))}
      </div>

      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold tracking-normal text-balance">{title}</h1>
          <p className="max-w-3xl text-sm leading-6 text-muted-foreground">{description}</p>
        </div>

        {action ? (
          <Button asChild>
            <Link href={action.href}>{action.label}</Link>
          </Button>
        ) : null}
      </div>
    </div>
  );
}
