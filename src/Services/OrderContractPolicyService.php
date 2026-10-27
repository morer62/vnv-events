<?php

namespace App\Services;

use App\Repositories\Connection;
use RuntimeException;

final class OrderContractPolicyService
{
    public function __construct(private int $ownerId, private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    public function requiredGeneralConditionsId(): int
    {
        $this->db->query(
            "SELECT id, title
             FROM orders_contracts
             WHERE id_owner = :owner
               AND LOWER(TRIM(title)) IN ('general conditions', 'general terms and conditions')
             ORDER BY CASE WHEN LOWER(TRIM(title)) = 'general conditions' THEN 0 ELSE 1 END, id
             LIMIT 1"
        );
        $this->db->bind(':owner', $this->ownerId);
        $contract = $this->db->fetchOne();

        if (!$contract) {
            throw new RuntimeException(
                'No se puede crear el estimate: falta configurar el contrato obligatorio General Conditions.'
            );
        }

        return (int) $contract->id;
    }
}
