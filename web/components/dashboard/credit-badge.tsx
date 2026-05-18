import { Coins } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { formatNumber } from "@/lib/utils";

export function CreditBadge({ credits }: { credits: number }) {
  return (
    <Badge variant="success" className="gap-1.5">
      <Coins className="size-3.5" />
      {formatNumber(credits)} credits
    </Badge>
  );
}
