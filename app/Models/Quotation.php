<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'status', 'approved_by', 'responded_by', 'responded_at'];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        // Includes deleted requests, so quotation/assignment history never loses its request.
        return $this->belongsTo(ServiceRequest::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function pdf(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'pdf_file_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }
}
