"use client";

import { use, useEffect, useState } from "react";
import { toast } from "sonner";

import { ProductForm } from "@/components/forms/product-form";
import { LoadingState } from "@/components/dashboard/loading-state";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { fetchProduct } from "@/lib/account";
import type { Product } from "@/types";

export default function EditProductPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const [product, setProduct] = useState<Product | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    void fetchProduct(id)
      .then(setProduct)
      .catch((error) => toast.error(error instanceof Error ? error.message : "Không thể tải sản phẩm."))
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) {
    return <LoadingState title="Đang tải sản phẩm..." description="" />;
  }

  if (!product) {
    return (
      <EmptyState
        title="Không tìm thấy sản phẩm"
        description="Sản phẩm không tồn tại hoặc đã bị xóa."
        action={{ label: "Về danh sách", href: "/admin/products" }}
      />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={`Sửa sản phẩm: ${product.name}`}
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Sản phẩm", href: "/admin/products" },
          { label: product.name }
        ]}
      />
      <ProductForm product={product} />
    </div>
  );
}
