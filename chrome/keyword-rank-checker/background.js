import {
  clearProxy,
  getActiveProxyAuth,
  resetProxyRotation,
  rotateProxy,
} from "./proxy-rotator.js";

const MANUAL_CAPTCHA_TIMEOUT_MS = 600000;
const manualCaptchaTabs = new Map();

chrome.webRequest.onAuthRequired.addListener(
  (details, callback) => {
    const auth = getActiveProxyAuth();

    if (auth) {
      callback({ authCredentials: auth });
      return;
    }

    callback();
  },
  { urls: ["<all_urls>"] },
  ["asyncBlocking"],
);

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || typeof message !== "object") {
    return false;
  }

  if (message.type === "CLICKON_RANK_ROTATE_PROXY") {
    void rotateProxy(Array.isArray(message.proxyUrls) ? message.proxyUrls : [])
      .then((result) => sendResponse(result))
      .catch((error) => sendResponse({ ok: false, error: error.message }));

    return true;
  }

  if (message.type === "CLICKON_RANK_CLEAR_PROXY") {
    void clearProxy()
      .then(() => {
        resetProxyRotation();
        sendResponse({ ok: true });
      })
      .catch((error) => sendResponse({ ok: false, error: error.message }));

    return true;
  }

  if (message.type === "CLICKON_RANK_FETCH_SERP") {
    void fetch(message.url, {
      method: "GET",
      credentials: "include",
      headers: {
        Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      },
    })
      .then(async (response) => {
        sendResponse({
          ok: response.ok,
          status: response.status,
          url: response.url,
          text: await response.text(),
        });
      })
      .catch((error) => sendResponse({ ok: false, status: 0, url: message.url, text: "", error: error.message }));

    return true;
  }

  if (message.type === "CLICKON_RANK_SUBMIT_CAPTCHA") {
    void fetch(message.actionUrl, {
      method: "POST",
      credentials: "include",
      redirect: "follow",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      },
      body: message.body,
    })
      .then(async (response) => {
        sendResponse({
          ok: response.ok,
          status: response.status,
          url: response.url,
          text: await response.text(),
        });
      })
      .catch((error) => sendResponse({ ok: false, status: 0, url: message.actionUrl, text: "", error: error.message }));

    return true;
  }

  if (message.type === "CLICKON_RANK_OPEN_CAPTCHA_TAB") {
    void chrome.tabs
      .create({ url: message.url, active: true })
      .then((tab) => sendResponse({ ok: true, tabId: tab.id }))
      .catch((error) => sendResponse({ ok: false, error: error.message }));

    return true;
  }

  if (message.type === "CLICKON_RANK_CLOSE_TAB") {
    void chrome.tabs
      .remove(message.tabId)
      .then(() => sendResponse({ ok: true }))
      .catch((error) => sendResponse({ ok: false, error: error.message }));

    return true;
  }

  return false;
});
