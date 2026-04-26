<?php

namespace App\Services\Coa;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Port of `coa_children_generator.py` — COAChildrenGeneratorService.
 */
class CoaChildrenGeneratorService
{
    public function __construct(
        private ?CoaRepository $repo = null,
        private ?CoaLlm $llm = null,
    ) {
        $this->repo ??= new CoaRepository;
        $this->llm ??= new CoaLlm;
    }

    public function generateChildren(
        string $organizationType,
        string $region = 'NG',
        int $suggestionsPerDetail = 3,
        ?string $classFilter = null
    ): array {
        if ($classFilter) {
            $allDetails = $this->repo->getTypeDetailsByClass($classFilter);
        } else {
            $allDetails = $this->repo->getAllChartAccountTypeDetails();
        }

        if (! $allDetails) {
            return [
                'success' => false,
                'error' => 'No chart_account_type_details found',
                'organization_type' => $organizationType,
                'region' => $region,
                'data' => [],
            ];
        }

        $groupedByClass = [];
        foreach ($allDetails as $detail) {
            $cls = $detail['class_slug'];
            if (! isset($groupedByClass[$cls])) {
                $groupedByClass[$cls] = [];
            }
            $groupedByClass[$cls][] = $detail;
        }

        $resultData = [];
        $totalSuggestions = 0;

        foreach ($groupedByClass as $classSlug => $details) {
            try {
                $batch = $this->generateForClassBatch(
                    $classSlug,
                    $details,
                    $organizationType,
                    $region,
                    $suggestionsPerDetail
                );
            } catch (Throwable $e) {
                Log::error('coa children batch failed', [
                    'class' => $classSlug,
                    'message' => $e->getMessage(),
                ]);
                $batch = array_map(
                    fn ($d) => $this->emptyParentRow($d),
                    $details
                );
            }
            foreach ($batch as $item) {
                $resultData[] = $item;
                $totalSuggestions += count($item['ai_children'] ?? []);
            }
        }

        return [
            'success' => true,
            'organization_type' => $organizationType,
            'region' => $region,
            'class_filter' => $classFilter,
            'suggestions_per_detail' => $suggestionsPerDetail,
            'total_grandchildren_processed' => count($allDetails),
            'total_ai_children_generated' => $totalSuggestions,
            'summary' => "Generated {$totalSuggestions} AI sub-accounts for ".count($allDetails)
                ." accounts tailored for ".str_replace('_', ' ', $organizationType).'.',
            'data' => $resultData,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return list<array{parent_account: array, ai_children: list}>
     */
    private function generateForClassBatch(
        string $classSlug,
        array $details,
        string $organizationType,
        string $region,
        int $suggestionsPerDetail
    ): array {
        $accountsList = [];
        foreach ($details as $d) {
            $accountsList[] = [
                'id' => $d['id'],
                'name' => $d['name'],
                'type' => $d['type_name'] ?? $d['type'] ?? null,
                'class' => $d['class_name'] ?? $d['class'] ?? null,
            ];
        }

        $ot = str_replace('_', ' ', $organizationType);
        $classUpper = strtoupper($classSlug);
        $listJson = json_encode($accountsList, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $prompt = <<<PROMPT
You are an expert accountant for {$ot} businesses in {$region}.

Here are existing accounts in the {$classUpper} category:

{$listJson}

For EACH account that is RELEVANT to a {$ot} business, generate up to {$suggestionsPerDetail} sub-accounts (children).

IMPORTANT:
- Only generate children for accounts that make sense for {$ot}
- Skip accounts that aren't relevant (return empty array for those)
- Each child needs: name, slug, description, reason, relevance (high/medium/low)

Return JSON object where keys are the account IDs:
{
  "82": [
    {"name": "Petty Cash", "slug": "petty_cash", "description": "...", "reason": "...", "relevance": "high"}
  ],
  "85": []
}
PROMPT;

        try {
            $aiResult = $this->llm->completeJson($prompt);
        } catch (Throwable $e) {
            return array_map(fn ($d) => $this->emptyParentRow($d), $details);
        }

        $result = [];
        foreach ($details as $detail) {
            $detailIdStr = (string) $detail['id'];
            $aiChildrenRaw = $this->getAiChildrenRaw($aiResult, $detailIdStr, $detail['id']);

            $classId = $detail['class_id'] ?? null;
            $typeId = $detail['type_id'] ?? $detail['chart_account_type_id'] ?? null;
            $detailId = (int) $detail['id'];

            $aiChildren = [];
            foreach ($aiChildrenRaw as $child) {
                if (! is_array($child) || empty($child['name'])) {
                    continue;
                }
                $child['chart_account_id'] = $classId;
                $child['chart_account_type_id'] = $typeId;
                $child['chart_account_type_detail_id'] = $detailId;
                $child['is_ai_generated'] = true;
                $child['is_mandatory'] = (bool) ($child['is_mandatory'] ?? false);
                $child['typical_transactions'] = is_array($child['typical_transactions'] ?? null)
                    ? $child['typical_transactions'] : [];
                $aiChildren[] = $child;
            }

            $result[] = [
                'parent_account' => $this->parentInfo($detail, $classId, $typeId, $detailId),
                'ai_children' => $aiChildren,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $aiResult
     * @return list<array<string, mixed>>
     */
    private function getAiChildrenRaw(array $aiResult, string $detailIdStr, int|float|string $id): array
    {
        if (isset($aiResult[$detailIdStr]) && is_array($aiResult[$detailIdStr])) {
            return $this->asList($aiResult[$detailIdStr]);
        }
        if (isset($aiResult[(int) $id]) && is_array($aiResult[(int) $id])) {
            return $this->asList($aiResult[(int) $id]);
        }

        return [];
    }

    private function asList(mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }

        return array_is_list($v) ? $v : [$v];
    }

    public function generateChildrenForSingleDetail(
        int $detailId,
        string $organizationType,
        string $region = 'NG',
        int $suggestionsCount = 3
    ): array {
        $detail = $this->repo->getTypeDetailById($detailId);

        if (! $detail) {
            return [
                'success' => false,
                'error' => "chart_account_type_details with id {$detailId} not found",
                'data' => null,
            ];
        }

        $ot = str_replace('_', ' ', $organizationType);
        $prompt = <<<PROMPT
You are an expert accountant for {$ot} businesses in {$region}.

Generate {$suggestionsCount} sub-account suggestions for:
- Account: {$detail['name']}
- Type: {$detail['type_name']}
- Class: {$detail['class_name']}

Return JSON array:
[{"name": "...", "slug": "...", "description": "...", "reason": "...", "relevance": "high|medium|low"}]
PROMPT;

        try {
            $parsed = $this->llm->completeJson($prompt);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'parent_account' => null,
                'ai_children' => [],
                'total_generated' => 0,
            ];
        }

        if (! array_is_list($parsed)) {
            if (is_array($parsed) && $parsed !== []) {
                $aiChildren = [$parsed];
            } else {
                $aiChildren = [];
            }
        } else {
            $aiChildren = $parsed;
        }

        $classId = $detail['class_id'] ?? null;
        $typeId = $detail['type_id'] ?? $detail['chart_account_type_id'] ?? null;
        $did = (int) $detail['id'];

        foreach ($aiChildren as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['chart_account_id'] = $classId;
            $row['chart_account_type_id'] = $typeId;
            $row['chart_account_type_detail_id'] = $did;
            $row['is_ai_generated'] = true;
            $row['is_mandatory'] = (bool) ($row['is_mandatory'] ?? false);
            $row['typical_transactions'] = is_array($row['typical_transactions'] ?? null)
                ? $row['typical_transactions'] : [];
            $aiChildren[$idx] = $row;
        }

        return [
            'success' => true,
            'organization_type' => $organizationType,
            'region' => $region,
            'parent_account' => $this->parentInfo($detail, $classId, $typeId, $did),
            'ai_children' => array_values(array_filter($aiChildren, 'is_array')),
            'total_generated' => count($aiChildren),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function parentInfo(array $detail, mixed $classId, mixed $typeId, int $detailId): array
    {
        return [
            'chart_account_id' => $classId,
            'chart_account_type_id' => $typeId,
            'chart_account_type_detail_id' => $detailId,
            'class_name' => $detail['class_name'] ?? null,
            'class_slug' => $detail['class_slug'] ?? null,
            'type_name' => $detail['type_name'] ?? null,
            'type_slug' => $detail['type_slug'] ?? null,
            'detail_name' => $detail['name'] ?? null,
            'detail_slug' => $detail['slug'] ?? null,
        ];
    }

    private function emptyParentRow(array $d): array
    {
        $classId = $d['class_id'] ?? null;
        $typeId = $d['type_id'] ?? $d['chart_account_type_id'] ?? null;

        return [
            'parent_account' => $this->parentInfo($d, $classId, $typeId, (int) $d['id']),
            'ai_children' => [],
        ];
    }
}
