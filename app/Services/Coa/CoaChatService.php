<?php

namespace App\Services\Coa;

use App\Ai\Agents\CoaReviewAgent;
use Illuminate\Support\Str;
use Throwable;

/**
 * Port of `backend/modules/automative_assistant/src/services/coa_chat_service.py`
 * (COAChatService class and module-level helpers).
 */
class CoaChatService
{
    private const VALID_CLASS_TABS = ['assets', 'liabilities', 'equity', 'income', 'expenses'];

    private const DESTRUCTIVE_OPS = ['remove_account', 'rename_account', 'merge_accounts', 'move_account'];

    private const NON_ACTIONABLE_OPS = ['answer_only', 'guidance_only', 'none', ''];

    public function __construct(
        private ?CoaRepository $coaRepository = null,
    ) {
        if ($this->coaRepository === null) {
            $this->coaRepository = new CoaRepository;
        }
    }

    /**
     * @param  array<int, array{role: string, content: string, turn_number?: int}>|null  $conversationHistory
     * @param  array<string, mixed>|null  $coaContext
     * @param  list<string>|null  $lockedEntityKeys
     * @return array{
     *   success: bool,
     *   message_id: string,
     *   conversation_id: string|null,
     *   error: string|null,
     *   assistant_message: string,
     *   suggested_follow_up_questions: list<string>,
     *   has_proposed_changes: bool,
     *   proposed_changes: list<array<string, mixed>>,
     *   rejected_due_to_lock: list<array<string, mixed>>,
     *   rejected_due_to_validation: list<array<string, mixed>>,
     *   active_class_tab: string
     * }
     */
    public function chat(string $message,string $activeClassTab,?string $branchLabel,
        string $organizationType,string $region,?array $coaContext,?array $lockedEntityKeys,
        bool $loadHierarchyFromDb,bool $restrictDestructiveToAiGenerated = false,?array 
        $conversationHistory = null,?string $conversationId = null,
        ): array 
    {
        $tab = $this->normalizeTab($activeClassTab);
        $locked = $this->parseLockedKeys($lockedEntityKeys);
        $messageId = (string) Str::uuid();

        $subtree = $coaContext;
        if ($subtree === null && $loadHierarchyFromDb) {
            $hierarchy = $this->coaRepository->getFullCoaHierarchy();
            $classBlock = $hierarchy['classes'][$tab] ?? null;
            $subtree = $classBlock !== null ? [$tab => $classBlock] : [];
        }

        $aiGeneratedRefs = [];
        if ($subtree) {
            $this->collectAiGeneratedRefs($subtree, $aiGeneratedRefs);
        }

        $contextJson = json_encode($subtree ?? new \stdClass, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($locked !== []) {
            $list = array_keys($locked);
            sort($list);
            $lockedJson = json_encode($list, JSON_THROW_ON_ERROR);
        } else {
            $lockedJson = '[]';
        }

        $historyBlock = $this->formatHistory($conversationHistory);
        $aiOnlyNote = $restrictDestructiveToAiGenerated
            ? 'ENABLED — remove/rename/merge/move only for rows where is_ai_generated is true.'
            : 'disabled.';

        $userPrompt = <<<PROMPT
        CONTEXT
        - Organization: {$this->escapeOrgType($organizationType)}
        - Region: {$region}
        - Active tab: {$this->strUpper($tab)} (one of: assets, liabilities, equity, income, expenses)
        - Branch: {$this->emptyAsNotSpecified($branchLabel)}

        {$historyBlock}

        COA_SUBTREE (live chart the user sees — arbitrary depth via recursive children arrays):
        {$contextJson}

        LOCKED_ENTITIES — no direct edit on these nodes (locks do NOT block adding descendants):
        {$lockedJson}

        Restrict destructive ops to AI-generated rows: {$aiOnlyNote}

        USER MESSAGE: {$message}
        PROMPT;

        try {
            $raw = $this->invokeLlm($userPrompt);
        } catch (Throwable $e) {
            report($e);

            return $this->failureResponse($messageId, $conversationId, $tab);
        }

        if (! is_array($raw)) {
            $raw = [];
        }

        $assistantMessage = $this->cleanMessage(
            trim((string) ($raw['assistant_message'] ?? '')) !== ''
                ? (string) $raw['assistant_message']
                : 'I did not receive a clear response. Could you rephrase your question?'
        );

        $follow = $raw['suggested_follow_up_questions'] ?? [];
        if (! is_array($follow)) {
            $follow = [];
        }
        $follow = array_values(array_filter(array_map('strval', $follow), fn ($x) => trim($x) !== ''));
        $follow = array_slice($follow, 0, 6);

        $proposedRaw = $raw['proposed_operations'] ?? $raw['proposed_changes'] ?? [];
        if (! is_array($proposedRaw)) {
            $proposedRaw = [];
        }

        $proposedRaw = array_values(array_filter(
            $proposedRaw,
            fn ($p) => is_array($p)
                && ! in_array(strtolower(trim((string) ($p['operation'] ?? ''))), self::NON_ACTIONABLE_OPS, true)
        ));

        $validOps = [];
        $validationRejects = [];
        foreach ($proposedRaw as $op) {
            if (! is_array($op)) {
                continue;
            }
            $reason = $this->validationReason($op);
            if ($reason !== null) {
                $validationRejects[] = array_merge($op, ['validation_reason' => $reason]);
            } else {
                $validOps[] = $op;
            }
        }

        $cleanOps = array_map(fn (array $op) => $this->buildCleanOp($op, $subtree), $validOps);

        $allowed = [];
        $lockRejects = [];
        foreach ($cleanOps as $op) {
            $lr = $this->lockReason($op, $locked);
            if ($lr !== null) {
                $lockRejects[] = array_merge($op, ['blocked_reason' => $lr]);

                continue;
            }
            if (
                $restrictDestructiveToAiGenerated
                && in_array($op['operation'] ?? '', self::DESTRUCTIVE_OPS, true)
                && ! $this->aiGeneratedCheck($op, $aiGeneratedRefs)
            ) {
                $lockRejects[] = array_merge($op, ['blocked_reason' => 'destructive_target_not_ai_generated']);

                continue;
            }
            $allowed[] = $op;
        }

        return [
            'success' => true,
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'assistant_message' => $assistantMessage,
            'suggested_follow_up_questions' => $follow,
            'has_proposed_changes' => $allowed !== [],
            'proposed_changes' => $allowed,
            'rejected_due_to_lock' => $lockRejects,
            'rejected_due_to_validation' => $validationRejects,
            'active_class_tab' => $tab,
            'error' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeLlm(string $userPrompt): array
    {
        // Default provider/model: config/ai.php + .env (set GEMINI_API_KEY to match Python)
        $response = (new CoaReviewAgent)->prompt($userPrompt);

        if (is_array($response)) {
            return $response;
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            return $response->toArray();
        }

        if ($response instanceof \ArrayAccess) {
            return (array) $response;
        }

        return (array) json_decode(json_encode($response), true);
    }

    private function failureResponse(string $messageId, ?string $conversationId, string $tab): array {
        return [
            'success' => false,
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'error' => 'coa_chat_generation_failed',
            'assistant_message' => 'I could not process that request right now. Please try again or rephrase your question.',
            'suggested_follow_up_questions' => [],
            'has_proposed_changes' => false,
            'proposed_changes' => [],
            'rejected_due_to_lock' => [],
            'rejected_due_to_validation' => [],
            'active_class_tab' => $tab,
        ];
    }

    private function strUpper(string $s): string
    {
        return strtoupper($s);
    }

    private function emptyAsNotSpecified(?string $s): string
    {
        $t = $s !== null ? trim($s) : '';

        return $t === '' ? 'not specified' : $t;
    }

    private function escapeOrgType(string $organizationType): string
    {
        return str_replace('_', ' ', $organizationType);
    }

    private function normalizeTab(string $tab): string
    {
        $t = strtolower(trim($tab !== '' ? $tab : 'assets'));

        return in_array($t, self::VALID_CLASS_TABS, true) ? $t : 'assets';
    }

    /**
     * @param  list<string>|null  $raw
     * @return array<string, true>
     */
    private function parseLockedKeys(?array $raw): array
    {
        if (! $raw) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! $item || ! str_contains((string) $item, ':')) {
                continue;
            }
            $parts = explode(':', (string) $item, 2);
            if (count($parts) < 2) {
                continue;
            }
            $level = strtolower(trim($parts[0]));
            $rest = trim($parts[1]);
            if ($rest === '') {
                continue;
            }
            if (in_array($level, ['detail', 'type', 'class'], true) && ctype_digit($rest)) {
                $out["{$level}:".((int) $rest)] = true;
            } elseif ($level === 'code') {
                $out['code:'.$rest] = true;
            } elseif ($level === 'id' && ctype_digit($rest)) {
                $out['id:'.((int) $rest)] = true;
            }
        }

        return $out;
    }

    private function collectAiGeneratedRefs(mixed $obj, array &$out): void
    {
        if (is_array($obj)) {
            if (array_key_exists('is_ai_generated', $obj) && $obj['is_ai_generated'] === true) {
                if (array_key_exists('id', $obj) && $obj['id'] !== null) {
                    try {
                        $out['id:'.((int) $obj['id'])] = true;
                    } catch (Throwable) {
                    }
                }
                if (isset($obj['code']) && (string) $obj['code'] !== '') {
                    $out['code:'.trim((string) $obj['code'])] = true;
                }
            }
            foreach ($obj as $v) {
                $this->collectAiGeneratedRefs($v, $out);
            }
        } elseif (is_object($obj)) {
            $this->collectAiGeneratedRefs((array) $obj, $out);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $ancestors
     * @return \Generator<int, array{0: list<array<string, mixed>>, 1: array<string, mixed>}>
     */
    private function dfsIterNodes(mixed $obj, array $ancestors): \Generator
    {
        if (is_array($obj) && $this->isAssoc($obj)) {
            $hasIdent = (isset($obj['id']) && $obj['id'] !== null)
                || (isset($obj['code']) && $obj['code'] !== null)
                || (isset($obj['slug']) && $obj['slug'] !== null);
            if ($hasIdent && isset($obj['name']) && $obj['name'] !== null) {
                yield [$ancestors, $obj];
            }
            $nextAnc = array_merge($ancestors, [$obj]);
            foreach ($obj as $v) {
                if (is_array($v)) {
                    if ($this->isListArray($v)) {
                        foreach ($v as $item) {
                            yield from $this->dfsIterNodes($item, $nextAnc);
                        }
                    } else {
                        yield from $this->dfsIterNodes($v, $nextAnc);
                    }
                }
            }
        } elseif (is_array($obj) && $this->isListArray($obj)) {
            foreach ($obj as $item) {
                yield from $this->dfsIterNodes($item, $ancestors);
            }
        }
    }

    private function isAssoc(array $a): bool
    {
        if ($a === []) {
            return true;
        }

        return array_keys($a) !== range(0, count($a) - 1);
    }

    private function isListArray(array $a): bool
    {
        if ($a === []) {
            return true;
        }

        return array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}|null
     */
    private function findNodeMatch(mixed $subtree, array $op): ?array
    {
        $wantId = $op['entity_id'] ?? null;
        $wantCode = $op['entity_code'] ?? null;
        $wantName = $op['name'] ?? null;
        $wantIdInt = null;
        if ($wantId !== null && is_numeric($wantId)) {
            $wantIdInt = (int) $wantId;
        }
        $wantCodeS = $wantCode !== null ? trim((string) $wantCode) : '';
        $wantNameS = $wantName !== null ? trim((string) $wantName) : '';
        $nameFallback = null;

        foreach ($this->dfsIterNodes($subtree, []) as [$anc, $node]) {
            $nid = $node['id'] ?? null;
            $nidInt = null;
            if ($nid !== null && is_numeric($nid)) {
                $nidInt = (int) $nid;
            }
            $nc = trim((string) ($node['code'] ?? ''));
            $nm = trim((string) ($node['name'] ?? ''));

            if ($wantIdInt !== null && $nidInt !== null && $nidInt === $wantIdInt) {
                return [$anc, $node];
            }
            if ($wantCodeS !== '' && $nc === $wantCodeS) {
                return [$anc, $node];
            }
            if ($wantNameS !== '' && $nm === $wantNameS && $wantIdInt === null && $wantCodeS === '') {
                $nameFallback = [$anc, $node];
            }
        }

        return $nameFallback;
    }

    /**
     * @param  array  $ancestors
     * @return list<array{id: mixed, code: mixed, name: mixed}>
     */
    private function cleanPath(array $ancestors, array $node): array
    {
        $path = [];
        foreach (array_merge($ancestors, [$node]) as $a) {
            $name = $a['name'] ?? null;
            $id = $a['id'] ?? null;
            $code = $a['code'] ?? null;
            if ($name || $id !== null || $code) {
                $path[] = ['id' => $id, 'code' => $code, 'name' => $name];
            }
        }

        return $path;
    }

    private function snapshotNode(array $n): array
    {
        $keys = [
            'id', 'code', 'name', 'slug', 'parent_code',
            'account_type_id', 'detail_type_id',
            'is_ai_generated', 'is_active', 'is_subaccount',
        ];
        $o = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $n) && $n[$k] !== null) {
                $o[$k] = $n[$k];
            }
        }

        return $o;
    }

