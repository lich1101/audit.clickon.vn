const LOCAL_TOKEN_KEY = "clickon_local_token";

export function isLocalAuthEnabled() {
  return process.env.NEXT_PUBLIC_LOCAL_AUTH === "1";
}

export function getLocalAuthToken() {
  if (typeof window === "undefined") {
    return null;
  }

  return window.localStorage.getItem(LOCAL_TOKEN_KEY);
}

export function setLocalAuthToken(token: string) {
  window.localStorage.setItem(LOCAL_TOKEN_KEY, token);
}

export function clearLocalAuthToken() {
  if (typeof window === "undefined") {
    return;
  }

  window.localStorage.removeItem(LOCAL_TOKEN_KEY);
}
