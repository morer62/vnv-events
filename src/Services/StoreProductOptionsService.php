<?php

namespace App\Services;

use App\Repositories\Connection;
use RuntimeException;

/** Generic product option groups with server-side exact-count validation. */
final class StoreProductOptionsService
{
    public function __construct(private ?Connection $db = null)
    {
        $this->db ??= new Connection();
    }

    /** @return array<int,array<string,mixed>> */
    public function groupsForProduct(int $ownerId, string $siteKey, int $productId): array
    {
        try {
            $this->db->query("SELECT g.* FROM store_product_option_groups g WHERE g.id_owner=:owner AND g.site_key=:site AND g.id_product=:product AND g.status='ACTIVE' ORDER BY g.sort_order,g.id");
            $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
            $this->db->bind(':site', $siteKey);
            $this->db->bind(':product', $productId, \PDO::PARAM_INT);
            $groups = $this->db->fetchAll() ?: [];
            $result = [];
            foreach ($groups as $group) {
                $choiceSetKey = trim((string)($group->choice_set_key ?? ''));
                if ($choiceSetKey !== '') {
                    $this->db->query("SELECT c.* FROM store_product_option_choices c INNER JOIN store_product_option_groups source_group ON source_group.id=c.id_group WHERE source_group.id_owner=:owner AND source_group.site_key=:site AND source_group.choice_set_key=:choice_set AND c.status='ACTIVE' ORDER BY CASE WHEN FIND_IN_SET(:preferred_tag,c.tags)>0 THEN 0 ELSE 1 END,c.sort_order,c.id");
                    $this->db->bind(':owner', $ownerId, \PDO::PARAM_INT);
                    $this->db->bind(':site', $siteKey);
                    $this->db->bind(':choice_set', $choiceSetKey);
                    $this->db->bind(':preferred_tag', trim((string)($group->preferred_tag ?? '')));
                } else {
                    $this->db->query("SELECT * FROM store_product_option_choices WHERE id_group=:group_id AND status='ACTIVE' ORDER BY sort_order,id");
                    $this->db->bind(':group_id', (int)$group->id, \PDO::PARAM_INT);
                }
                $choices = array_map(static fn(object $row): array => (array)$row, $this->db->fetchAll() ?: []);
                $preferredTag = trim((string)($group->preferred_tag ?? ''));
                foreach ($choices as &$choice) {
                    $tags = array_values(array_filter(array_map('trim', explode(',', (string)($choice['tags'] ?? '')))));
                    $choice['tag_list'] = $tags;
                    $choice['is_preferred'] = $preferredTag !== '' && in_array($preferredTag, $tags, true);
                    $choice['is_premium'] = in_array('premium', $tags, true) || (float)($choice['price_modifier'] ?? 0) > 0;
                    $choice['price_modifier'] = round((float)($choice['price_modifier'] ?? 0), 2);
                }
                unset($choice);
                $this->db->query("SELECT id_product_variation,required_count,pieces_per_option FROM store_product_variation_option_rules WHERE id_group=:group_id");
                $this->db->bind(':group_id', (int)$group->id, \PDO::PARAM_INT);
                $rules = [];
                foreach ($this->db->fetchAll() ?: [] as $rule) {
                    $rules[(string)(int)$rule->id_product_variation] = [
                        'required_count' => (int)$rule->required_count,
                        'pieces_per_option' => (int)$rule->pieces_per_option,
                    ];
                }
                $row = (array)$group;
                $row['choices'] = $choices;
                $row['variation_rules'] = $rules;
                $result[] = $row;
            }
            return $result;
        } catch (\Throwable $e) {
            // Allows code deployment before the accompanying migration is applied.
            error_log('[Store product options] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate untrusted browser selections and return a trusted order-line snapshot.
     * @param array<string,mixed> $submitted
     * @return array<int,array<string,mixed>>
     */
    public function validate(int $ownerId, string $siteKey, int $productId, ?int $variationId, array $submitted): array
    {
        $groups = $this->groupsForProduct($ownerId, $siteKey, $productId);
        if ($groups === []) return [];

        $byGroup = [];
        foreach (($submitted['option_groups'] ?? []) as $group) {
            if (is_array($group)) $byGroup[(int)($group['group_id'] ?? 0)] = $group;
        }

        $snapshot = [];
        foreach ($groups as $group) {
            $rule = $variationId ? ($group['variation_rules'][(string)$variationId] ?? null) : null;
            $required = (int)($rule['required_count'] ?? $group['required_count_default'] ?? 0);
            $pieces = (int)($rule['pieces_per_option'] ?? $group['pieces_per_option_default'] ?? 0);
            $submittedGroup = $byGroup[(int)$group['id']] ?? [];
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($submittedGroup['selected_choice_ids'] ?? [])))));
            if (count($selectedIds) !== $required) {
                throw new RuntimeException(sprintf('Choose exactly %d %s before adding this item.', $required, strtolower((string)$group['name'])));
            }
            $choices = [];
            foreach ($group['choices'] as $choice) $choices[(int)$choice['id']] = $choice;
            $clean = [];
            foreach ($selectedIds as $choiceId) {
                if (!isset($choices[$choiceId])) throw new RuntimeException('One selected product option is no longer available.');
                $choice = $choices[$choiceId];
                $clean[] = [
                    'choice_id' => $choiceId,
                    'name' => (string)$choice['name'],
                    'slug' => (string)$choice['slug'],
                    'pieces' => $pieces,
                    'serve_label' => (string)($choice['serve_label'] ?? ''),
                    'price_modifier' => round((float)($choice['price_modifier'] ?? 0), 2),
                    'serving_instructions' => (string)($choice['serving_instructions'] ?? ''),
                ];
            }
            $modifierTotal = round(array_sum(array_map(static fn(array $choice): float => (float)$choice['price_modifier'], $clean)), 2);
            $snapshot[] = [
                'group_id' => (int)$group['id'],
                'group_name' => (string)$group['name'],
                'option_type' => (string)$group['option_type'],
                'required_count' => $required,
                'pieces_per_option' => $pieces,
                'price_modifier_total' => $modifierTotal,
                'selections' => $clean,
            ];
        }
        return $snapshot;
    }
}
