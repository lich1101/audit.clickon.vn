const WEB_SOURCE = "clickon-web";
const EXT_SOURCE = "clickon-rank-extension";
const PREFS_STORAGE_KEY = "clickon_rank_checker_user_prefs";

let running = false;
let stopRequested = false;

postReady();

window.addEventListener("message", (event) => {
  if (event.source !== window || event.data?.source !== WEB_SOURCE) {
    return;
  }

  if (event.data.type === "CLICKON_RANK_EXTENSION_PING") {
    postReady();
    return;
  }

  if (event.data.type === "CLICKON_RANK_STOP") {
    stopRequested = true;
    postStatus("Đã nhận yêu cầu dừng.");
    return;
  }

  if (event.data.type === "CLICKON_RANK_RUN") {
    if (running) {
      postStatus("Extension đang có phiên check rank khác.");
      return;
    }

    void runRankCheck(event.data.payload);
    return;
  }

  if (event.data.type === "CLICKON_RANK_SYNC_PREFS") {
    void saveExtensionPrefs(event.data.payload || {}, { preserveUpdatedAt: true });
    return;
  }

  if (event.data.type === "CLICKON_RANK_REQUEST_PREFS") {
    void loadExtensionPrefs().then((prefs) => {
      postPrefs(normalizeExtensionPrefs(prefs));
    });
    return;
  }
});

function postReady() {
  window.postMessage({ source: EXT_SOURCE, type: "CLICKON_RANK_EXTENSION_READY", version: 1 }, window.location.origin);
}

async function runRankCheck(payload) {
  running = true;
  stopRequested = false;
  const keywords = Array.isArray(payload.keywords) ? payload.keywords : [];
  const storedPrefs = await loadExtensionPrefs();
  const googleHostRaw = payload.googleHost || storedPrefs.googleHost || "https://www.google.com";
  const settings = {
    runPublicId: payload.runPublicId,
    targetDomain: normalizeDomain(payload.targetDomain),
    pages: clampNumber(payload.pages, 10, 10, 10),
    delayMin: clampNumber(payload.delayMin ?? storedPrefs.delayMin, 1, 120, 4),
    delayMax: Math.max(
      clampNumber(payload.delayMin ?? storedPrefs.delayMin, 1, 120, 4),
      clampNumber(payload.delayMax ?? storedPrefs.delayMax, 1, 180, 9),
    ),
    googleHost: googleHostRaw === "https://www.google.com.vn" ? "https://www.google.com.vn" : "https://www.google.com",
    hl: sanitizeLocalePart(payload.hl ?? storedPrefs.hl, "vi"),
    gl: sanitizeLocalePart(payload.gl ?? storedPrefs.gl, "vn"),
    autoCaptcha: payload.autoCaptcha === true,
  };

  try {
    postStatus(`Bắt đầu check ${keywords.length} keyword.`, 0, keywords.length);

    for (let index = 0; index < keywords.length; index += 1) {
      if (stopRequested) {
        break;
      }

      const keyword = keywords[index];
      postStatus(`Đang check ${keyword.keyword}`, index, keywords.length);
      const result = await checkKeyword(keyword, settings, index + 1, keywords.length);
      const persisted = await postItem(result);
      if (!persisted.ok) {
        throw new Error(persisted.error || "Không thể lưu kết quả keyword vào hệ thống.");
      }
      postStatus(`Đã xử lý ${keyword.keyword}`, index + 1, keywords.length);

      if (!stopRequested && index < keywords.length - 1) {
        await wait(randomBetween(settings.delayMin, settings.delayMax) * 1000);
      }
    }

    postComplete({ stopped: stopRequested, error: null });
  } catch (error) {
    postComplete({ stopped: stopRequested, error: error.message || "Extension run failed." });
  } finally {
    running = false;
    stopRequested = false;
  }
}

