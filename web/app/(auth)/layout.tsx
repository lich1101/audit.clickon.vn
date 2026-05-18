export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <main className="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(79,70,229,0.18),transparent_28%),radial-gradient(circle_at_70%_20%,rgba(16,185,129,0.12),transparent_20%),linear-gradient(180deg,#f8fafc,#eef2ff)] dark:bg-[radial-gradient(circle_at_top_left,rgba(79,70,229,0.24),transparent_28%),radial-gradient(circle_at_70%_20%,rgba(16,185,129,0.18),transparent_24%),linear-gradient(180deg,#020617,#0f172a)]">
      <div className="content-shell grid min-h-screen items-center gap-10 py-10 lg:grid-cols-[1.05fr_0.95fr]">
        <section className="hidden lg:block">
          <div className="premium-surface overflow-hidden p-10">
            <p className="text-sm uppercase tracking-[0.3em] text-primary">Credit-centered audit ops</p>
            <h1 className="mt-6 max-w-xl text-5xl font-semibold leading-tight text-balance">
              Clickon Audit giúp đội vận hành website kiểm soát credit, gói cước và audit theo thời gian thực.
            </h1>
            <p className="mt-6 max-w-2xl text-base leading-8 text-muted-foreground">
              Thiết kế dạng SaaS control room với Firebase realtime cho credit balance, Laravel API cho giao dịch tín dụng,
              và luồng quản lý website/audit tách page rõ ràng để triển khai production.
            </p>
            <div className="mt-10 grid gap-4 md:grid-cols-3">
              {[
                "Realtime credit listener",
                "Manual approval for plans",
                "Website & category audit forms"
              ].map((item) => (
                <div key={item} className="rounded-[28px] border border-border/70 bg-card/70 p-5 text-sm shadow-sm">
                  {item}
                </div>
              ))}
            </div>
          </div>
        </section>

        <section className="flex justify-center">{children}</section>
      </div>
    </main>
  );
}
