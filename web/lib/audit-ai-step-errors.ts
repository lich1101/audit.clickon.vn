import type { AuditAiStepError } from "@/types";

const GENERIC_ITEM_ERROR_PATTERN =
  /^(Batch AI bước|Batch AI không trả|\[Bước .+\] Batch AI không trả)/i;

export function isGenericBatchItemError(message?: string | null) {
  if (!message?.trim()) {
    return false;
  }

  return GENERIC_ITEM_ERROR_PATTERN.test(message.trim());
}

export function aiStepErrorsForPosition(aiStepErrors: AuditAiStepError[] | undefined, position?: number | null) {
  if (!aiStepErrors?.length || !position) {
    return [];
  }

  return aiStepErrors.filter((entry) => {
    if (entry.positionFrom == null || entry.positionTo == null) {
      return false;
    }

    return position >= entry.positionFrom && position <= entry.positionTo;
  });
}

export function resolveAiStepRowState(input: {
  position?: number | null;
  aiStepErrors?: AuditAiStepError[];
  itemErrorMessage?: string | null;
  status?: string | null;
}) {
  const scopedErrors = aiStepErrorsForPosition(input.aiStepErrors, input.position);
  const hardErrors = scopedErrors.filter((entry) => entry.status !== "needs_json_formatter");
  const formatterPending = scopedErrors.find((entry) => entry.status === "needs_json_formatter");

  let errorMessage = input.itemErrorMessage?.trim() || null;

  if ((!errorMessage || isGenericBatchItemError(errorMessage)) && hardErrors.length > 0) {
    const primary = hardErrors[hardErrors.length - 1];
    const detail = primary.errorMessage || primary.parseError;

    if (detail) {
      errorMessage = `[${primary.stepLabel}] ${detail}`;
    }
  }

  let stageHint: string | null = null;

  if (formatterPending) {
    stageHint = formatterPending.stepLabel.includes("2.5")
      ? "Bước 2.5: đang chuẩn hóa JSON"
      : formatterPending.stepLabel.includes("3.5")
        ? "Bước 3.5: đang chuẩn hóa JSON"
        : `${formatterPending.stepLabel}: đang chuẩn hóa JSON`;
  }

  if (!stageHint && input.status === "analyzing" && hardErrors.length > 0) {
    const latest = hardErrors[hardErrors.length - 1];
    stageHint = `${latest.stepLabel}: ${latest.status ?? "lỗi"}`;
  }

  return {
    errorMessage,
    stageHint,
  };
}

export function collectRunDisplayErrors(input: {
  lastError?: string | null;
  aiStepErrors?: AuditAiStepError[];
  itemErrorMessages?: Array<string | null | undefined>;
  limit?: number;
}) {
  const limit = input.limit ?? 6;
  const seen = new Set<string>();
  const messages: string[] = [];

  function push(message?: string | null) {
    const trimmed = message?.trim();

    if (!trimmed || seen.has(trimmed)) {
      return;
    }

    seen.add(trimmed);
    messages.push(trimmed);
  }

  push(input.lastError);

  for (const entry of input.aiStepErrors ?? []) {
    if (entry.status === "needs_json_formatter" && !entry.parseError) {
      continue;
    }

    const detail = entry.errorMessage || entry.parseError;

    if (detail) {
      push(`[${entry.stepLabel}] ${detail}`);
    }
  }

  for (const message of input.itemErrorMessages ?? []) {
    push(message);
  }

  return messages.slice(0, limit);
}
