<?php

namespace App\Repositories;

class StoreProductFoodProfilesRepository
{
    private Connection $db;

    public function __construct()
    {
        $this->db = new Connection();
    }

    public function getForProduct(int $ownerId, string $siteKey, int $productId): object|bool
    {
        $this->db->query('SELECT * FROM store_product_food_profiles WHERE id_owner=:owner AND site_key=:site AND id_product=:product LIMIT 1');
        $this->db->bind(':owner', $ownerId);
        $this->db->bind(':site', $siteKey);
        $this->db->bind(':product', $productId);
        return $this->db->fetchOne();
    }

    public function upsert(int $ownerId, string $siteKey, int $productId, array $input): void
    {
        $minimum = max(1, (int)($input['minimum_servings'] ?? 1));
        $included = max($minimum, (int)($input['included_servings'] ?? $minimum));
        $maximum = isset($input['maximum_servings']) && $input['maximum_servings'] !== ''
            ? max($included, (int)$input['maximum_servings'])
            : null;
        $preferences = $this->normalizeList((string)($input['cooking_preferences'] ?? ''));

        $this->db->query('INSERT INTO store_product_food_profiles
            (id_owner,site_key,id_product,minimum_servings,included_servings,maximum_servings,additional_person_price,cooking_preferences_json,preparation_instructions,reheating_instructions,serving_instructions,plating_instructions,presentation_instructions)
            VALUES (:owner,:site,:product,:minimum,:included,:maximum,:additional,:preferences,:preparation,:reheating,:serving,:plating,:presentation)
            ON DUPLICATE KEY UPDATE minimum_servings=VALUES(minimum_servings),included_servings=VALUES(included_servings),maximum_servings=VALUES(maximum_servings),additional_person_price=VALUES(additional_person_price),cooking_preferences_json=VALUES(cooking_preferences_json),preparation_instructions=VALUES(preparation_instructions),reheating_instructions=VALUES(reheating_instructions),serving_instructions=VALUES(serving_instructions),plating_instructions=VALUES(plating_instructions),presentation_instructions=VALUES(presentation_instructions),updated_at=NOW()');
        foreach ([
            ':owner' => $ownerId, ':site' => $siteKey, ':product' => $productId,
            ':minimum' => $minimum, ':included' => $included, ':maximum' => $maximum,
            ':additional' => max(0, (float)($input['additional_person_price'] ?? 0)),
            ':preferences' => $preferences ? json_encode($preferences, JSON_UNESCAPED_UNICODE) : null,
            ':preparation' => $this->nullable($input['preparation_instructions'] ?? null),
            ':reheating' => $this->nullable($input['reheating_instructions'] ?? null),
            ':serving' => $this->nullable($input['serving_instructions'] ?? null),
            ':plating' => $this->nullable($input['plating_instructions'] ?? null),
            ':presentation' => $this->nullable($input['presentation_instructions'] ?? null),
        ] as $key => $value) {
            $this->db->bind($key, $value);
        }
        $this->db->execute();
    }

    public function preferencesText(object|bool $profile): string
    {
        if (!$profile || empty($profile->cooking_preferences_json)) {
            return '';
        }
        $values = json_decode((string)$profile->cooking_preferences_json, true);
        return is_array($values) ? implode("\n", $values) : '';
    }

    private function normalizeList(string $value): array
    {
        $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts), static fn($item) => $item !== '')));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
