<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartAccountTypeDetail extends Model
{
    protected $guarded = ['*'];

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(ChartAccountType::class, 'chart_account_type_id');
    }
}
