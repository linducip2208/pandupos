<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Cheque extends Model
{
    use BelongsToTenant;

    public const RECEIVED = 'received';

    public const ISSUED = 'issued';

    public const DEPOSITED = 'deposited';

    public const CLEARED = 'cleared';

    public const BOUNCED = 'bounced';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'type', 'contact_id', 'bank_name', 'cheque_no', 'amount',
        'issue_date', 'due_date', 'status', 'deposit_id', 'bounce_reason', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'issue_date' => 'date', 'due_date' => 'date'];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function deposit()
    {
        return $this->belongsTo(ChequeDeposit::class, 'deposit_id');
    }
}
