const STORAGE_KEY = "clickon_rank_checker_user_prefs";

const defaults = {
  delayMin: 4,
  delayMax: 9,
  autoCaptcha: false,
  googleHost: "https://www.google.com",
  hl: "vi",
  gl: "vn",
};

const form = document.getElementById("settings-form");
const saveStatus = document.getElementById("save-status");

async function loadPrefs() {
  const stored = await chrome.storage.local.get(STORAGE_KEY);
  return { ...defaults, ...(stored[STORAGE_KEY] || {}) };
}

async function savePrefs(prefs) {
  await chrome.storage.local.set({ [STORAGE_KEY]: prefs });
}

function bindForm(prefs) {
  document.getElementById("delay-min").value = String(prefs.delayMin);
  document.getElementById("delay-max").value = String(prefs.delayMax);
  document.getElementById("google-host").value = prefs.googleHost;
  document.getElementById("hl").value = prefs.hl;
  document.getElementById("gl").value = prefs.gl;
  document.getElementById("auto-captcha").checked = Boolean(prefs.autoCaptcha);
}

function readForm() {
  const delayMin = Math.max(1, Number.parseInt(document.getElementById("delay-min").value, 10) || defaults.delayMin);
  const delayMax = Math.max(delayMin, Number.parseInt(document.getElementById("delay-max").value, 10) || defaults.delayMax);

  return {
    delayMin,
    delayMax,
    autoCaptcha: document.getElementById("auto-captcha").checked,
    googleHost: document.getElementById("google-host").value || defaults.googleHost,
    hl: document.getElementById("hl").value.trim() || defaults.hl,
    gl: document.getElementById("gl").value.trim() || defaults.gl,
  };
}

void loadPrefs().then(bindForm);

form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const prefs = readForm();
  await savePrefs(prefs);
  if (saveStatus) {
    saveStatus.hidden = false;
    window.setTimeout(() => {
      saveStatus.hidden = true;
    }, 1800);
  }
});

document.getElementById("open-runner")?.addEventListener("click", async () => {
  await chrome.tabs.create({ url: chrome.runtime.getURL("runner.html") });
  window.close();
});
