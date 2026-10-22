<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Abono posterior a la venta. La inicial NO vive aquí: está en sales.initial_payment.
 *
 * @property int $id
 * @property int $sale_id
 * @property string $amount
 * @property Carbon $date
 * @property string $payment_method
 * @property string|null $payment_proof_path
 * @property string|null $notes
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SalePayment extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sale_id',
        'amount',
        'date',
        'payment_method',
        'payment_proof_path',
        'notes',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'datetime',
        ];
    }

    public function paymentMethodLabel(): string
    {
        return Sale::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }

    public function dateLabel(): string
    {
        return $this->date->format('d/m/Y g:i A');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
