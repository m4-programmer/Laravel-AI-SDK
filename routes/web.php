<?php

use App\Services\CoaOrchestrator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/coa-test', function (CoaOrchestrator $orchestrator) {

    $payload = [
        "message" => "Under \"Cash and Cash Equivalents\" (code 1010), add a child account \"Branch float — Ikeja\" for small local expenses.",
        "active_class_tab" => "assets",
        "organization_type" => "hotel",
        "region" => "NG",
        "coa_context" => [
            "account_class" => [
                [
                    "account_class_id" => "assets",
                    "accounts" => [
                        [
                            "id" => null,
                            "code" => "1000",
                            "name" => "Current Assets",
                            "is_ai_generated" => false,
                            "children" => [
                                [
                                    "id" => null,
                                    "code" => "1010",
                                    "name" => "Cash and Cash Equivalents",
                                    "is_ai_generated" => false,
                                    "children" => [
                                        [
                                            "id" => 9001,
                                            "code" => "1011",
                                            "name" => "Petty Cash",
                                            "is_ai_generated" => true,
                                            "children" => []
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ],
        "load_hierarchy_from_db" => false,
        "locked_entity_keys" => ["code:1000", "code:1010"],
        "restrict_destructive_to_ai_generated" => false
    ];

    try {
        $result = $orchestrator->handle($payload);

        return response()->json([
            'success' => true,
            'data' => $result
        ]);

    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
});

