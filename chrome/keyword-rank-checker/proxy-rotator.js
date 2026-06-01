/** @typedef {{ scheme: string, host: string, port: number, username: string, password: string, label: string }} ParsedProxy */

let proxyRotationIndex = 0;
/** @type {{ username: string, password: string } | null} */
let activeProxyAuth = null;

const PROXY_BYPASS_LIST = [
  "<local>",
  "127.0.0.1",
  "localhost",
  "*.clickon.vn",
  "clickon.vn",
  "audit.clickon.vn",
];

/**
 * @param {string} line
 * @returns {ParsedProxy | null}
 */
export function parseProxyLine(line) {
  let raw = String(line || "").trim();
  if (!raw) {
    return null;
  }

  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(raw)) {
    raw = `http://${raw}`;
  }

  try {
    const url = new URL(raw);
    let scheme = url.protocol.replace(":", "").toLowerCase();
    if (scheme === "socks") {
      scheme = "socks5";
    }
    if (!["http", "https", "socks4", "socks5"].includes(scheme)) {
      return null;
    }

    const port = Number.parseInt(url.port, 10) || (scheme === "https" ? 443 : scheme.startsWith("socks") ? 1080 : 80);
    const host = url.hostname;

    if (!host || !Number.isFinite(port) || port < 1 || port > 65535) {
      return null;
    }

    return {
      scheme,
      host,
      port,
      username: decodeURIComponent(url.username || ""),
      password: decodeURIComponent(url.password || ""),
      label: `${host}:${port}`,
    };
  } catch {
    return null;
  }
}

/**
 * @param {string[]} lines
 * @returns {ParsedProxy[]}
 */
export function parseProxyList(lines) {
  const proxies = [];
  const seen = new Set();

  for (const line of lines) {
    const parsed = parseProxyLine(line);
    if (!parsed) {
      continue;
    }

    const key = `${parsed.scheme}://${parsed.host}:${parsed.port}`;
    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    proxies.push(parsed);
  }

  return proxies;
}

/**
 * @param {ParsedProxy} proxy
 */
export async function applyProxy(proxy) {
  await chrome.proxy.settings.set({
    value: {
      mode: "fixed_servers",
      rules: {
        singleProxy: {
          scheme: proxy.scheme,
          host: proxy.host,
          port: proxy.port,
        },
        bypassList: PROXY_BYPASS_LIST,
      },
    },
    scope: "regular",
  });

  activeProxyAuth =
    proxy.username && proxy.password ? { username: proxy.username, password: proxy.password } : null;
}

export async function clearProxy() {
  activeProxyAuth = null;

  await chrome.proxy.settings.clear({ scope: "regular" });
}

/**
 * @param {string[]} proxyUrls
 */
export async function rotateProxy(proxyUrls) {
  const proxies = parseProxyList(proxyUrls);

  if (proxies.length === 0) {
    await clearProxy();
    return { ok: false, error: "Không có proxy hợp lệ trong danh sách." };
  }

  const proxy = proxies[proxyRotationIndex % proxies.length];
  proxyRotationIndex += 1;

  await applyProxy(proxy);

  const rotation = ((proxyRotationIndex - 1) % proxies.length) + 1;

  return {
    ok: true,
    proxy: proxy.label,
    rotation,
    total: proxies.length,
  };
}

export function getActiveProxyAuth() {
  return activeProxyAuth;
}

export function resetProxyRotation() {
  proxyRotationIndex = 0;
  activeProxyAuth = null;
}
