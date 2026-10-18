<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $category_id
 * @property string $name
 * @property string $detal_price
 * @property string $major_price
 * @property int $total_stock
 * @property string|null $img_route
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Product extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'detal_price',
        'major_price',
        'total_stock',
        'img_route',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detal_price' => 'decimal:2',
            'major_price' => 'decimal:2',
            'total_stock' => 'integer',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q) => $q->where('name', 'like', "%{$term}%"));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByCategory(Builder $query, ?int $categoryId): Builder
    {
        return $query->when($categoryId, fn (Builder $q) => $q->where('category_id', $categoryId));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('total_stock', '>', 0);
    }

    /**
     * Unit profit in money: detal_price (public price) minus major_price (wholesale cost).
     */
    public function profitMarginAmount(): string
    {
        return Money::fromCents(Money::toCents($this->detal_price) - Money::toCents($this->major_price));
    }

    /**
     * Markup over the wholesale cost, in percent: (detal_price - major_price) / major_price * 100.
     */
    public function profitMarginPercentage(): float
    {
        $costCents = Money::toCents($this->major_price);

        if ($costCents === 0) {
            return 0.0;
        }

        $profitCents = Money::toCents($this->detal_price) - $costCents;

        return round($profitCents / $costCents * 100, 2);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<SaleProduct, $this>
     */
    public function saleProducts(): HasMany
    {
        return $this->hasMany(SaleProduct::class);
    }
}
