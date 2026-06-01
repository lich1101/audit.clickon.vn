/** Mỗi dòng một proxy: host:port hoặc http://user:pass@host:port hoặc socks5://host:port */
export function parseProxyUrlsInput(text: string): string[] {
  const seen = new Set<string>();
  const result: string[] = [];

  for (const line of text.split(/\r?\n/g)) {
    const trimmed = line.trim();
    if (!trimmed) {
      continue;
    }

    const key = trimmed.toLocaleLowerCase("vi-VN");
    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    result.push(trimmed);
  }

  return result.slice(0, 50);
}
