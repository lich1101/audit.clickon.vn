import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const badgeVariants = cva("inline-flex items-center rounded-full px-3 py-1 text-xs font-medium", {
  variants: {
    variant: {
      default: "bg-primary/10 text-primary",
      secondary: "bg-secondary text-secondary-foreground",
      outline: "border border-border bg-background/60 text-foreground",
      success: "bg-emerald-500/10 text-emerald-600 dark:text-emerald-300",
      warning: "bg-amber-500/10 text-amber-600 dark:text-amber-300",
      destructive: "bg-red-500/10 text-red-600 dark:text-red-300"
    }
  },
  defaultVariants: {
    variant: "default"
  }
});

type BadgeProps = React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof badgeVariants>;

function Badge({ className, variant, ...props }: BadgeProps) {
  return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
