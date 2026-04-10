<?php

namespace App\Entity;

class UserAppleJwt
{
    public string $iss;
    public string $aud;
    public int $exp;
    public int $iat;
    public string $sub;
    public string $at_hash;
    public string $email;
    public bool $email_verified;
    public bool $is_private_email;
    public int $auth_time;
    public bool $nonce_supported;

    public function __construct(array $data)
    {
        // Recorre cada propiedad del array y asigna si existe en el DTO
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}