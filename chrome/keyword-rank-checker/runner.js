const STORAGE_KEY = "clickon_rank_checker_settings";
const RESULTS_KEY = "clickon_rank_checker_results";

const els = {
  targetDomain: document.getElementById("target-domain"),
  pages: document.getElementById("pages"),
  googleHost: document.getElementById("google-host"),
  gl: document.getElementById("gl"),
  hl: document.getElementById("hl"),
  delayMin: document.getElementById("delay-min"),
  delayMax: document.getElementById("delay-max"),
  callbackUrl: document.getElementById("callback-url"),
  keywords: document.getElementById("keywords"),
  start: document.getElementById("start"),
  stop: document.getElementById("stop"),
  exportCsv: document.getElementById("export-csv"),
  clearResults: document.getElementById("clear-results"),
  summaryState: document.getElementById("summary-state"),
  summaryDetail: document.getElementById("summary-detail"),
  progressText: document.getElementById("progress-text"),
  progressCount: document.getElementById("progress-count"),
  progressBar: document.getElementById("progress-bar"),
  log: document.getElementById("log"),
  resultsBody: document.getElementById("results-body"),
  resultCount: document.getElementById("result-count"),
};

const state = {
  running: false,
  stopRequested: false,
  results: [],
};

init();

async function init() {
  await loadState();
  bindEvents();
  renderResults();
  updateProgress(0, state.results.length, "Sẵn sàng");
}

function bindEvents() {
  els.start.addEventListener("click", startRun);
  els.stop.addEventListener("click", () => {
    state.stopRequested = true;
    appendLog("Đã yêu cầu dừng. Công cụ sẽ dừng sau request hiện tại.");
  });
  els.exportCsv.addEventListener("click", exportCsv);
  els.clearResults.addEventListener("click", async () => {
    if (state.running) return;
    state.results = [];
    await chrome.storage.local.remove(RESULTS_KEY);
    renderResults();
    updateSummary("Sẵn sàng", "Đã xóa kết quả cũ.");
  });
}

async function loadState() {
  const stored = await chrome.storage.local.get([STORAGE_KEY, RESULTS_KEY]);
  const settings = stored[STORAGE_KEY] || {};
  els.targetDomain.value = settings.targetDomain || "";
  els.pages.value = String(settings.pages || 10);
  els.googleHost.value = settings.googleHost || "https://www.google.com";
  els.gl.value = settings.gl || "vn";
  els.hl.value = settings.hl || "vi";
  els.delayMin.value = String(settings.delayMin || 4);
  els.delayMax.value = String(settings.delayMax || 9);
  els.callbackUrl.value = settings.callbackUrl || "";
  els.keywords.value = settings.keywords || "";
  state.results = Array.isArray(stored[RESULTS_KEY]) ? stored[RESULTS_KEY] : [];
}

async function saveSettings(settings) {
  await chrome.storage.local.set({ [STORAGE_KEY]: settings });
}

async function saveResults() {
  await chrome.storage.local.set({ [RESULTS_KEY]: state.results });
}

function readSettings() {
  const targetDomain = normalizeDomain(els.targetDomain.value);
  const keywords = uniqueLines(els.keywords.value);
  const pages = clampNumber(els.pages.value, 10, 10, 10);
  const delayMin = clampNumber(els.delayMin.value, 1, 120, 4);
  const delayMax = Math.max(delayMin, clampNumber(els.delayMax.value, 1, 180, 9));
  const googleHost = els.googleHost.value === "https://www.google.com.vn"
    ? "https://www.google.com.vn"
    : "https://www.google.com";

  return {
    targetDomain,
    keywords,
    pages,
    googleHost,
    gl: sanitizeLocalePart(els.gl.value, "vn"),
    hl: sanitizeLocalePart(els.hl.value, "vi"),
    delayMin,
    delayMax,
    callbackUrl: els.callbackUrl.value.trim(),
  };
}

