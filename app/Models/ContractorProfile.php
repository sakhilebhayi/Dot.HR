<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A contractor's own business details, kept current by the contractor and read by HR. No bank
 * details, by design (see the migration).
 *
 * @property int $id
 * @property int $team_id
 * @property int $employee_id
 * @property string|null $trading_name
 * @property string|null $registration_number
 * @property string|null $vat_number
 * @property string|null $billing_address
 * @property string|null $invoice_email
 */
class ContractorProfile extends Model
{
    use HasTeamScope;

    public const FIELDS = [
        'trading_name' => 'Trading name',
        'registration_number' => 'Company registration number',
        'vat_number' => 'VAT number',
        'billing_address' => 'Billing address',
        'invoice_email' => 'Email for invoices',
    ];

    protected $fillable = ['trading_name', 'registration_number', 'vat_number', 'billing_address', 'invoice_email'];
}
