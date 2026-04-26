<?php

namespace App\Services\Coa;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Port of `coa_generator.py` — COAGeneratorService::generate_coa_suggestions.
 */
class CoaGeneratorService
{
    public function __construct(
        private ?CoaRepository $repo = null,
        private ?CoaLlm $llm = null
    ) {
        $this->repo ??= new CoaRepository;
        $this->llm ??= new CoaLlm;
    }

    public function generateCoaSuggestions(
        string $organizationType,
        string $region = 'NG',
        int $suggestionsPerClass = 5
    ): array {
        $accountStructure = $this->repo->getAccountStructure();
        $accountClasses = array_keys($accountStructure);
        if (! $accountClasses) {
            $accountClasses = ['assets', 'liabilities', 'equity', 'income', 'expenses'];
        }

        $result = [
            'organization_type' => $organizationType,
            'region' => $region,
            'account_classes' => [],
            'summary' => '',
        ];

        $allSuggestions = [];
        foreach ($accountClasses as $accountClass) {
            $classInfo = $accountStructure[$accountClass] ?? [
                'name' => ucfirst($accountClass),
                'types' => [],
            ];
            $suggestions = $this->generateClassSuggestions(
                $organizationType,
                $accountClass,
                $classInfo['name'] ?? ucfirst($accountClass),
                $classInfo['types'] ?? [],
                $region,
                $suggestionsPerClass
            );
            $result['account_classes'][$accountClass] = [
                'class_name' => $classInfo['name'] ?? ucfirst($accountClass),
                'suggestions' => $suggestions,
            ];
            $allSuggestions = array_merge($allSuggestions, $suggestions);
        }

        $result['summary'] = $this->generateSummary(
            $organizationType,
            $region,
            count($allSuggestions)
        );
        $result['total_suggestions'] = count($allSuggestions);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $existingTypes
     * @return list<array<string, mixed>>
     */
    private function generateClassSuggestions(
        string $organizationType,
        string $accountClass,
        string $className,
        array $existingTypes,
        string $region,
        int $count
    ): array {
        $ot = str_replace('_', ' ', $organizationType);
        $existingTypesText = '';
        if ($existingTypes) {
            $lines = [];
            foreach ($existingTypes as $t) {
                $name = is_array($t) ? ($t['name'] ?? '') : (string) $t;
                $desc = is_array($t) ? ($t['description'] ?? '') : '';
                $lines[] = "- {$name}: {$desc}";
            }
            $existingTypesText = implode("\n", $lines);
        }

        $etBlock = $existingTypesText
            ? "Existing account types in this class:\n{$existingTypesText}"
            : 'No existing account types.';

        $prompt = <<<P
You are an expert accountant specializing in {$ot} businesses in {$region}.

Generate exactly {$count} chart of account suggestions for the "{$className}" ({$accountClass}) class.

Organization Type: {$ot}
Region: {$region}
Account Class: {$className}

{$etBlock}

Requirements:
1. Each account must be specific and relevant to {$ot} businesses
2. Include accounts that are commonly used in {$region} for this industry
3. Accounts should follow standard accounting principles
4. Each account needs a clear name, type, description, and business reason

Return a JSON array with exactly {$count} account suggestions:
[
    {
        "name": "Account Name",
        "type": "Account Type (e.g., Current Asset, Fixed Asset, Operating Expense)",
        "description": "What this account tracks",
        "reason": "Why a business needs this account",
        "is_mandatory": true,
        "typical_transactions": ["Example transaction 1", "Example transaction 2"]
    }
]
P;

        try {
            $parsed = $this->llm->completeJson($prompt);
        } catch (Throwable $e) {
            Log::error('coa class generation failed', [
                'class' => $accountClass,
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackSuggestions($accountClass, $className, $organizationType, $count);
        }

        if (isset($parsed['name']) && is_string($parsed['name'] ?? null)) {
            $suggestions = [$parsed];
        } elseif (array_is_list($parsed)) {
            $suggestions = $parsed;
        } else {
            $suggestions = [$parsed];
        }

        $out = [];
        foreach (array_slice($suggestions, 0, $count) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $s['account_class'] = $accountClass;
            $s['class_name'] = $className;
            $out[] = $s;
        }

        return $out;
    }

    private function generateSummary(
        string $organizationType,
        string $region,
        int $totalAccounts
    ): string {
        $ot = str_replace('_', ' ', $organizationType);
        $prompt = "You are an expert accountant. Write a brief 2-3 sentence summary explaining why the chart of accounts structure you recommended is ideal for a {$ot} business in {$region}. You recommended {$totalAccounts} accounts across all classes. Be concise. Return only the summary text, no JSON.";

        try {
            return trim($this->llm->completeText($prompt));
        } catch (Throwable $e) {
            return "This chart of accounts is tailored for {$ot} businesses in {$region}, covering all essential financial categories with {$totalAccounts} recommended accounts.";
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getFallbackSuggestions(
        string $accountClass,
        string $className,
        string $organizationType,
        int $count
    ): array {
        $base = $this->fallbackData(strtolower($accountClass));
        $out = [];
        foreach (array_slice($base, 0, $count) as $row) {
            $out[] = array_merge($row, [
                'account_class' => $accountClass,
                'class_name' => $className,
            ]);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fallbackData(string $key): array
    {
        if (in_array($key, ['expense', 'expenses'], true)) {
            $key = 'expenses';
        }
        if ($key === 'assets') {
            return [
                ['name' => 'Cash on Hand', 'type' => 'Current Asset', 'description' => 'Physical cash held by the business', 'reason' => 'Essential for daily operations', 'is_mandatory' => true, 'typical_transactions' => ['Petty cash disbursements', 'Cash sales received']],
                ['name' => 'Bank Accounts', 'type' => 'Current Asset', 'description' => 'Funds in business bank accounts', 'reason' => 'Primary payment and receipt channel', 'is_mandatory' => true, 'typical_transactions' => ['Customer payments', 'Supplier payments']],
            ];
        }
        if ($key === 'liabilities') {
            return [
                ['name' => 'Accounts Payable', 'type' => 'Current Liability', 'description' => 'Money owed to suppliers', 'reason' => 'Track supplier obligations', 'is_mandatory' => true, 'typical_transactions' => ['Supplier invoices', 'Supplier payments']],
            ];
        }
        if ($key === 'equity') {
            return [
                ['name' => "Owner's Capital", 'type' => "Owner's Equity", 'description' => "Owner's investment in business", 'reason' => 'Track ownership stake', 'is_mandatory' => true, 'typical_transactions' => ['Capital contribution', 'Capital withdrawal']],
            ];
        }
        if ($key === 'income') {
            return [
                ['name' => 'Service Revenue', 'type' => 'Operating Income', 'description' => 'Income from services rendered', 'reason' => 'Core business income', 'is_mandatory' => true, 'typical_transactions' => ['Service fee received', 'Consulting income']],
            ];
        }
        if ($key === 'expenses') {
            return [
                ['name' => 'Salaries and Wages', 'type' => 'Operating Expense', 'description' => 'Employee compensation', 'reason' => 'Major operating cost', 'is_mandatory' => true, 'typical_transactions' => ['Monthly salary', 'Overtime payment']],
            ];
        }

        return [
            ['name' => 'General Ledger Account', 'type' => 'General', 'description' => 'Default fallback account', 'reason' => 'LLM fallback', 'is_mandatory' => false, 'typical_transactions' => []],
        ];
    }
}
