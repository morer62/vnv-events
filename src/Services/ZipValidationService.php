<?php

namespace App\Services;

use App\Repositories\ServiceZipPaymentsRepository;

class ZipValidationService {

    private ServiceZipPaymentsRepository $serviceZipPaymentsRepository;

    public function __construct() {
        $this->serviceZipPaymentsRepository = new ServiceZipPaymentsRepository();
    }

    public function isCityTaken(string $slug, int $categoryId): bool
    {
        $repo = new ServiceZipPaymentsRepository();
        $result = $repo->getOne([
            "city_slug" => $slug,
            "id_service_category" => $categoryId
        ]);

        return $result !== null;
    }

}