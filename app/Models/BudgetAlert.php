<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetAlert extends Model
{
    public $timestamps    = false; // hanya pakai created_at via useCurrent()
    public $incrementing  = true;

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            $model->created_at = now();
        });
    }

    protected $fillable = [
        'budget_id',
        'threshold',
    ];

    protected $casts = [
        'threshold'  => 'integer',
        'created_at' => 'datetime',
    ];

    const UPDATED_AT = null; // beritahu Eloquent tidak ada kolom updated_at

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }
}
