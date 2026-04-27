<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartAccount extends Model
{
    /**
     * Books chart of accounts — top-level class (e.g. Assets, Liabilities).
     *
     * @see .junie/skills/laravel-best-practices (Eloquent, security: guarded)
     */
    protected $guarded = ['*'];

//    public function getTable()
//    {
//        return config('coa.model_connections.books_db') . '.chart_accounts';
//    }

    public function accountTypes(): HasMany
    {
        return $this->hasMany(ChartAccountType::class, 'chart_account_id');
    }
}