async function checkKeyword(keyword, settings, keywordIndex, totalKeywords) {
  const checkedAt = new Date().toISOString();
  let organicRank = 0;

  for (let page = 1; page <= settings.pages; page += 1) {
    if (stopRequested) {
      return buildResult(keyword, checkedAt, { status: "stopped", error: "Stopped by user." });
    }

    const start = (page - 1) * 10;
    const url = buildGoogleSearchUrl(settings, keyword.keyword, start);
    postStatus(`[${keywordIndex}/${totalKeywords}] Google trang ${page}/${settings.pages}: ${keyword.keyword}`, keywordIndex - 1, totalKeywords);

    const response = await fetchSerp(url);
    if (!response.ok) {
      return buildResult(keyword, checkedAt, { status: "error", page, error: response.error || `Google HTTP ${response.status}` });
    }

    let html = response.text;
    let responseUrl = response.url || url;
    let blockedReason = detectBlockedPage(html);

    if (blockedReason && settings.autoCaptcha) {
      const captchaApplied = await trySolveAndSubmitCaptcha(html, responseUrl, settings);
      if (captchaApplied.ok) {
        const retry = await fetchSerp(url);
        html = retry.text;
        responseUrl = retry.url || url;
        blockedReason = detectBlockedPage(html);
      } else {
        return buildResult(keyword, checkedAt, { status: "blocked", page, error: captchaApplied.error || blockedReason });
      }
    }

    if (blockedReason && !settings.autoCaptcha) {
      const manual = await waitForManualCaptcha(url, responseUrl);
      if (manual.ok) {
        html = manual.text;
        responseUrl = manual.url || url;
        blockedReason = detectBlockedPage(html);
      } else {
        return buildResult(keyword, checkedAt, { status: "blocked", page, error: manual.error || blockedReason });
      }
    }

    if (blockedReason) {
      return buildResult(keyword, checkedAt, { status: "blocked", page, error: blockedReason });
    }

    const parsed = parseGoogleResults(html, settings.googleHost);

    for (const item of parsed) {
      organicRank += 1;
      if (domainMatches(item.url, settings.targetDomain)) {
        return buildResult(keyword, checkedAt, {
          status: "found",
          rank: organicRank,
          page,
          matchedUrl: item.url,
          title: item.title,
        });
      }
    }

    if (page < settings.pages) {
      await wait(randomBetween(settings.delayMin, settings.delayMax) * 1000);
    }
  }

  return buildResult(keyword, checkedAt, {
    status: "not_found",
    error: `Không tìm thấy domain trong ${settings.pages * 10} kết quả đầu.`,
  });
}

async function waitForManualCaptcha(searchUrl, blockedPageUrl) {
  postStatus("Google yêu cầu captcha — đang mở tab để bạn giải thủ công.");

  const opened = await sendRuntimeMessage({
    type: "CLICKON_RANK_OPEN_CAPTCHA_TAB",
    url: blockedPageUrl || searchUrl,
  });

  if (!opened?.ok || !opened.tabId) {
    return { ok: false, error: "Không mở được tab captcha." };
  }

  const tabId = opened.tabId;
  const deadline = Date.now() + 600000;

  while (Date.now() < deadline && !stopRequested) {
    await wait(5000);
    const retry = await fetchSerp(searchUrl);

    if (retry.ok && !detectBlockedPage(retry.text)) {
      await sendRuntimeMessage({ type: "CLICKON_RANK_CLOSE_TAB", tabId });
      return { ok: true, text: retry.text, url: retry.url || searchUrl };
    }

    postStatus("Đang chờ bạn giải captcha trong tab trình duyệt...");
  }

  await sendRuntimeMessage({ type: "CLICKON_RANK_CLOSE_TAB", tabId });

  return { ok: false, error: stopRequested ? "Stopped by user." : "Hết thời gian chờ giải captcha thủ công." };
}

async function trySolveAndSubmitCaptcha(html, responseUrl, settings) {
  const captcha = extractGoogleCaptcha(html, responseUrl);

  if (!captcha.websiteKey) {
    return { ok: false, error: "Google yêu cầu captcha nhưng extension không tìm thấy sitekey." };
  }

  for (let attempt = 1; attempt <= 2; attempt += 1) {
    const solved = await requestCaptchaSolve({
      runPublicId: settings.runPublicId,
      websiteUrl: captcha.websiteUrl,
      websiteKey: captcha.websiteKey,
      recaptchaDataSValue: captcha.recaptchaDataSValue || null,
      isInvisible: captcha.isInvisible,
      userAgent: navigator.userAgent,
      cookies: null,
    });

    if (!solved.ok || !solved.solutionToken) {
      if (attempt < 2) continue;
      return { ok: false, error: solved.error || "2captcha không trả token." };
    }

    const submitPayload = buildCaptchaSubmitPayload(html, responseUrl, solved.solutionToken);
    if (!submitPayload) {
      return { ok: false, error: "Không tìm thấy form submit captcha của Google." };
    }

    const submitted = await sendRuntimeMessage({
      type: "CLICKON_RANK_SUBMIT_CAPTCHA",
      actionUrl: submitPayload.actionUrl,
      body: submitPayload.body,
    });

    if (submitted?.ok) {
      return { ok: true };
    }
  }

  return { ok: false, error: "Submit captcha không thành công." };
}

