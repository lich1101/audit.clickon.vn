import { Badge } from "@/components/ui/badge";

export function RoleBadge({ role }: { role: "admin" | "user" }) {
  return <Badge variant={role === "admin" ? "default" : "secondary"}>{role}</Badge>;
}
