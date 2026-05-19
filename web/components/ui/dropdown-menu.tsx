import * as DropdownMenuPrimitive from "@radix-ui/react-dropdown-menu";

import { cn } from "@/lib/utils";

const DropdownMenu = DropdownMenuPrimitive.Root;
const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;
const DropdownMenuContent = ({ className, sideOffset = 8, ...props }: DropdownMenuPrimitive.DropdownMenuContentProps) => (
  <DropdownMenuPrimitive.Portal>
    <DropdownMenuPrimitive.Content
      sideOffset={sideOffset}
      className={cn("z-50 min-w-48 rounded-2xl border border-border bg-popover p-2 text-popover-foreground shadow-soft outline-none", className)}
      {...props}
    />
  </DropdownMenuPrimitive.Portal>
);
const DropdownMenuItem = ({ className, ...props }: DropdownMenuPrimitive.DropdownMenuItemProps) => (
  <DropdownMenuPrimitive.Item className={cn("flex cursor-pointer items-center rounded-xl px-3 py-2 text-sm outline-none transition hover:bg-secondary focus:bg-secondary", className)} {...props} />
);
const DropdownMenuSeparator = ({ className, ...props }: DropdownMenuPrimitive.DropdownMenuSeparatorProps) => (
  <DropdownMenuPrimitive.Separator className={cn("-mx-2 my-2 h-px bg-border", className)} {...props} />
);

export { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger };
