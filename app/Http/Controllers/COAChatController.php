<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\COAChatRequest;
use App\Services\Coa\CoaChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class COAChatController extends Controller
{
    public function __construct(private CoaChatService $coaChat,) {}

    public function __invoke(COAChatRequest $request): JsonResponse
    {
        $h = $request->validated();
        $history = $h['conversation_history'] ?? null;

        $result = $this->coaChat->chat(
            message: (string) $h['message'],
            activeClassTab: (string) $h['active_class_tab'],
            branchLabel: $h['branch_label'] ?? null,
            organizationType: (string) $h['organization_type'],
            region: (string) $h['region'],
            coaContext: $h['coa_context'] ?? null,
            lockedEntityKeys: $h['locked_entity_keys'] ?? null,
            loadHierarchyFromDb: $request->boolean('load_hierarchy_from_db'),
            restrictDestructiveToAiGenerated: $request->boolean('restrict_destructive_to_ai_generated'),
            conversationHistory: is_array($history) ? $history : null,
            conversationId: $h['conversation_id'] ?? null,
        );

        return response()->json($result);
    }
}
