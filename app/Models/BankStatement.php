<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported cartola. The uploaded file is kept on the `cartolas` disk
 * so a disputed reconciliation can be traced back to the exact export it
 * came from.
 */
class BankStatement extends Model
{
    protected $table = 'bank_statements';

    protected $fillable = [
        'bank',
        'account_number',
        'period_start',
        'period_end',
        'original_filename',
        'stored_path',
        'file_hash',
        'imported_by',
        'movement_count',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(BankMovement::class);
    }
}
