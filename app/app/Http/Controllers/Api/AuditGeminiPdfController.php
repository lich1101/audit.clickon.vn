<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditGeminiPdfAttachmentService;
use Illuminate\Http\Request;
use RuntimeException;

class AuditGeminiPdfController extends Controller
{
    public function __construct(
        private readonly AuditGeminiPdfAttachmentService $geminiPdfAttachmentService,
    ) {
    }

    public function upload(Request $request, string $slot)
    {
        $validated = $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            $attachment = $this->geminiPdfAttachmentService->upload($slot, $validated['pdf']);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $attachment,
        ]);
    }

    public function destroy(string $slot)
    {
        try {
            $this->geminiPdfAttachmentService->delete($slot);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => [
                'slot' => $slot,
                'deleted' => true,
            ],
        ]);
    }
}
