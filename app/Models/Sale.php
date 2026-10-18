<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $client_id
 * @property int $user_id
 * @property string $total
 * @property Carbon $date
 * @property string $payment_method
 * @property string $payment_type
 * @property string|null $payment_proof_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Sale extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'user_id',
        'total',
        'date',
        'payment_method',
        'payment_type',
        'payment_proof_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Filters by the business date (`date`), never by `created_at`.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFilterByPeriod(Builder $query, ?string $period, ?string $startDate = null, ?string $endDate = null): Builder
    {
        $column = $this->qualifyColumn('date');

        return match ($period) {
            'today' => $query->whereBetween($column, [now()->startOfDay(), now()->endOfDay()]),
            'week' => $query->whereBetween($column, [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereBetween($column, [now()->startOfMonth(), now()->endOfMonth()]),
            'custom' => $query
                ->when($startDate, fn (Builder $q) => $q->where($column, '>=', Carbon::parse($startDate)->startOfDay()))
                ->when($endDate, fn (Builder $q) => $q->where($column, '<=', Carbon::parse($endDate)->endOfDay())),
            default => $query,
        };
    }

    /**
     * Net profit of the whole sale: the sum of each line's profit().
     */
    public function totalProfit(): string
    {
        $profitCents = 0;

        foreach ($this->saleProducts as $line) {
            $profitCents += Money::toCents($line->profit());
        }

        return Money::fromCents($profitCents);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SaleProduct, $this>
     */
    public function saleProducts(): HasMany
    {
        return $this->hasMany(SaleProduct::class, 'sale_id');
    }
}
