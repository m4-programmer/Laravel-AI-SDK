<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\COAChatFeedbackRequest;
use App\Http\Requests\COAChatRequest;
use App\Http\Requests\COAChildrenRequest;
use App\Http\Requests\COAChildrenSingleRequest;
use App\Http\Requests\COALinkedRequest;
use App\Http\Requests\COASuggestionsRequest;
use App\Services\Coa\CoaChatService;
use App\Services\Coa\CoaChildrenGeneratorService;
use App\Services\Coa\CoaGeneratorService;
use App\Services\Coa\CoaLinkedGeneratorService;
use App\Services\Coa\CoaRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * COA HTTP surface; extend `services/coa_*.py` in Python. Extra routes: POST
 * `/assistant/coa/suggestions` and `/assistant/coa/linked` expose services that
 * are not in FastAPI `routes.py` (they exist as Python services only).
 * Apply `X-Service-Auth` middleware on protected routes the same as FastAPI.
 */
class CoaController extends Controller
{
    public function __construct(
        private CoaRepository $coaRepository,
        private CoaChildrenGeneratorService $coaChildren,
        private CoaGeneratorService $coaGenerator,
        private CoaLinkedGeneratorService $coaLinked,
    ) {}

    public function getCoaHierarchy(): JsonResponse
    {
        $hierarchy = $this->coaRepository->getFullCoaHierarchy();
        $classes = $hierarchy['classes'] ?? [];

        return response()->json([
            'hierarchy' => $hierarchy,
            'classes_count' => count($classes),
        ]);
    }

    public function coaChildrenPost(COAChildrenRequest $request): JsonResponse
    {
        $h = $request->validated();
        $result = $this->coaChildren->generateChildren(
            (string) $h['organization_type'],
            (string) $h['region'],
            (int) $h['suggestions_per_detail'],
            $h['class_filter'] ?? null
        );

        return response()->json($result);
    }

    public function coaChildrenGet(string $organizationType, Request $request): JsonResponse
    {
        $data = $request->validate([
            'region' => 'sometimes|string',
            'suggestions_per_detail' => 'sometimes|integer|min:1|max:10',
            'class_filter' => 'nullable|string',
        ]);
        $region = $data['region'] ?? 'NG';
        $suggestionsPerDetail = min(max((int) ($data['suggestions_per_detail'] ?? 3), 1), 10);

        $result = $this->coaChildren->generateChildren(
            $organizationType,
            $region,
            $suggestionsPerDetail,
            $data['class_filter'] ?? null
        );

        return response()->json($result);
    }

    public function coaChildrenForDetail(
        string $organizationType,
        int $detailId,
        Request $request
    ): JsonResponse {
        $data = $request->validate([
            'region' => 'sometimes|string',
            'suggestions_count' => 'sometimes|integer|min:1|max:10',
        ]);
        $region = $data['region'] ?? 'NG';
        $suggestionsCount = min(max((int) ($data['suggestions_count'] ?? 3), 1), 10);

        $result = $this->coaChildren->generateChildrenForSingleDetail(
            $detailId,
            $organizationType,
            $region,
            $suggestionsCount
        );

        return response()->json($result);
    }

    public function coaChildrenSinglePost(
        int $detailId,
        COAChildrenSingleRequest $request
    ): JsonResponse {
        $h = $request->validated();
        $result = $this->coaChildren->generateChildrenForSingleDetail(
            $detailId,
            (string) $h['organization_type'],
            (string) $h['region'],
            (int) $h['suggestions_count']
        );

        return response()->json($result);
    }

    public function coaSuggestions(COASuggestionsRequest $request): JsonResponse
    {
        $h = $request->validated();
        $result = $this->coaGenerator->generateCoaSuggestions(
            (string) $h['organization_type'],
            (string) $h['region'],
            (int) $h['suggestions_per_class']
        );

        return response()->json($result);
    }

    public function coaLinked(COALinkedRequest $request): JsonResponse
    {
        $h = $request->validated();
        $result = $this->coaLinked->generateLinkedCoa(
            (string) $h['organization_type'],
            (string) $h['region'],
            (int) $h['suggestions_per_type']
        );

        return response()->json($result);
    }


    public function coaChatFeedback(COAChatFeedbackRequest $request): JsonResponse
    {
        $v = $request->validated();
        $id = (string) Str::uuid();

        Log::info('coa_chat_feedback', [
            'feedback_id' => $id,
            'rating' => $v['rating'] ?? null,
            'conversation_id' => $v['conversation_id'] ?? null,
            'message_id' => $v['message_id'] ?? null,
            'tenant_id' => $v['tenant_id'] ?? null,
        ]);

        return response()->json(['status' => 'recorded', 'feedback_id' => $id]);
    }
}
