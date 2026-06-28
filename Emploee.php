<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Emploee extends Model
{
    protected $table = 'emploees';

    protected $fillable = [
        'corporate_id',
        'name',
        'external_staff_code',
        'qualification',
        'employment_start_date',
        'employment_end_date',
    ];

    protected $casts = [
        'employment_start_date' => 'date',
        'employment_end_date' => 'date',
    ];

    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmploeeAssignment::class, 'staff_id');
    }

    // 資格の履歴（時点管理）。1人の職員に複数の「いつから何の資格」行がぶら下がる。
    // 外部キーは emploee_assignments と同じ慣例で staff_id。
    public function qualifications(): HasMany
    {
        return $this->hasMany(EmploeeQualification::class, 'staff_id');
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'staff_id');
    }

    public function facilityExternalCodes(): HasMany
    {
        return $this->hasMany(FacilityStaffExternalCode::class, 'staff_id');
    }
}
