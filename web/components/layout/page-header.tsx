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
  description?: string;
  breadcrumbs: BreadcrumbItem[];
  action?: { label: string; href: string };
}) {
  const compactBreadcrumbs =
    breadcrumbs.length > 3
      ? [{ label: "..." }, ...breadcrumbs.slice(-2)]
      : breadcrumbs;
  const compactDescription = description ? description.replace(/\s+/g, " ").trim().slice(0, 140) : "";

  return (
    <div className="flex flex-col gap-2">
      {compactBreadcrumbs.length > 1 ? (
        <div className="flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
          {compactBreadcrumbs.map((item, index) => (
            <div key={`${item.label}-${index}`} className="flex items-center gap-2">
              {item.href ? (
                <Link href={item.href} className="transition hover:text-foreground">
                  {item.label}
                </Link>
              ) : (
                <span className={index === compactBreadcrumbs.length - 1 ? "text-foreground" : undefined}>{item.label}</span>
              )}
              {index < compactBreadcrumbs.length - 1 ? <ChevronRight className="size-3.5" /> : null}
            </div>
          ))}
        </div>
      ) : null}

      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div className="min-w-0 space-y-1">
          <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
          {compactDescription ? <p className="max-w-2xl text-sm text-muted-foreground">{compactDescription}</p> : null}
        </div>

        {action ? (
          <Button asChild size="sm" className="w-full md:w-auto">
            <Link href={action.href}>{action.label}</Link>
          </Button>
        ) : null}
      </div>
    </div>
  );
}
