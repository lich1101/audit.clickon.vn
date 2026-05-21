import type { Metadata } from "next";

import "@/app/globals.css";

import { Providers } from "@/components/layout/providers";

export const metadata: Metadata = {
  title: "Clickon Audit",
  description: "SaaS quản lý credit và audit website với Laravel API/MySQL, Firebase signal cho audit realtime.",
  icons: {
    icon: "/favicon.svg"
  }
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="vi" suppressHydrationWarning>
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
