import { ProductForm } from "@/components/forms/product-form";
import { PageHeader } from "@/components/layout/page-header";

export default function CreateProductPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Tạo sản phẩm"
        description="Khai báo sản phẩm bán lẻ: gói captcha hoặc credit audit."
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Sản phẩm", href: "/admin/products" },
          { label: "Create" }
        ]}
      />
      <ProductForm />
    </div>
  );
}
