import Link from "next/link";
import { CheckCircle2, ShieldCheck, Wallet } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { formatCurrency, formatNumber, formatUsd } from "@/lib/utils";
import type { Product } from "@/types";

function productSummary(product: Product) {
  if (product.type === "captcha_pack") {
    return `${formatNumber(product.captchaCredits)} lượt giải captcha tự động`;
  }

  return `${formatNumber(product.credits)} credit audit · ${formatUsd(product.balanceUsd, 2)}`;
}

export function ProductCard({
  product,
  onSelect,
  loading
}: {
  product: Product;
  onSelect?: (product: Product) => void;
  loading?: boolean;
}) {
  const isCaptcha = product.type === "captcha_pack";

  return (
    <Card className="h-full hover:-translate-y-0.5 hover:shadow-md">
      <CardHeader>
        <div className="flex items-start justify-between gap-4">
          <div className="flex flex-col gap-1.5">
            <CardTitle>{product.name}</CardTitle>
            <p className="text-xs uppercase tracking-[0.16em] text-muted-foreground">
              {isCaptcha ? "Gói captcha" : "Credit audit"}
            </p>
          </div>
          {isCaptcha ? <ShieldCheck className="size-5 text-blue-500" /> : <Wallet className="size-5 text-emerald-500" />}
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="text-2xl font-semibold">{formatCurrency(product.price)}</p>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <CheckCircle2 className="size-4 text-emerald-500" />
          {productSummary(product)}
        </div>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <CheckCircle2 className="size-4 text-blue-500" />
          Kích hoạt thủ công sau khi admin duyệt
        </div>
        {isCaptcha ? (
          <p className="text-xs text-muted-foreground">
            Dùng cho check thứ hạng keyword khi bật tự động giải captcha.{" "}
            <Link className="text-primary underline-offset-4 hover:underline" href="/products">
              Xem thêm
            </Link>
          </p>
        ) : null}
      </CardContent>
      <CardFooter>
        <Button className="w-full" disabled={!product.isActive || loading} onClick={() => onSelect?.(product)}>
          Mua sản phẩm
        </Button>
      </CardFooter>
    </Card>
  );
}