function requestCaptchaSolve(payload) {
  const requestId = `captcha-${Date.now()}-${Math.random().toString(16).slice(2)}`;

  return new Promise((resolve) => {
    const timeout = window.setTimeout(() => {
      window.removeEventListener("message", onResponse);
      resolve({ ok: false, error: "Hết thời gian chờ 2captcha." });
    }, 240000);

    function onResponse(event) {
      if (
        event.source !== window ||
        event.data?.source !== WEB_SOURCE ||
        event.data?.type !== "CLICKON_RANK_CAPTCHA_TASK_RESPONSE" ||
        event.data?.requestId !== requestId
      ) {
        return;
      }

      window.clearTimeout(timeout);
      window.removeEventListener("message", onResponse);
      resolve(event.data.payload || { ok: false, error: "2captcha response rỗng." });
    }

    window.addEventListener("message", onResponse);
    window.postMessage(
      {
        source: EXT_SOURCE,
        type: "CLICKON_RANK_CAPTCHA_TASK_REQUEST",
        requestId,
        payload,
      },
      window.location.origin
    );
  });
}

function fetchSerp(url) {
  return sendRuntimeMessage({ type: "CLICKON_RANK_FETCH_SERP", url });
}

function sendRuntimeMessage(message) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage(message, (response) => {
      if (chrome.runtime.lastError) {
        resolve({ ok: false, error: chrome.runtime.lastError.message });
        return;
      }

      resolve(response);
    });
  });
}

function buildGoogleSearchUrl(settings, keyword, start) {
  const url = new URL("/search", settings.googleHost);
  url.searchParams.set("q", keyword);
  url.searchParams.set("num", "10");
  url.searchParams.set("start", String(start));
  url.searchParams.set("hl", settings.hl);
  url.searchParams.set("gl", settings.gl);
  url.searchParams.set("pws", "0");
  url.searchParams.set("ie", "utf-8");
  url.searchParams.set("oe", "utf-8");
  return url.toString();
}

function parseGoogleResults(html, googleHost) {
  const doc = new DOMParser().parseFromString(html, "text/html");
  const candidates = [];
  const seen = new Set();
  const containers = [...doc.querySelectorAll("div#search div.MjjYud, div#search div.g, div#search div.tF2Cxc, div#search div[data-rpos]")];
  const nodes = containers.length > 0 ? containers : [...doc.querySelectorAll("a[href]")];

  for (const node of nodes) {
    const anchor = node.matches?.("a[href]") ? node : node.querySelector?.("a[href]");
    if (!anchor) continue;

    const rawHref = anchor.getAttribute("href");
    const url = normalizeResultUrl(rawHref, googleHost);
    if (!url || shouldIgnoreResultUrl(url)) continue;

    const titleNode = node.querySelector?.("h3") || anchor.querySelector?.("h3") || anchor;
    const title = compactText(titleNode.textContent || "");
    if (!title || title.length < 2) continue;

    const key = stripUrlHash(url);
    if (seen.has(key)) continue;
    seen.add(key);
    candidates.push({ url, title });
  }

  return candidates;
}

