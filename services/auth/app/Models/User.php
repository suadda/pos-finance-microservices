<?php

namespace App\Models;

use App\Models\Concerns\SerializesLocalDates;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use SerializesLocalDates;

    public const ROLES = ['superadmin', 'kasir', 'supervisor_pos', 'staff_finance', 'manager_finance'];

    /** Roles bound to a single outlet (outlet_id is mandatory). */
    public const OUTLET_ROLES = ['kasir', 'supervisor_pos'];

    /** Finance roles see every outlet (outlet_id is always NULL). */
    public const FINANCE_ROLES = ['staff_finance', 'manager_finance'];

    protected $fillable = ['name', 'email', 'password', 'role', 'outlet_id', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'outlet_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Public identity shape (also returned by GET /auth/me). */
    public function toIdentity(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'outlet_id' => $this->outlet_id,
            'is_active' => $this->is_active,
        ];
    }
}
