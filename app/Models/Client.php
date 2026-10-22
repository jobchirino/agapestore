<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Client extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'address',
        'email',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q) => $q->where(function (Builder $group) use ($term): void {
            $group->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('address', 'like', "%{$term}%");
        }));
    }

    /**
     * Agregados de cada cliente en UNA sola consulta (sin N+1).
     *
     * withCount / withSum / withMax agregan subconsultas correlacionadas. La deuda necesita
     * una expresión propia (total - inicial - abonos), por eso va en addSelect con la misma
     * expresión SQL que usa el Historial.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithMetrics(Builder $query): Builder
    {
        $paid = Sale::paidAmountSql();

        return $query
            ->withCount('sales as purchases_count')
            ->withSum('sales as total_spent', 'total')
            ->withMax('sales as last_purchase_at', 'date')
            ->addSelect([
                'pending_cents' => Sale::query()
                    ->selectRaw("COALESCE(SUM(ROUND((sales.total - {$paid}) * 100)), 0)")
                    ->whereColumn('sales.client_id', 'clients.id')
                    ->where('sales.payment_type', 'credito')
                    ->where(DB::raw('sales.total'), '>', DB::raw($paid)),
            ]);
    }

    /**
     * Cantidad de compras. Usa el agregado cargado; si no vino, consulta (caso de un solo cliente).
     */
    public function purchasesCount(): int
    {
        $attributes = $this->getAttributes();

        return array_key_exists('purchases_count', $attributes)
            ? (int) $attributes['purchases_count']
            : $this->sales()->count();
    }

    public function totalSpent(): string
    {
        $attributes = $this->getAttributes();

        // withSum devuelve NULL cuando no hay ventas: no confundir con "no cargado".
        $total = array_key_exists('total_spent', $attributes)
            ? (float) $attributes['total_spent']
            : (float) $this->sales()->sum('total');

        return Money::fromCents(Money::toCents($total));
    }

    public function pendingBalance(): string
    {
        $attributes = $this->getAttributes();

        if (array_key_exists('pending_cents', $attributes)) {
            return Money::fromCents((int) $attributes['pending_cents']);
        }

        $paid = Sale::paidAmountSql();

        $row = Sale::query()
            ->where('sales.client_id', $this->getKey())
            ->where('sales.payment_type', 'credito')
            ->where(DB::raw('sales.total'), '>', DB::raw($paid))
            ->selectRaw("COALESCE(SUM(ROUND((sales.total - {$paid}) * 100)), 0) as cents")
            ->first();

        return Money::fromCents((int) ($row?->getAttribute('cents') ?? 0));
    }

    public function lastPurchaseAt(): ?Carbon
    {
        $attributes = $this->getAttributes();

        $value = array_key_exists('last_purchase_at', $attributes)
            ? $attributes['last_purchase_at']
            : $this->sales()->max('date');

        return $value ? Carbon::parse($value) : null;
    }

    public function hasPendingBalance(): bool
    {
        return (float) $this->pendingBalance() > 0;
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
