<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sale_id
 * @property int $product_id
 * @property int $quantity
 * @property string $unit_price
 * @property string $unit_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SaleProduct extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'sales_products';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'unit_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
        ];
    }

    /**
     * Net profit of this line: (unit_price - unit_cost) * quantity, using the frozen values.
     */
    public function profit(): string
    {
        $unitProfitCents = Money::toCents($this->unit_price) - Money::toCents($this->unit_cost);

        return Money::fromCents($unitProfitCents * $this->quantity);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
