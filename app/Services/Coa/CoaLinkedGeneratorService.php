<?php

namespace App\Services\Coa;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Port of `coa_linked_generator.py` — COALinkedGeneratorService::generate_linked_coa.
 */
class CoaLinkedGeneratorService
{
    public function __construct(
        private ?CoaRepository $repo = null,
        private ?CoaLlm $llm = null
    ) {
        $this->repo ??= new CoaRepository;
        $this->llm ??= new CoaLlm;
    }

    public function generateLinkedCoa(
        string $organizationType,
        string $region = 'NG',
        int $suggestionsPerType = 3
    ): array {
        $hierarchy = $this->repo->getFullCoaHierarchy();
        $allTypes = $this->repo->getAllChartAccountTypes();

        $result = [
            'organization_type' => $organizationType,
            'region' => $region,
            'hierarchy' => ['classes' => []],
            'summary' => '',
            'total_suggestions' => 0,
        ];
        $totalSuggestions = 0;

        foreach ($hierarchy['classes'] ?? [] as $classSlug => $classInfo) {
            $result['hierarchy']['classes'][$classSlug] = [
                'id' => $classInfo['id'] ?? null,
                'name' => $classInfo['name'] ?? null,
                'slug' => $classSlug,
                'types' => [],
            ];

            $classTypes = array_values(array_filter(
                $allTypes,
                fn ($t) => ($t['class_slug'] ?? null) === $classSlug
            ));

            if (! $classTypes) {
                $typeSuggestions = $this->generateTypeSuggestions(
                    $organizationType,
                    $classSlug,
                    $classInfo['name'] ?? ucfirst((string) $classSlug),
                    $region
                );
                foreach ($typeSuggestions as $idx => $typeSug) {
                    if (! is_array($typeSug)) {
                        continue;
                    }
                    $typeKey = $typeSug['slug'] ?? "type_{$idx}";
                    $details = $this->generateAccountDetails(
                        $organizationType,
                        $classSlug,
                        $classInfo['name'] ?? ucfirst((string) $classSlug),
                        $typeSug['name'] ?? 'General',
                        null,
                        $region,
                        $suggestionsPerType,
                        []
                    );
                    $result['hierarchy']['classes'][$classSlug]['types'][$typeKey] = [
                        'id' => null,
                        'name' => $typeSug['name'] ?? null,
                        'slug' => $typeKey,
                        'description' => $typeSug['description'] ?? null,
                        'is_ai_suggested' => true,
                        'accounts' => $details,
                    ];
                    $totalSuggestions += count($details);
                }
            } else {
                foreach ($classTypes as $typ) {
                    $typeSlug = $typ['slug'] ?? (string) ($typ['id'] ?? '');
                    $typeId = (int) ($typ['id'] ?? 0);
                    $existingDetails = $this->repo->getTypeDetailsByTypeId($typeId);
                    $existingNames = array_map(
                        fn ($d) => $d['name'] ?? '',
                        $existingDetails
                    );
                    $aiSuggestions = $this->generateAccountDetails(
                        $organizationType,
                        $classSlug,
                        $classInfo['name'] ?? ucfirst((string) $classSlug),
                        $typ['name'] ?? 'Account type',
                        $typeId,
                        $region,
                        $suggestionsPerType,
                        $existingNames
                    );
                    $result['hierarchy']['classes'][$classSlug]['types'][$typeSlug] = [
                        'id' => $typ['id'] ?? null,
                        'chart_account_id' => $typ['chart_account_id'] ?? null,
                        'name' => $typ['name'] ?? null,
                        'slug' => $typeSlug,
                        'description' => $typ['description'] ?? null,
                        'is_ai_suggested' => false,
                        'existing_accounts' => $existingDetails,
                        'suggested_accounts' => $aiSuggestions,
                    ];
                    $totalSuggestions += count($aiSuggestions);
                }
            }
        }

        $result['summary'] = $this->generateSummary(
            $organizationType,
            $region,
            $totalSuggestions
        );
        $result['total_suggestions'] = $totalSuggestions;

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateTypeSuggestions(
        string $organizationType,
        string $classSlug,
        string $className,
        string $region
    ): array {
        $ot = str_replace('_', ' ', $organizationType);
        $prompt = <<<P
You are an expert accountant specializing in {$ot} businesses in {$region}.

The account class "{$className}" ({$classSlug}) has no account types defined yet.

Generate 3-5 account type categories that should exist under this class for a {$ot} business.

Return a JSON array:
[
    {"name": "Type Name", "slug": "type_slug", "description": "What this type category includes"}
]
P;

        try {
            $parsed = $this->llm->completeJson($prompt);

            return array_is_list($parsed) ? $parsed : [$parsed];
        } catch (Throwable $e) {
            Log::error('coa linked type generation failed', [
                'class_slug' => $classSlug,
                'message' => $e->getMessage(),
            ]);

            return $this->getFallbackTypes($classSlug);
        }
    }

    /**
     * @param  list<string>  $existingAccounts
     * @return list<array<string, mixed>>
     */
    private function generateAccountDetails(
        string $organizationType,
        string $classSlug,
        string $className,
        string $typeName,
        ?int $typeId,
        string $region,
        int $count,
        array $existingAccounts
    ): array {
        $ot = str_replace('_', ' ', $organizationType);
        $existingText = '';
        if ($existingAccounts) {
            $lines = array_map(fn ($a) => "- {$a}", array_filter($existingAccounts));
            if ($lines) {
                $existingText = "\n\nExisting accounts in this type (DO NOT duplicate these):\n".implode("\n", $lines);
            }
        }

        $prompt = <<<P
You are an expert accountant specializing in {$ot} businesses in {$region}.

Generate exactly {$count} specific account suggestions for:
- Account Class: {$className} ({$classSlug})
- Account Type: {$typeName}
- Organization: {$ot}
- Region: {$region}
{$existingText}

Each account should be specific to {$ot} businesses.

Return a JSON array:
[
  {
    "name": "Account Name",
    "slug": "account_slug",
    "description": "What transactions this account tracks",
    "reason": "Why this org needs this account",
    "is_mandatory": true,
    "typical_transactions": ["T1", "T2"],
    "compliance_notes": "Any {$region} specific requirements"
  }
]
P;

        try {
            $accounts = $this->llm->completeJson($prompt);
            if (isset($accounts['name']) && is_string($accounts['name'] ?? null)) {
                $accounts = [$accounts];
            } elseif (! array_is_list($accounts)) {
                $accounts = [$accounts];
            }
        } catch (Throwable $e) {
            return $this->getFallbackAccounts($classSlug, $typeName, $typeId, $count);
        }

        $out = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $account['chart_account_type_id'] = $typeId;
            $account['class_slug'] = $classSlug;
            $account['class_name'] = $className;
            $account['type_name'] = $typeName;
            $account['is_ai_suggested'] = true;
            $out[] = $account;
        }

        return $out;
    }