function extractGoogleCaptcha(html, responseUrl) {
  const doc = new DOMParser().parseFromString(html, "text/html");
  const sitekey =
    doc.querySelector("[data-sitekey]")?.getAttribute("data-sitekey") ||
    firstMatch(html, /[?&]k=([^&"']+)/) ||
    firstMatch(html, /sitekey["']?\s*[:=]\s*["']([^"']+)/i);
  const dataS =
    doc.querySelector("[data-s]")?.getAttribute("data-s") ||
    firstMatch(html, /[?&]s=([^&"']+)/) ||
    firstMatch(html, /data-s=["']([^"']+)/i);
  const size = doc.querySelector("[data-size]")?.getAttribute("data-size") || "";

  return {
    websiteUrl: responseUrl,
    websiteKey: sitekey ? decodeURIComponent(sitekey) : "",
    recaptchaDataSValue: dataS ? decodeURIComponent(dataS) : "",
    isInvisible: size === "invisible",
  };
}

function buildCaptchaSubmitPayload(html, responseUrl, token) {
  const doc = new DOMParser().parseFromString(html, "text/html");
  const form = doc.querySelector("form");
  if (!form) return null;

  const actionUrl = new URL(form.getAttribute("action") || responseUrl, responseUrl).toString();
  const body = new URLSearchParams();

  form.querySelectorAll("input[name], textarea[name], select[name]").forEach((input) => {
    const name = input.getAttribute("name");
    if (!name) return;
    body.set(name, input.value || input.getAttribute("value") || "");
  });

  body.set("g-recaptcha-response", token);
  body.set("submit", "Submit");

  return { actionUrl, body: body.toString() };
}

function detectBlockedPage(html) {
  const lower = html.toLowerCase();
  if (lower.includes("/sorry/index") || lower.includes("our systems have detected unusual traffic")) {
    return "Google chặn unusual traffic/captcha.";
  }
  if (lower.includes("g-recaptcha") || lower.includes("recaptcha")) {
    return "Google yêu cầu captcha.";
  }
  if (lower.includes("before you continue to google") || lower.includes("consent.google")) {
    return "Google yêu cầu consent/cookie.";
  }
  if (!lower.includes("id=\"search\"") && !lower.includes("id=search")) {
    return "Không thấy vùng kết quả Google; có thể bị chặn hoặc Google đổi giao diện.";
  }
  return "";
}

function buildResult(keyword, checkedAt, data) {
  return {
    keywordId: keyword.id,
    keyword: keyword.keyword,
    checkedAt,
    status: data.status,
    rank: data.rank || null,
    page: data.page || null,
    matchedUrl: data.matchedUrl || "",
    title: data.title || "",
    error: data.error || "",
  };
}

function postStatus(message, processed, total) {
  window.postMessage({ source: EXT_SOURCE, type: "CLICKON_RANK_STATUS", payload: { message, processed, total } }, window.location.origin);
}

function postItem(payload) {
  const requestId = `item-${Date.now()}-${Math.random().toString(16).slice(2)}`;

  return new Promise((resolve) => {
    const timeout = window.setTimeout(() => {
      window.removeEventListener("message", onResponse);
      resolve({ ok: false, error: "Hết thời gian chờ hệ thống xác nhận lưu kết quả keyword." });
    }, 60000);

    function onResponse(event) {
      if (
        event.source !== window ||
        event.data?.source !== WEB_SOURCE ||
        event.data?.type !== "CLICKON_RANK_ITEM_RESULT_ACK" ||
        event.data?.requestId !== requestId
      ) {
        return;
      }

      window.clearTimeout(timeout);
      window.removeEventListener("message", onResponse);
      resolve(event.data.payload || { ok: false, error: "Phản hồi lưu kết quả keyword rỗng." });
    }

    window.addEventListener("message", onResponse);
    window.postMessage({ source: EXT_SOURCE, type: "CLICKON_RANK_ITEM_RESULT", requestId, payload }, window.location.origin);
  });
}

function postComplete(payload) {
  window.postMessage({ source: EXT_SOURCE, type: "CLICKON_RANK_COMPLETE", payload }, window.location.origin);
}

function normalizeResultUrl(rawHref, googleHost) {
  if (!rawHref || rawHref === "#" || rawHref.startsWith("javascript:")) return "";

  try {
    const url = new URL(rawHref, googleHost);
    if (isGoogleHost(url.hostname) && url.pathname === "/url") {
      const target = url.searchParams.get("q") || url.searchParams.get("url");
      return target ? new URL(target).toString() : "";
    }
    if (url.protocol === "http:" || url.protocol === "https:") {
      return url.toString();
    }
  } catch {
    return "";
  }

  return "";
}

function shouldIgnoreResultUrl(url) {
  try {
    const parsed = new URL(url);
    const host = parsed.hostname.replace(/^www\./, "").toLowerCase();
    if (isGoogleHost(host)) return true;
    if (host === "youtube.com" || host.endsWith(".youtube.com")) return true;
    if (host === "youtu.be") return true;
    if (host === "gstatic.com" || host.endsWith(".gstatic.com")) return true;
    if (host === "googleusercontent.com" || host.endsWith(".googleusercontent.com")) return true;
    if (host === "googleapis.com" || host.endsWith(".googleapis.com")) return true;
    if (parsed.pathname.startsWith("/search")) return true;
    return false;
  } catch {
    return true;
  }
}

function isGoogleHost(hostname) {
  const host = hostname.replace(/^www\./, "").toLowerCase();
  return host === "google.com" || host === "google.com.vn" || host.endsWith(".google.com") || host.endsWith(".google.com.vn");
}

function domainMatches(resultUrl, targetDomain) {
  try {
    const host = new URL(resultUrl).hostname.replace(/^www\./, "").toLowerCase();
    const target = targetDomain.replace(/^www\./, "").toLowerCase();
    return host === target || host.endsWith(`.${target}`);
  } catch {
    return false;
  }
}

function normalizeDomain(value) {
  const raw = String(value || "").trim().toLowerCase();
  if (!raw) return "";
  try {
    const url = new URL(raw.includes("://") ? raw : `https://${raw}`);
    return url.hostname.replace(/^www\./, "");
  } catch {
    return raw.replace(/^https?:\/\//, "").replace(/^www\./, "").split("/")[0];
  }
}

function firstMatch(value, regexp) {
  const match = value.match(regexp);
  return match?.[1] || "";
}

function clampNumber(value, min, max, fallback) {
  const number = Number.parseInt(value, 10);
  if (!Number.isFinite(number)) return fallback;
  return Math.min(max, Math.max(min, number));
}

function sanitizeLocalePart(value, fallback) {
  const cleaned = String(value || "").toLowerCase().replace(/[^a-z-]/g, "");
  return cleaned || fallback;
}

function randomBetween(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function compactText(value) {
  return String(value || "").replace(/\s+/g, " ").trim();
}

function stripUrlHash(url) {
  try {
    const parsed = new URL(url);
    parsed.hash = "";
    return parsed.toString();
  } catch {
    return url;
  }
}

chrome.storage.onChanged.addListener((changes, area) => {
  if (area !== "local" || !changes[PREFS_STORAGE_KEY]) {
    return;
  }

  postPrefs(normalizeExtensionPrefs(changes[PREFS_STORAGE_KEY].newValue || {}));
});

function postPrefs(prefs) {
  window.postMessage({ source: EXT_SOURCE, type: "CLICKON_RANK_PREFS", payload: prefs }, window.location.origin);
}

const DEFAULT_EXTENSION_PREFS = {
  delayMin: 4,
  delayMax: 9,
  autoCaptcha: false,
  googleHost: "https://www.google.com",
  hl: "vi",
  gl: "vn",
  updatedAt: null,
};

function normalizeExtensionPrefs(raw) {
  const prefs = { ...DEFAULT_EXTENSION_PREFS, ...(raw || {}) };
  const delayMin = clampNumber(prefs.delayMin, 1, 120, DEFAULT_EXTENSION_PREFS.delayMin);
  const delayMax = Math.max(delayMin, clampNumber(prefs.delayMax, 1, 180, DEFAULT_EXTENSION_PREFS.delayMax));
  const googleHostRaw = String(prefs.googleHost || DEFAULT_EXTENSION_PREFS.googleHost).trim();

  return {
    delayMin,
    delayMax,
    autoCaptcha: prefs.autoCaptcha === true,
    googleHost: googleHostRaw === "https://www.google.com.vn" ? "https://www.google.com.vn" : "https://www.google.com",
    hl: sanitizeLocalePart(prefs.hl, DEFAULT_EXTENSION_PREFS.hl),
    gl: sanitizeLocalePart(prefs.gl, DEFAULT_EXTENSION_PREFS.gl),
    updatedAt: typeof prefs.updatedAt === "string" && prefs.updatedAt.trim() ? prefs.updatedAt.trim() : null,
  };
}

function loadExtensionPrefs() {
  return new Promise((resolve) => {
    chrome.storage.local.get(PREFS_STORAGE_KEY, (stored) => {
      resolve(normalizeExtensionPrefs(stored[PREFS_STORAGE_KEY] || {}));
    });
  });
}

function saveExtensionPrefs(prefs, options = {}) {
  const preserveUpdatedAt = options.preserveUpdatedAt === true;
  const normalized = normalizeExtensionPrefs({
    ...prefs,
    updatedAt: preserveUpdatedAt && prefs?.updatedAt ? prefs.updatedAt : new Date().toISOString(),
  });

  return new Promise((resolve) => {
    chrome.storage.local.set({ [PREFS_STORAGE_KEY]: normalized }, () => resolve(normalized));
  });
}
