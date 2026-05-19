export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <main className="min-h-screen bg-background">
      <div className="content-shell grid min-h-screen items-center gap-6 py-8 lg:grid-cols-[1.04fr_0.96fr]">
        <section className="hidden lg:block">
          <div className="premium-surface overflow-hidden p-8">
            <p className="text-sm uppercase tracking-[0.3em] text-primary">Credit-centered audit ops</p>
            <h1 className="mt-5 max-w-xl text-4xl font-semibold leading-tight text-balance xl:text-5xl">
              Clickon Audit giúp đội vận hành website kiểm soát credit, gói cước và audit theo thời gian thực.
            </h1>
            <p className="mt-5 max-w-2xl text-base leading-8 text-muted-foreground">
              Thiết kế dạng SaaS control room với Firebase realtime cho credit balance, Laravel API cho giao dịch tín dụng,
              và luồng quản lý website/audit tách page rõ ràng để triển khai production.
            </p>
            <div className="mt-8 grid gap-3 md:grid-cols-3">
              {[
                "Realtime credit listener",
                "Manual approval for plans",
                "Website & category audit forms"
              ].map((item) => (
                <div key={item} className="mail-row rounded-xl border border-border/70 bg-background/70 p-4 text-sm shadow-sm">
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
