import { LoaderCircle } from "lucide-react";

export function LoadingState({ title, description }: { title: string; description: string }) {
  return (
    <div className="flex min-h-[50vh] items-center justify-center">
      <div className="premium-surface flex max-w-md flex-col items-center gap-4 p-10 text-center">
        <div className="flex size-14 items-center justify-center rounded-3xl bg-primary/10 text-primary">
          <LoaderCircle className="size-6 animate-spin" />
        </div>
        <div className="space-y-1">
          <h2 className="text-lg font-semibold">{title}</h2>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
      </div>
    </div>
  );
}
