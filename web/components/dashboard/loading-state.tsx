import { LoaderCircle } from "lucide-react";

export function LoadingState({ title, description }: { title: string; description: string }) {
  return (
    <div className="flex min-h-[50vh] items-center justify-center">
      <div className="flex max-w-md flex-col items-center gap-4 rounded-[22px] border border-border bg-card px-8 py-9 text-center shadow-sm">
        <div className="flex size-12 items-center justify-center rounded-full bg-secondary text-primary">
          <LoaderCircle className="size-6 animate-spin" />
        </div>
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-semibold">{title}</h2>
          <p className="text-sm leading-6 text-muted-foreground">{description}</p>
        </div>
      </div>
    </div>
  );
}
