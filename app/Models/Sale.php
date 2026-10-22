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
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int|null $client_id
 * @property int $user_id
 * @property string $total
 * @property string $initial_payment
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
     * Fuente única de los métodos de pago: valor almacenado => etiqueta visible.
     * La usan el POS, los filtros del historial y el formulario de abonos.
     *
     * @var array<string, string>
     */
    public const PAYMENT_METHODS = [
        'pago_movil' => 'Pago Móvil',
        'zelle' => 'Zelle',
        'efectivo' => 'Efectivo',
        'binance' => 'Binance',
        'punto' => 'Punto',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'user_id',
        'total',
        'initial_payment',
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
            'initial_payment' => 'decimal:2',
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

    public function paymentMethodLabel(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }

    /**
     * Formato de fecha visible en toda la aplicación (12 horas, AM/PM).
     */
    public function dateLabel(): string
    {
        return $this->date->format('d/m/Y g:i A');
    }

    /**
     * Expresión SQL del monto pagado (inicial + abonos). Fuente única para filtros y KPIs,
     * así "crédito pendiente" se calcula igual en la tabla y en las tarjetas.
     *
     * @return literal-string
     */
    public static function paidAmountSql(): string
    {
        // Contado: se pagó el total al registrar la venta. Crédito: inicial + abonos.
        return "(CASE WHEN sales.payment_type = 'contado' THEN sales.total ELSE sales.initial_payment + COALESCE((SELECT SUM(sale_payments.amount) FROM sale_payments WHERE sale_payments.sale_id = sales.id), 0) END)";
    }

    /**
     * Total pagado: inicial más abonos. Si la consulta trae withSum('payments', 'amount'),
     * usa ese valor y no hace consultas extra (importante en tablas paginadas).
     */
    public function paidAmount(): string
    {
        // Una venta de contado se paga completa al registrarse: no queda nada pendiente.
        if ($this->payment_type === 'contado') {
            return Money::fromCents(Money::toCents($this->total));
        }

        // OJO: withSum devuelve NULL cuando la venta no tiene abonos. No se puede usar
        // "es null" para decidir si hay que consultar: hay que mirar si el atributo existe.
        $attributes = $this->getAttributes();

        $payments = array_key_exists('payments_sum_amount', $attributes)
            ? (float) $attributes['payments_sum_amount']
            : (float) ($this->relationLoaded('payments')
                ? $this->payments->sum('amount')
                : $this->payments()->sum('amount'));

        return Money::fromCents(Money::toCents($this->initial_payment) + Money::toCents($payments));
    }

    /**
     * Pending balance of a credit sale. Derived, never stored, so it cannot drift.
     */
    public function balanceDue(): string
    {
        $balanceCents = Money::toCents($this->total) - Money::toCents($this->paidAmount());

        return Money::fromCents(max(0, $balanceCents));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFilterByStatus(Builder $query, ?string $status): Builder
    {
        $paid = self::paidAmountSql();

        return match ($status) {
            'contado' => $query->where('sales.payment_type', 'contado'),
            'credito_pending' => $query
                ->where('sales.payment_type', 'credito')
                ->where(DB::raw($paid), '<', DB::raw('sales.total')),
            'credito_paid' => $query
                ->where('sales.payment_type', 'credito')
                ->where(DB::raw($paid), '>=', DB::raw('sales.total')),
            default => $query,
        };
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFilterByMethod(Builder $query, ?string $method): Builder
    {
        return $query->when(
            $method !== null && $method !== '' && $method !== 'all',
            fn (Builder $q) => $q->where('sales.payment_method', $method),
        );
    }

    /**
     * "#12" busca por ID de venta; el resto busca por nombre o teléfono del cliente.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // "#12" es el atajo explícito al ID de la venta.
        if (str_starts_with($term, '#')) {
            return $query->where('sales.id', (int) substr($term, 1));
        }

        $clientFilter = fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%");

        // Un número suelto puede ser el ID (el de la primera columna) o un teléfono.
        if (ctype_digit($term)) {
            return $query->where(function (Builder $q) use ($term, $clientFilter): void {
                $q->where('sales.id', (int) $term)->orWhereHas('client', $clientFilter);
            });
        }

        return $query->whereHas('client', $clientFilter);
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

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }
}
