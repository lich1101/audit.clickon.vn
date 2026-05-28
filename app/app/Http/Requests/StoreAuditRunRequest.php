<?php

namespace App\Http\Requests;

use App\Models\AuditRun;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditRunRequest extends FormRequest
{
    private const MAX_TARGET_URLS = 1000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('workflow') && $this->filled('action')) {
            $this->merge([
                'workflow' => $this->input('action'),
            ]);
        }

        if (! $this->filled('callbackUrl') && $this->filled('callback_url')) {
            $this->merge([
                'callbackUrl' => $this->input('callback_url'),
            ]);
        }

        if (! $this->filled('startFromStep') && $this->filled('start_from_step')) {
            $this->merge([
                'startFromStep' => $this->input('start_from_step'),
            ]);
        }

        if (! $this->filled('stopAfterStep') && $this->filled('stop_after_step')) {
            $this->merge([
                'stopAfterStep' => $this->input('stop_after_step'),
            ]);
        }

        $startFromStep = $this->input('startFromStep');
        $stopAfterStep = $this->input('stopAfterStep');

        if (is_string($startFromStep)) {
            $normalized = strtolower(trim($startFromStep));

            if (in_array($normalized, ['step2', '2'], true)) {
                $this->merge(['startFromStep' => 2]);
            }

            if (in_array($normalized, ['step3', '3'], true)) {
                $this->merge(['startFromStep' => 3]);
            }
        }

        if (is_string($stopAfterStep)) {
            $normalized = strtolower(trim($stopAfterStep));

            if (in_array($normalized, ['step2', 'step2_only', '2'], true)) {
                $this->merge(['stopAfterStep' => 2]);
            }

            if (in_array($normalized, ['step3', '3'], true)) {
                $this->merge(['stopAfterStep' => 3]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'websiteId' => ['required', 'string'],
            'websiteName' => ['nullable', 'string', 'max:255'],
            'websiteUrl' => ['nullable', 'string', 'max:2048'],
            'workflow' => ['nullable', 'string', 'in:'.implode(',', AuditRun::WORKFLOWS)],
            'action' => ['nullable', 'string', 'in:'.implode(',', AuditRun::WORKFLOWS)],
            'callbackUrl' => ['nullable', 'string', 'max:2048'],
            'callback_url' => ['nullable', 'string', 'max:2048'],
            'startFromStep' => ['nullable', 'integer', 'in:2,3'],
            'start_from_step' => ['nullable', 'integer', 'in:2,3'],
            'stopAfterStep' => ['nullable', 'integer', 'in:2,3'],
            'stop_after_step' => ['nullable', 'integer', 'in:2,3'],
            'targetUrls' => ['required', 'array', 'min:1', 'max:'.self::MAX_TARGET_URLS],
            'targetUrls.*' => ['required', 'string', 'max:2048'],
            'categories' => ['nullable', 'array', 'max:200'],
            'categories.*.name' => ['required_with:categories', 'string', 'max:255'],
            'categories.*.url' => ['required_with:categories', 'string', 'max:2048'],
            'checklistText' => ['nullable', 'string', 'max:50000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $websiteUrl = $this->input('websiteUrl');
            if (is_string($websiteUrl) && trim($websiteUrl) !== '' && ! $this->isHttpUrl($websiteUrl)) {
                $validator->errors()->add('websiteUrl', 'URL website không hợp lệ.');
            }

            $callbackUrl = $this->input('callbackUrl');
            if (is_string($callbackUrl) && trim($callbackUrl) !== '' && ! $this->isHttpUrl($callbackUrl)) {
                $validator->errors()->add('callbackUrl', 'Callback URL không hợp lệ.');
            }

            $startFromStep = (int) ($this->input('startFromStep') ?? 2);
            $stopAfterStep = $this->input('stopAfterStep');

            if ($stopAfterStep !== null && (int) $stopAfterStep < $startFromStep) {
                $validator->errors()->add('stopAfterStep', 'stopAfterStep phải lớn hơn hoặc bằng startFromStep.');
            }

            foreach ((array) $this->input('targetUrls', []) as $index => $url) {
                if (! is_string($url) || ! $this->isHttpUrl($url)) {
                    $line = ((int) $index) + 1;
                    $value = is_scalar($url) ? (string) $url : '';
                    $validator->errors()->add(
                        "targetUrls.{$index}",
                        "URL mục tiêu dòng {$line} không hợp lệ: {$value}"
                    );
                }
            }

            foreach ((array) $this->input('categories', []) as $index => $category) {
                $url = is_array($category) ? ($category['url'] ?? null) : null;

                if (! is_string($url) || ! $this->isHttpUrl($url)) {
                    $line = ((int) $index) + 1;
                    $value = is_scalar($url) ? (string) $url : '';
                    $validator->errors()->add(
                        "categories.{$index}.url",
                        "URL danh mục dòng {$line} không hợp lệ: {$value}"
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'targetUrls.required' => 'Cần chọn ít nhất một URL để chạy audit.',
            'targetUrls.array' => 'Danh sách URL chạy audit không hợp lệ.',
            'targetUrls.min' => 'Cần chọn ít nhất một URL để chạy audit.',
            'targetUrls.max' => 'Mỗi lần chạy audit tối đa '.self::MAX_TARGET_URLS.' URL.',
            'categories.max' => 'Mỗi audit tối đa 200 danh mục.',
        ];
    }

    private function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('/\s/u', $url)) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }
}
