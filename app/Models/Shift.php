<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'shift_group_id',
        'user_id',
        'employee_id',
        'name',
        'shift_date',
        'start_time',
        'end_time',
        'status',
        'opening_cash',
        'expected_cash',
        'actual_cash',
        'difference',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'opening_cash' => 'decimal:4',
        'expected_cash' => 'decimal:4',
        'actual_cash' => 'decimal:4',
        'difference' => 'decimal:4',
        'closed_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function shiftGroup(): BelongsTo
    {
        return $this->belongsTo(ShiftGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employeePayments(): HasMany
    {
        return $this->hasMany(ShiftEmployeePayment::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
"