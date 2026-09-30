<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Shift;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data isolation rules:
 *   kasir          -> only own shifts / transactions
 *   supervisor_pos -> everything in own outlet
 *   superadmin     -> everything
 * Out-of-scope records are reported as 404 so their existence is not leaked.
 */
final class AccessScope
{
    public static function apply(Builder $query, AuthUser $user): Builder
    {
        return match ($user->role) {
            'superadmin' => $query,
            'supervisor_pos' => $query->where('outlet_id', $user->outletId),
            'kasir' => $query->where('cashier_id', $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function canSee(AuthUser $user, Transaction|Shift $record): bool
    {
        return match ($user->role) {
            'superadmin' => true,
            'supervisor_pos' => $record->outlet_id === $user->outletId,
            'kasir' => $record->cashier_id === $user->id,
            default => false,
        };
    }

    public static function ensureCanSee(AuthUser $user, Transaction|Shift $record): void
    {
        if (! self::canSee($user, $record)) {
            throw ApiException::notFound($record instanceof Shift ? 'Shift tidak ditemukan' : 'Transaksi tidak ditemukan');
        }
    }

    /** Operating a shift/transaction (pay, close) is reserved to its own cashier or a superadmin. */
    public static function ensureOwner(AuthUser $user, Transaction|Shift $record): void
    {
        self::ensureCanSee($user, $record);
        if (! $user->is('superadmin') && $record->cashier_id !== $user->id) {
            throw ApiException::forbidden('Hanya kasir pemilik yang dapat melakukan aksi ini');
        }
    }
}