    private function buildCleanOp(array $rawOp, mixed $subtree): array
    {
        $operation = strtolower(trim((string) ($rawOp['operation'] ?? '')));
        $match = $subtree ? $this->findNodeMatch($subtree, $rawOp) : null;
        $ancestors = $match[0] ?? [];
        $node = $match[1] ?? null;

        $placement = [];
        if ($ancestors) {
            $p = $ancestors[array_key_last($ancestors)];
            if (! empty($p['code'])) {
                $placement['parent_code'] = $p['code'];
            }
            if (isset($p['id']) && $p['id'] !== null) {
                $placement['parent_id'] = $p['id'];
            }
            if (! empty($p['name'])) {
                $placement['parent_name'] = $p['name'];
            }
            if (isset($p['account_type_id']) && $p['account_type_id'] !== null) {
                $placement['parent_account_type_id'] = $p['account_type_id'];
            }
            if (isset($p['detail_type_id']) && $p['detail_type_id'] !== null) {
                $placement['parent_detail_type_id'] = $p['detail_type_id'];
            }
        }
        if (! empty($rawOp['parent_code'])) {
            $placement['parent_code'] ??= $rawOp['parent_code'];
        }
        if (isset($rawOp['parent_type_id']) && $rawOp['parent_type_id'] !== null) {
            $placement['parent_account_type_id'] ??= $rawOp['parent_type_id'];
        }
        if (isset($rawOp['parent_detail_id']) && $rawOp['parent_detail_id'] !== null) {
            $placement['parent_detail_type_id'] ??= $rawOp['parent_detail_id'];
        }

        $target = [];
        if ($node) {
            $snap = $this->snapshotNode($node);
            $target = $snap;
            $target['path_from_root'] = $this->cleanPath($ancestors, $node);
        } else {
            if (isset($rawOp['entity_id']) && $rawOp['entity_id'] !== null) {
                $target['id'] = $rawOp['entity_id'];
            }
            if (! empty($rawOp['entity_code'])) {
                $target['code'] = $rawOp['entity_code'];
            }
            if (! empty($rawOp['name'])) {
                $target['name'] = $rawOp['name'];
            }
        }

        $before = [];
        $after = [];

        if ($operation === 'rename_account') {
            $before['name'] = $target['name'] ?? $rawOp['name'] ?? null;
            $after['name'] = $rawOp['new_name'] ?? null;
            if (! empty($rawOp['new_code'])) {
                $after['code'] = $rawOp['new_code'];
            }
        } elseif ($operation === 'add_account') {
            $after['name'] = $rawOp['name'] ?? null;
            if (! empty($rawOp['new_code'])) {
                $after['code'] = $rawOp['new_code'];
            }
            if ($placement) {
                $after['parent'] = $placement;
            }
        } elseif ($operation === 'remove_account') {
            $before['name'] = $target['name'] ?? $rawOp['name'] ?? null;
            $before['is_active'] = $target['is_active'] ?? true;
        } elseif ($operation === 'merge_accounts') {
            $before['name'] = $target['name'] ?? $rawOp['name'] ?? null;
            $dest = [];
            if (isset($rawOp['merge_into_entity_id']) && $rawOp['merge_into_entity_id'] !== null) {
                $dest['id'] = $rawOp['merge_into_entity_id'];
            }
            if (! empty($rawOp['merge_into_entity_code'])) {
                $dest['code'] = $rawOp['merge_into_entity_code'];
            }
            $after['merged_into'] = $dest;
        } elseif ($operation === 'move_account') {
            $before['name'] = $target['name'] ?? $rawOp['name'] ?? null;
            if ($node && $ancestors) {
                $oldP = $ancestors[array_key_last($ancestors)];
                $before['parent'] = array_filter([
                    'id' => $oldP['id'] ?? null,
                    'code' => $oldP['code'] ?? null,
                    'name' => $oldP['name'] ?? null,
                ], fn ($v) => $v !== null);
            }
            if ($placement) {
                $after['parent'] = $placement;
            }
        } elseif ($operation === 'update_description') {
            $before['description'] = $target['description'] ?? null;
            $after['description'] = $rawOp['new_description'] ?? null;
        } elseif ($operation === 'toggle_active') {
            $cur = $target['is_active'] ?? true;
            $before['is_active'] = $cur;
            $after['is_active'] = ! $cur;
        } elseif ($operation === 'update_code') {
            $before['code'] = $target['code'] ?? $rawOp['entity_code'] ?? null;
            $after['code'] = $rawOp['new_code'] ?? null;
        }

        $out = [
            'op_id' => (string) Str::uuid(),
            'operation' => $operation,
        ];
        if (isset($target['id']) && $target['id'] !== null) {
            $out['entity_id'] = is_numeric($target['id']) ? (int) $target['id'] : $target['id'];
        }
        if (! empty($target['code'])) {
            $out['entity_code'] = (string) $target['code'];
        }
        $level = $rawOp['entity_level'] ?? null;
        if ($level && ! in_array(strtolower((string) $level), ['null', 'none'], true)) {
            $out['entity_level'] = $level;
        }
        if ($this->nonEmptyAssoc($before)) {
            $out['before'] = $this->filterNulls($before);
        }
        if ($this->nonEmptyAssoc($after)) {
            $out['after'] = $this->filterNulls($after);
        }
        if ($this->nonEmptyAssoc($target)) {
            $out['target'] = $this->filterNulls($target);
        }
        if ($this->nonEmptyAssoc($placement)) {
            $out['placement'] = $placement;
        }
        if (! empty($rawOp['rationale'])) {
            $out['rationale'] = $rawOp['rationale'];
        }
        if ($operation === 'merge_accounts') {
            if (isset($rawOp['merge_into_entity_id']) && $rawOp['merge_into_entity_id'] !== null) {
                $out['merge_destination_id'] = is_numeric($rawOp['merge_into_entity_id'])
                    ? (int) $rawOp['merge_into_entity_id'] : $rawOp['merge_into_entity_id'];
            }
            if (! empty($rawOp['merge_into_entity_code'])) {
                $out['merge_destination_code'] = (string) $rawOp['merge_into_entity_code'];
            }
        }

        return $out;
    }

