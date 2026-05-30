import { Wallet } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { formatUsd } from "@/lib/utils";

export function CreditBadge({ balanceUsd }: { balanceUsd: number }) {
  return (
    <Badge variant="success" className="gap-1.5">
      <Wallet className="size-3.5" />
      {formatUsd(balanceUsd, 4)}
    </Badge>
  );
}
