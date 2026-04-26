<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartAccountType extends Model
{
    protected $guarded = ['*'];

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'chart_account_id');
    }

    public function typeDetails(): HasMany
    {
        return $this->hasMany(ChartAccountTypeDetail::class, 'chart_account_type_id');
    }
}