    private function nonEmptyAssoc(array $a): bool
    {
        return $a !== [] && $this->filterNulls($a) !== [];
    }

    private function filterNulls(array $a): array
    {
        return array_filter($a, fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, true>  $locked
     */
    private function lockReason(array $op, array $locked): ?string
    {
        $level = $op['entity_level'] ?? null;
        $eid = $op['entity_id'] ?? null;
        $eidInt = null;
        if ($eid !== null && is_numeric($eid)) {
            $eidInt = (int) $eid;
        }
        if ($eidInt !== null) {
            $lvlS = strtolower(trim((string) ($level ?? '')));
            if (in_array($lvlS, ['detail', 'type', 'class'], true) && isset($locked["{$lvlS}:{$eidInt}"])) {
                return 'entity_locked';
            }
            if (isset($locked['id:'.$eidInt])) {
                return 'entity_locked';
            }
        }
        $ec = $op['entity_code'] ?? null;
        if ($ec && trim((string) $ec) !== '' && isset($locked['code:'.trim((string) $ec)])) {
            return 'entity_locked';
        }

        return null;
    }

    /**
     * @param  array<string, true>  $aiRefs
     */
    private function aiGeneratedCheck(array $op, array $aiRefs): bool
    {
        $eid = $op['entity_id'] ?? null;
        if ($eid !== null && is_numeric($eid)) {
            if (isset($aiRefs['id:'.(int) $eid])) {
                return true;
            }
        }
        $ec = $op['entity_code'] ?? null;
        if ($ec && trim((string) $ec) !== '' && isset($aiRefs['code:'.trim((string) $ec)])) {
            return true;
        }
        if (($op['operation'] ?? '') === 'add_account') {
            return true;
        }

        return false;
    }

    private function validationReason(array $op): ?string
    {
        $opname = strtolower(trim((string) ($op['operation'] ?? '')));
        if ($opname === '') {
            return 'missing_operation';
        }
        $hasId = isset($op['entity_id']) && $op['entity_id'] !== null;
        $hasCode = ! empty($op['entity_code']) && trim((string) $op['entity_code']) !== '';
        $hasTarget = $hasId || $hasCode;

        if ($opname === 'rename_account') {
            if (! $hasTarget) {
                return 'rename_missing_target';
            }
            $nn = $op['new_name'] ?? null;
            if (! $nn || trim((string) $nn) === '') {
                return 'rename_missing_new_name';
            }
        } elseif ($opname === 'remove_account') {
            if (! $hasTarget) {
                return 'remove_missing_target';
            }
        } elseif ($opname === 'add_account') {
            $nm = $op['name'] ?? $op['new_account_name'] ?? $op['account_name'] ?? null;
            if (! $nm || trim((string) $nm) === '') {
                return 'add_missing_account_name';
            }
            $pc = $op['parent_code'] ?? null;
            $hasParent = ($pc && trim((string) $pc) !== '')
                || (($op['parent_type_id'] ?? null) !== null)
                || (($op['parent_detail_id'] ?? null) !== null);
            if (! $hasParent) {
                return 'add_missing_parent_ref';
            }
        } elseif ($opname === 'merge_accounts') {
            if (! $hasTarget) {
                return 'merge_missing_source';
            }
            $destId = $op['merge_into_entity_id'] ?? null;
            $destCode = $op['merge_into_entity_code'] ?? null;
            if ($destId === null && ! ($destCode && trim((string) $destCode) !== '')) {
                return 'merge_missing_destination';
            }
        } elseif ($opname === 'move_account') {
            if (! $hasTarget) {
                return 'move_missing_target';
            }
            $pc = $op['parent_code'] ?? null;
            $pt = $op['parent_type_id'] ?? null;
            $pd = $op['parent_detail_id'] ?? null;
            if ($pt === null && $pd === null && ! ($pc && trim((string) $pc) !== '')) {
                return 'move_missing_destination_parent';
            }
        } elseif (in_array($opname, ['update_description', 'toggle_active', 'update_code'], true)) {
            if (! $hasTarget) {
                return "{$opname}_missing_target";
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $history
     */
    private function formatHistory(?array $history): string
    {
        if (! $history) {
            return '';
        }
        $lines = ['CONVERSATION_HISTORY (oldest first):'];
        foreach ($history as $turn) {
            $role = strtoupper((string) ($turn['role'] ?? ''));
            $content = trim((string) ($turn['content'] ?? ''));
            if ($role !== '' && $content !== '') {
                $lines[] = "[{$role}] {$content}";
            }
        }

        return implode("\n", $lines);
    }

    private function cleanMessage(string $text): string
    {
        $t = trim($text);
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
        $t = preg_replace(
            '/\s*(Would you like me to proceed|Shall I proceed|Should I go ahead)[^.?!]*[.?!]?\s*$/i',
            '',
            $t
        ) ?? $t;

        return trim($t);
    }
}
