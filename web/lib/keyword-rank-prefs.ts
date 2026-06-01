import type { KeywordRankPreferences } from "@/types";

export const DEFAULT_KEYWORD_RANK_PREFS: KeywordRankPreferences = {
  delayMin: 4,
  delayMax: 9,
  autoCaptcha: false,
  googleHost: "https://www.google.com",
  hl: "vi",
  gl: "vn",
  updatedAt: null,
};

export function parsePreferenceTimestamp(preferences: Pick<KeywordRankPreferences, "updatedAt">) {
  const parsed = Date.parse(preferences.updatedAt ?? "");
  return Number.isFinite(parsed) ? parsed : 0;
}

export function keywordRankPreferencesEqual(left: KeywordRankPreferences, right: KeywordRankPreferences) {
  return (
    left.delayMin === right.delayMin &&
    left.delayMax === right.delayMax &&
    left.autoCaptcha === right.autoCaptcha &&
    left.googleHost === right.googleHost &&
    left.hl === right.hl &&
    left.gl === right.gl &&
    (left.updatedAt ?? null) === (right.updatedAt ?? null)
  );
}

export function reconcileKeywordRankPreferences(
  webPrefs: KeywordRankPreferences,
  extensionPrefs: KeywordRankPreferences
): { preferences: KeywordRankPreferences; source: "web" | "extension" | "equal" } {
  if (keywordRankPreferencesEqual(webPrefs, extensionPrefs)) {
    return { preferences: webPrefs, source: "equal" };
  }

  const webTime = parsePreferenceTimestamp(webPrefs);
  const extensionTime = parsePreferenceTimestamp(extensionPrefs);

  if (extensionTime > webTime) {
    return { preferences: extensionPrefs, source: "extension" };
  }

  if (webTime > extensionTime) {
    return { preferences: webPrefs, source: "web" };
  }

  return { preferences: webPrefs, source: "web" };
}
