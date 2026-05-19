import { Search } from "lucide-react";

import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

type Column<T> = {
  key: string;
  header: string;
  render: (row: T) => React.ReactNode;
};

export function DataTable<T>({
  title,
  search,
  onSearchChange,
  columns,
  rows,
  empty
}: {
  title: string;
  search?: string;
  onSearchChange?: (value: string) => void;
  columns: Column<T>[];
  rows: T[];
  empty: React.ReactNode;
}) {
  return (
    <Card className="overflow-hidden">
      <CardHeader className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <CardTitle>{title}</CardTitle>
        {typeof search === "string" && onSearchChange ? (
          <div className="relative w-full max-w-sm">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input className="rounded-full bg-secondary pl-10 shadow-none" placeholder="Tìm kiếm..." value={search} onChange={(event) => onSearchChange(event.target.value)} />
          </div>
        ) : null}
      </CardHeader>
      <CardContent className="px-0 pb-0">
        {rows.length ? (
          <Table>
            <TableHeader>
              <TableRow>
                {columns.map((column) => (
                  <TableHead key={column.key}>{column.header}</TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row, rowIndex) => (
                <TableRow key={rowIndex}>
                  {columns.map((column) => (
                    <TableCell key={column.key}>{column.render(row)}</TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        ) : (
          empty
        )}
      </CardContent>
    </Card>
  );
}
