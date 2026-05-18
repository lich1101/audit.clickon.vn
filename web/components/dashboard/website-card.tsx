import Link from "next/link";
import { ArrowUpRight, FileSearch, Globe } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate } from "@/lib/utils";
import type { Website } from "@/types";

export function WebsiteCard({ website }: { website: Website }) {
  return (
    <Card className="h-full">
      <CardHeader className="space-y-4">
        <div className="flex size-12 items-center justify-center rounded-3xl bg-primary/10 text-primary">
          <Globe className="size-5" />
        </div>
        <div>
          <CardTitle>{website.name}</CardTitle>
          <p className="mt-2 truncate text-sm text-muted-foreground">{website.url}</p>
        </div>
      </CardHeader>
      <CardContent className="flex items-center gap-2 text-sm text-muted-foreground">
        <FileSearch className="size-4" />
        Tạo lúc {formatDate(website.createdAt)}
      </CardContent>
      <CardFooter className="justify-between">
        <Button asChild variant="secondary">
          <Link href={`/websites/${website.id}`}>Chi tiết</Link>
        </Button>
        <Button asChild variant="ghost" size="icon">
          <Link href={`/websites/${website.id}/audit`}>
            <ArrowUpRight className="size-4" />
          </Link>
        </Button>
      </CardFooter>
    </Card>
  );
}
