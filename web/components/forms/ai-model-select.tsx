"use client";

import { useEffect, useState } from "react";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { fetchAiModels, resolveAiModelValue } from "@/lib/ai-models";
import type { AiProvider } from "@/types";

export function AiModelSelect({
  provider,
  value,
  onChange,
  id = "aiModel",
  label = "Model",
  description,
  allowCustomInput = false
}: {
  provider: AiProvider;
  value?: string | null;
  onChange: (model: string) => void;
  id?: string;
  label?: string;
  description?: string;
  allowCustomInput?: boolean;
}) {
  const [loading, setLoading] = useState(true);
  const [catalog, setCatalog] = useState<Awaited<ReturnType<typeof fetchAiModels>> | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        setLoading(true);
        setError(null);
        const nextCatalog = await fetchAiModels(provider);
        if (cancelled) {
          return;
        }

        setCatalog(nextCatalog);
        const resolved = resolveAiModelValue(nextCatalog, value);
        if ((!value || value.trim() === "") && resolved) {
          onChange(resolved);
        }
      } catch (loadError) {
        if (!cancelled) {
          setError(loadError instanceof Error ? loadError.message : "Không thể tải danh sách model.");
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void load();

    return () => {
      cancelled = true;
    };
  }, [provider]);

  const selected = resolveAiModelValue(catalog, value);
  const options = catalog?.models ?? [];
  const mergedOptions =
    selected && !options.some((model) => model.id === selected)
      ? [{ id: selected, label: selected }, ...options]
      : options;

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Select
        value={selected || undefined}
        onValueChange={onChange}
        disabled={loading || mergedOptions.length === 0}
      >
        <SelectTrigger id={id}>
          <SelectValue placeholder={loading ? "Đang tải model..." : "Chọn model"} />
        </SelectTrigger>
        <SelectContent>
          {mergedOptions.map((model) => (
            <SelectItem key={model.id} value={model.id}>
              {model.label}
              {model.default ? " (mặc định)" : ""}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      {allowCustomInput ? (
        <Input
          id={`${id}-custom`}
          value={value ?? ""}
          onChange={(event) => onChange(event.target.value)}
          placeholder={`Nhập model id ${provider}`}
        />
      ) : null}
      <p className="text-xs text-muted-foreground">
        {error
          ? error
          : description ?? (catalog ? `Nguồn: ${catalog.source === "api" ? "API provider" : catalog.source === "config" ? "cấu hình hệ thống" : "danh sách dự phòng"}` : "Đang tải...")}
      </p>
    </div>
  );
}