async function startRun() {
  if (state.running) return;

  const settings = readSettings();
  if (!settings.targetDomain) {
    appendLog("Thiếu domain cần tìm.");
    els.targetDomain.focus();
    return;
  }
  if (settings.keywords.length === 0) {
    appendLog("Thiếu danh sách keyword.");
    els.keywords.focus();
    return;
  }

  await saveSettings({ ...settings, keywords: els.keywords.value });
  state.running = true;
  state.stopRequested = false;
  state.results = [];
  setControlsRunning(true);
  renderResults();
  updateSummary("Đang chạy", `${settings.keywords.length} keyword, tối đa ${settings.pages * 10} kết quả/keyword.`);
  appendLog(`Bắt đầu check ${settings.keywords.length} keyword cho domain ${settings.targetDomain}.`);

  try {
    for (let index = 0; index < settings.keywords.length; index += 1) {
      if (state.stopRequested) {
        appendLog("Đã dừng theo yêu cầu người dùng.");
        break;
      }

      const keyword = settings.keywords[index];
      updateProgress(index, settings.keywords.length, `Đang check: ${keyword}`);
      const result = await checkKeyword(keyword, settings, index + 1, settings.keywords.length);
      state.results.push(result);
      await saveResults();
      renderResults();
      updateProgress(index + 1, settings.keywords.length, result.status === "found" ? `Tìm thấy: ${keyword}` : `Xong: ${keyword}`);

      if (!state.stopRequested && index < settings.keywords.length - 1) {
        await wait(randomBetween(settings.delayMin, settings.delayMax) * 1000);
      }
    }

    const stopped = state.stopRequested;
    if (settings.callbackUrl && state.results.length > 0) {
      await sendCallback(settings, stopped);
    }
    updateSummary(stopped ? "Đã dừng" : "Hoàn tất", `${state.results.length}/${settings.keywords.length} keyword đã xử lý.`);
  } catch (error) {
    appendLog(`Lỗi phiên chạy: ${error.message}`);
    updateSummary("Lỗi", error.message);
  } finally {
    state.running = false;
    state.stopRequested = false;
    setControlsRunning(false);
    renderResults();
  }
}

async function checkKeyword(keyword, settings, keywordIndex, totalKeywords) {
  const checkedAt = new Date().toISOString();
  let organicRank = 0;

  for (let page = 1; page <= settings.pages; page += 1) {
    if (state.stopRequested) {
      return buildResult(keyword, settings.targetDomain, checkedAt, {
        status: "stopped",
        error: "Stopped by user.",
      });
    }

    const start = (page - 1) * 10;
    const url = buildGoogleSearchUrl(settings, keyword, start);
    appendLog(`[${keywordIndex}/${totalKeywords}] Trang ${page}/${settings.pages}: ${keyword}`);

    try {
      const html = await fetchSerp(url);
      const blockedReason = detectBlockedPage(html);
      if (blockedReason) {
        return buildResult(keyword, settings.targetDomain, checkedAt, {
          status: "blocked",
          page,
          error: blockedReason,
        });
      }

      const parsed = parseGoogleResults(html, settings.googleHost);
      if (parsed.length === 0) {
        appendLog(`Không thấy organic result ở trang ${page}; có thể Google đổi HTML hoặc đang chặn nhẹ.`);
      }

      for (const item of parsed) {
        organicRank += 1;
        if (domainMatches(item.url, settings.targetDomain)) {
          return buildResult(keyword, settings.targetDomain, checkedAt, {
            status: "found",
            rank: organicRank,
            page,
            matchedUrl: item.url,
            title: item.title,
          });
        }
      }
    } catch (error) {
      return buildResult(keyword, settings.targetDomain, checkedAt, {
        status: "error",
        page,
        error: error.message,
      });
    }

    if (page < settings.pages) {
      await wait(randomBetween(settings.delayMin, settings.delayMax) * 1000);
    }
  }

  return buildResult(keyword, settings.targetDomain, checkedAt, {
    status: "not_found",
    error: `Không tìm thấy domain trong ${settings.pages * 10} kết quả đầu.`,
  });
}

