"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/dashboard/empty-state";
import { ProductCard } from "@/components/dashboard/product-card";
import { PageHeader } from "@/components/layout/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/use-auth";
import { createProductRequest, fetchProductRequests, fetchProducts } from "@/lib/account";
import { formatCurrency, formatDate, formatNumber, formatUsd } from "@/lib/utils";
import type { Product, ProductRequest } from "@/types";

function requestSummary(request: ProductRequest) {
  if (request.productType === "captcha_pack") {
    return `${formatNumber(request.captchaCredits)} lượt captcha`;
  }

  return `${formatNumber(request.credits)} credit · ${formatUsd(request.balanceUsd, 2)}`;
}

export default function ProductsPage() {
  const { profile } = useAuth();
  const [products, setProducts] = useState<Product[]>([]);
  const [requests, setRequests] = useState<ProductRequest[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    void fetchProducts(true).then(setProducts).catch(() => setProducts([]));
  }, []);

  useEffect(() => {
    if (!profile) return;

    void fetchProductRequests()
      .then(setRequests)
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải lịch sử mua sản phẩm."));
  }, [profile]);

  async function handlePurchase(product: Product) {
    try {
      setLoading(true);
      await createProductRequest(product.id);
      toast.success("Yêu cầu mua sản phẩm đã được gửi để admin duyệt.");
      setRequests(await fetchProductRequests());
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể tạo yêu cầu mua sản phẩm.");
    } finally {
      setLoading(false);
    }
  }

  const captchaProducts = products.filter((item) => item.type === "captcha_pack");
  const auditProducts = products.filter((item) => item.type === "audit_credit");

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Sản phẩm"
        description="Mua lẻ lượt giải captcha hoặc credit audit — tách biệt với gói cước định kỳ."
        breadcrumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Sản phẩm" }]}
      />

      {captchaProducts.length ? (
        <section className="flex flex-col gap-4">
          <h2 className="text-lg font-semibold">Gói lượt captcha</h2>
          <div className="grid gap-4 lg:grid-cols-3">
            {captchaProducts.map((product) => (
              <ProductCard key={product.id} product={product} loading={loading} onSelect={handlePurchase} />
            ))}
          </div>
        </section>
      ) : null}

      {auditProducts.length ? (
        <section className="flex flex-col gap-4">
          <h2 className="text-lg font-semibold">Credit audit</h2>
          <div className="grid gap-4 lg:grid-cols-3">
            {auditProducts.map((product) => (
              <ProductCard key={product.id} product={product} loading={loading} onSelect={handlePurchase} />
            ))}
          </div>
        </section>
      ) : null}

      {!products.length ? (
        <EmptyState title="Chưa có sản phẩm active" description="Admin chưa publish sản phẩm bán lẻ nào cho hệ thống." />
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Yêu cầu mua sản phẩm gần đây</CardTitle>
        </CardHeader>
        <CardContent>
          {requests.length ? (
            <div className="grid gap-2">
              {requests.map((request) => (
                <div key={request.id} className="mail-row rounded-xl border border-border bg-background/70 px-4 py-3">
                  <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                      <p className="font-semibold">{request.productName}</p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {formatCurrency(request.price)} · {requestSummary(request)}
                      </p>
                    </div>
                    <div className="text-sm">
                      <p className="font-medium uppercase tracking-[0.16em] text-primary">{request.status}</p>
                      <p className="mt-1 text-muted-foreground">{formatDate(request.createdAt)}</p>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <EmptyState title="Chưa có yêu cầu mua sản phẩm" description="Khi bạn mua sản phẩm, trạng thái chờ duyệt sẽ hiển thị ở đây." />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
