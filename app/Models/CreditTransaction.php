<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const HOLD = 'hold';       // worst-case price reserved when a generation is submitted

    public const RELEASE = 'release'; // the reservation given back once the generation is final

    public const CHARGE = 'charge';   // the actual price of a succeeded generation

    protected $guarded = ['id'];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