async function fetchSerp(url) {
  const response = await fetch(url, {
    method: "GET",
    credentials: "include",
    headers: {
      "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    },
  });

  const text = await response.text();
  if (!response.ok) {
    throw new Error(`Google trả HTTP ${response.status}`);
  }
  return text;
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
  const containers = [
    ...doc.querySelectorAll("div#search div.MjjYud, div#search div.g, div#search div.tF2Cxc, div#search div[data-rpos]"),
  ];
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

function buildResult(keyword, domain, checkedAt, data) {
  return {
    keyword,
    domain,
    checkedAt,
    status: data.status,
    rank: data.rank || null,
    page: data.page || null,
    matchedUrl: data.matchedUrl || "",
    title: data.title || "",
    error: data.error || "",
  };
}

async function sendCallback(settings, stopped) {
  const payload = {
    domain: settings.targetDomain,
    checkedAt: new Date().toISOString(),
    stopped,
    source: "chrome_extension",
    search: {
      googleHost: settings.googleHost,
      pages: settings.pages,
      hl: settings.hl,
      gl: settings.gl,
    },
    results: state.results,
  };

  try {
    const response = await fetch(settings.callbackUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!response.ok) {
      appendLog(`Callback lỗi HTTP ${response.status}. Kết quả vẫn được lưu local.`);
      return;
    }
    appendLog("Callback kết quả thành công.");
  } catch (error) {
    appendLog(`Callback thất bại: ${error.message}. Kết quả vẫn được lưu local.`);
  }
}

function renderResults() {
  els.exportCsv.disabled = state.results.length === 0;
  els.resultCount.textContent = `${state.results.length} keyword`;

  if (state.results.length === 0) {
    els.resultsBody.innerHTML = '<tr><td colspan="8" class="empty">Chưa có dữ liệu.</td></tr>';
    return;
  }

  els.resultsBody.innerHTML = state.results.map((result, index) => {
    const statusClass = `status-${result.status.replace("_", "-")}`;
    const rank = result.rank ? String(result.rank) : "-";
    const page = result.page ? String(result.page) : "-";
    const url = result.matchedUrl
      ? `<a href="${escapeAttr(result.matchedUrl)}" target="_blank" rel="noreferrer">${escapeHtml(result.matchedUrl)}</a>`
      : "-";

    return `
      <tr>
        <td>${index + 1}</td>
        <td>${escapeHtml(result.keyword)}</td>
        <td><span class="status ${statusClass}">${statusLabel(result.status)}</span></td>
        <td>${rank}</td>
        <td>${page}</td>
        <td>${url}</td>
        <td>${escapeHtml(result.title || "-")}</td>
        <td>${escapeHtml(result.error || "-")}</td>
      </tr>
    `;
  }).join("");
}

function statusLabel(status) {
  return {
    found: "Tìm thấy",
    not_found: "Không thấy",
    blocked: "Bị chặn",
    error: "Lỗi",
    stopped: "Đã dừng",
  }[status] || status;
}

function setControlsRunning(running) {
  els.start.disabled = running;
  els.stop.disabled = !running;
  els.clearResults.disabled = running;
  els.exportCsv.disabled = running || state.results.length === 0;
}

function updateProgress(done, total, text) {
  const pct = total > 0 ? Math.round((done / total) * 100) : 0;
  els.progressText.textContent = text;
  els.progressCount.textContent = `${done}/${total}`;
  els.progressBar.style.width = `${pct}%`;
}

function updateSummary(stateText, detail) {
  els.summaryState.textContent = stateText;
  els.summaryDetail.textContent = detail;
}

function appendLog(message) {
  const time = new Date().toLocaleTimeString("vi-VN", { hour12: false });
  els.log.textContent += `[${time}] ${message}\n`;
  els.log.scrollTop = els.log.scrollHeight;
}

function exportCsv() {
  const headers = ["keyword", "domain", "status", "rank", "page", "matched_url", "title", "error", "checked_at"];
  const lines = [
    headers.join(","),
    ...state.results.map((result) => [
      result.keyword,
      result.domain,
      result.status,
      result.rank || "",
      result.page || "",
      result.matchedUrl || "",
      result.title || "",
      result.error || "",
      result.checkedAt || "",
    ].map(csvValue).join(",")),
  ];
  const blob = new Blob([`\uFEFF${lines.join("\n")}`], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
  link.href = url;
  link.download = `clickon-keyword-ranks-${stamp}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function csvValue(value) {
  const text = String(value ?? "");
  return `"${text.replaceAll('"', '""')}"`;
}

function normalizeDomain(value) {
  const raw = value.trim().toLowerCase();
  if (!raw) return "";
  try {
    const url = new URL(raw.includes("://") ? raw : `https://${raw}`);
    return url.hostname.replace(/^www\./, "");
  } catch {
    return raw.replace(/^https?:\/\//, "").replace(/^www\./, "").split("/")[0];
  }
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

function uniqueLines(value) {
  const seen = new Set();
  return value
    .split(/\r?\n/)
    .map((line) => compactText(line))
    .filter(Boolean)
    .filter((line) => {
      const key = line.toLowerCase();
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
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

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function escapeAttr(value) {
  return escapeHtml(value);
}
