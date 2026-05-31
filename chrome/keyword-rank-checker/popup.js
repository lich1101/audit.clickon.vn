document.getElementById("open-runner")?.addEventListener("click", async () => {
  const url = chrome.runtime.getURL("runner.html");
  await chrome.tabs.create({ url });
  window.close();
});
