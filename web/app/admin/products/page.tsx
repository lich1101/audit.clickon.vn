"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/dashboard/confirm-dialog";
import { DataTable } from "@/components/dashboard/data-table";
import { EmptyState } from "@/components/dashboard/empty-state";
import { PageHeader } from "@/components/layout/page-header";
import { Button } from "@/components/ui/button";
import { fetchAdminProducts, updateProduct } from "@/lib/account";
import { formatCurrency, formatDate, formatNumber, formatUsd } from "@/lib/utils";
import type { Product } from "@/types";

function productValue(row: Product) {
  if (row.type === "captcha_pack") {
    return `${formatNumber(row.captchaCredits)} captcha`;
  }

  return `${formatNumber(row.credits)} credit · ${formatUsd(row.balanceUsd, 2)}`;
}

export default function AdminProductsPage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [search, setSearch] = useState("");

  useEffect(() => {
    void fetchAdminProducts().then(setProducts).catch(() => setProducts([]));
  }, []);

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();
    if (!keyword) return products;
    return products.filter((product) => product.name.toLowerCase().includes(keyword));
  }, [products, search]);

  async function deactivateProduct(product: Product) {
    try {
      await updateProduct(product.id, { isActive: false });
      setProducts(await fetchAdminProducts());
      toast.success("Sản phẩm đã được deactivate.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Không thể deactivate sản phẩm.");
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Sản phẩm"
        description="Quản lý sản phẩm bán lẻ: gói captcha hoặc credit audit, tách khỏi gói cước."
        breadcrumbs={[{ label: "Admin", href: "/admin" }, { label: "Sản phẩm" }]}
        action={{ label: "Tạo sản phẩm", href: "/admin/products/create" }}
      />

      <DataTable
        title="Product management"
        search={search}
        onSearchChange={setSearch}
        rows={filtered}
        columns={[
          { key: "name", header: "Sản phẩm", render: (row: Product) => row.name },
          {
            key: "type",
            header: "Loại",
            render: (row: Product) => (row.type === "captcha_pack" ? "Captcha" : "Audit credit")
          },
          { key: "price", header: "Giá", render: (row: Product) => formatCurrency(row.price) },
          { key: "value", header: "Giá trị", render: (row: Product) => productValue(row) },
          { key: "status", header: "Status", render: (row: Product) => (row.isActive ? "active" : "inactive") },
          { key: "createdAt", header: "Ngày tạo", render: (row: Product) => formatDate(row.createdAt) },
          {
            key: "actions",
            header: "Actions",
            render: (row: Product) => (
              <div className="flex gap-2">
                <Button asChild size="sm" variant="secondary">
                  <Link href={`/admin/products/${row.id}/edit`}>Sửa</Link>
                </Button>
                {row.isActive ? (
                  <ConfirmDialog
                    trigger={
                      <Button size="sm" variant="outline">
                        Deactivate
                      </Button>
                    }
                    title="Deactivate sản phẩm"
                    description="Sản phẩm sẽ bị ẩn khỏi trang Sản phẩm cho user."
                    actionLabel="Deactivate"
                    onConfirm={() => void deactivateProduct(row)}
                  />
                ) : null}
              </div>
            )
          }
        ]}
        empty={
          <EmptyState
            title="Chưa có sản phẩm"
            description="Hãy tạo sản phẩm bán lẻ đầu tiên cho hệ thống."
            action={{ label: "Tạo sản phẩm", href: "/admin/products/create" }}
          />
        }
      />
    </div>
  );
}
