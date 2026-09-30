<?php

namespace App\Support;

/** Identity resolved from the Auth Service (GET /auth/me). */
final class AuthUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly ?int $outletId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            (string) $data['name'],
            (string) $data['email'],
            (string) $data['role'],
            isset($data['outlet_id']) ? (int) $data['outlet_id'] : null,
        );
    }

    public function is(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
