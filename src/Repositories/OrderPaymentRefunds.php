<?php

namespace App\Repositories;

class OrderPaymentRefunds extends BaseRepository
{

    public function __construct()
    {
        $this->table = "orders_payments_refunds";
        $this->db = new Connection();
        $this->fields = [
          'id',
          'payment_id',
          'refund_id',
          'refund_amount',
          'created_at'
        ];
    }
}