    private function generateSummary(
        string $organizationType,
        string $region,
        int $totalAccounts
    ): string {
        $ot = str_replace('_', ' ', $organizationType);
        $prompt = "You are an expert accountant. Write a brief 2-3 sentence professional summary explaining the chart of accounts structure recommended for a {$ot} business in {$region}. You recommended {$totalAccounts} accounts. Be concise. Return only the text, no JSON.";

        try {
            return trim($this->llm->completeText($prompt));
        } catch (Throwable $e) {
            return "This chart of accounts is tailored for {$ot} businesses in {$region}, with {$totalAccounts} recommended accounts across all categories.";
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function getFallbackTypes(string $classSlug): array
    {
        $f = match (strtolower($classSlug)) {
            'assets' => [
                ['name' => 'Current Assets', 'slug' => 'current_assets', 'description' => 'Short-term assets convertible to cash within a year'],
            ],
            'liabilities' => [
                ['name' => 'Current Liabilities', 'slug' => 'current_liabilities', 'description' => 'Obligations due within a year'],
            ],
            'equity' => [
                ['name' => "Owner's Equity", 'slug' => 'owners_equity', 'description' => "Owner's stake in the business"],
            ],
            'income' => [
                ['name' => 'Operating Income', 'slug' => 'operating_income', 'description' => 'Revenue from core operations'],
            ],
            'expenses' => [
                ['name' => 'Operating Expenses', 'slug' => 'operating_expenses', 'description' => 'Costs of running the business'],
            ],
            default => [
                ['name' => 'General', 'slug' => 'general', 'description' => 'General accounts'],
            ],
        };

        return $f;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getFallbackAccounts(
        string $classSlug,
        string $typeName,
        ?int $typeId,
        int $count
    ): array {
        $defaults = [[
            'name' => "{$typeName} - Account 1",
            'slug' => 'account_1',
            'description' => 'General account',
            'reason' => 'Standard accounting practice',
            'is_mandatory' => false,
            'typical_transactions' => [],
            'compliance_notes' => '',
            'chart_account_type_id' => $typeId,
            'class_slug' => $classSlug,
            'type_name' => $typeName,
            'is_ai_suggested' => true,
        ]];

        return array_slice($defaults, 0, max(1, $count));
    }
}
