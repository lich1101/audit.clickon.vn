import { PlanForm } from "@/components/forms/plan-form";
import { PageHeader } from "@/components/layout/page-header";

export default function CreatePlanPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Tạo gói cước"
        description="Khai báo tên gói cước, giá trị, số credit và trạng thái active/inactive."
        breadcrumbs={[
          { label: "Admin", href: "/admin" },
          { label: "Plans", href: "/admin/plans" },
          { label: "Create" }
        ]}
      />
      <PlanForm />
    </div>
  );
}
