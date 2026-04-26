<?php

namespace App\Services\Coa;

use App\Models\ChartAccount;
use App\Models\ChartAccountType;
use App\Models\ChartAccountTypeDetail;
use Illuminate\Support\Collection;

/**
 * Port of `coa_repo.py` ::COARepository — uses Eloquent per .junie/laravel-best-practices (Eloquent,
 * N+1 prevention, no hardcoded table name strings: relationships + model getTable() when needed).
 */
class CoaRepository
{
    public function getFullCoaHierarchy(): array
    {
        $hierarchy = ['classes' => []];

        $accounts = ChartAccount::query()
            ->orderBy('id')
            ->with([
                'accountTypes' => fn ($q) => $q
                    ->orderBy('id')
                    ->with([
                        'typeDetails' => fn ($d) => $d->orderBy('id'),
                    ]),
            ])
            ->get();

        foreach ($accounts as $class) {
            $typesOut = [];
            foreach ($class->accountTypes as $type) {
                $typeSlug = $type->slug ?? (string) $type->id;
                $details = [];
                foreach ($type->typeDetails as $detail) {
                    $details[] = [
                        'id' => $detail->id,
                        'name' => $detail->name,
                        'slug' => $detail->slug,
                        'description' => $detail->description,
                        'chart_account_type_id' => $detail->chart_account_type_id,
                    ];
                }
                $typesOut[$typeSlug] = [
                    'id' => $type->id,
                    'name' => $type->name,
                    'slug' => $typeSlug,
                    'description' => $type->description,
                    'details' => $details,
                ];
            }
            $hierarchy['classes'][$class->slug] = [
                'id' => $class->id,
                'name' => $class->name,
                'slug' => $class->slug,
                'description' => $class->getAttribute('description') ?? null,
                'types' => $typesOut,
            ];
        }

        return $hierarchy;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllChartAccountTypeDetails(): array
    {
        $rows = ChartAccountTypeDetail::query()
            ->with(['accountType.chartAccount'])
            ->get();

        return $this->mapDetailCollectionOrdered($rows)->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTypeDetailsByClass(string $classSlug): array
    {
        $class = ChartAccount::query()->where('slug', $classSlug)->first();
        if (! $class) {
            return [];
        }

        $rows = ChartAccountTypeDetail::query()
            ->whereHas('accountType', function ($q) use ($class) {
                $q->where('chart_account_id', $class->id);
            })
            ->with(['accountType.chartAccount'])
            ->get()
            ->sortBy(function (ChartAccountTypeDetail $d) {
                $cat = $d->accountType;

                return sprintf('%010d-%010d', $cat?->id ?? 0, $d->id);
            })
            ->values();

        return $this->mapDetailCollection($rows)->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllChartAccountTypes(): array
    {
        $rows = ChartAccountType::query()
            ->with('chartAccount')
            ->get()
            ->sortBy(function (ChartAccountType $t) {
                return sprintf('%010d-%010d', $t->chartAccount?->id ?? 0, $t->id);
            })
            ->values();

        return $rows->map(fn (ChartAccountType $t) => [
            'id' => $t->id,
            'chart_account_id' => $t->chart_account_id,
            'name' => $t->name,
            'slug' => $t->slug,
            'description' => $t->description,
            'class_id' => $t->chartAccount?->id,
            'class_slug' => $t->chartAccount?->slug,
            'class_name' => $t->chartAccount?->name,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTypeDetailsByTypeId(int $typeId): array
    {
        $rows = ChartAccountTypeDetail::query()
            ->where('chart_account_type_id', $typeId)
            ->orderBy('id')
            ->with(['accountType.chartAccount'])
            ->get();

        return $this->mapDetailCollection($rows)->all();
    }

    public function getTypeDetailById(int $detailId): ?array
    {
        $detail = ChartAccountTypeDetail::query()
            ->whereKey($detailId)
            ->with(['accountType.chartAccount'])
            ->first();

        if (! $detail) {
            return null;
        }

        return $this->mapDetailToRow($detail);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountStructure(): array
    {
        $accounts = ChartAccount::query()
            ->orderBy('id')
            ->with(['accountTypes' => fn ($q) => $q->orderBy('id')])
            ->get();

        $structure = [];
        foreach ($accounts as $ca) {
            $key = mb_strtolower(trim($ca->slug), 'UTF-8');
            $types = [];
            foreach ($ca->accountTypes as $t) {
                if ($t->name) {
                    $types[] = [
                        'id' => $t->id,
                        'name' => $t->name,
                        'slug' => $t->slug,
                        'description' => $t->description,
                    ];
                }
            }
            $structure[$key] = [
                'name' => $ca->name,
                'types' => $types,
            ];
        }

        return $structure;
    }

    /**
     * @param  Collection<int, ChartAccountTypeDetail>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function mapDetailCollectionOrdered(Collection $rows): Collection
    {
        $sorted = $rows->sortBy(function (ChartAccountTypeDetail $d) {
            $ca = $d->accountType?->chartAccount;
            $cat = $d->accountType;

            return sprintf(
                '%010d-%010d-%010d',
                $ca?->id ?? 0,
                $cat?->id ?? 0,
                $d->id
            );
        })->values();

        return $this->mapDetailCollection($sorted);
    }

    /**
     * @param  Collection<int, ChartAccountTypeDetail>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function mapDetailCollection(Collection $rows): Collection
    {
        return $rows->map(fn (ChartAccountTypeDetail $d) => $this->mapDetailToRow($d));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDetailToRow(ChartAccountTypeDetail $d): array
    {
        $ca = $d->accountType?->chartAccount;
        $cat = $d->accountType;

        return [
            'id' => $d->id,
            'chart_account_type_id' => $d->chart_account_type_id,
            'name' => $d->name,
            'slug' => $d->slug,
            'description' => $d->description,
            'type_id' => $cat?->id,
            'type_name' => $cat?->name,
            'type_slug' => $cat?->slug,
            'class_id' => $ca?->id,
            'class_slug' => $ca?->slug,
            'class_name' => $ca?->name,
        ];
    }
}
