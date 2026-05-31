import { Wallet } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { formatNumber, formatUsd } from "@/lib/utils";

export function CreditBadge({ balanceUsd, credits }: { balanceUsd: number; credits?: number }) {
  return (
    <Badge variant="success" className="gap-1.5">
      <Wallet className="size-3.5" />
      {credits !== undefined ? `${formatNumber(credits)} credit · ` : null}
      {formatUsd(balanceUsd, 4)}
    </Badge>
  );
}
