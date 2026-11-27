<?php

namespace App\Services;

final class VnvCommercialIntake
{
    public static function project(array $services, array $fields): array
    {
        // An enquiry about inventory is not consent to introduce a sponsor to a nonprofit.
        $destinations = [
            'Nonprofit Partnerships' => 'NONPROFIT',
            'Full Event Production' => 'CORPORATE',
            'Event Production' => 'CORPORATE',
            'Production Essential' => 'CORPORATE',
            'Advanced Production' => 'CORPORATE',
            'Brand Activation' => 'ACTIVATION',
            'Sponsorship Opportunities' => 'CORPORATE',
            'Event Software Demo' => 'CORPORATE',
        ];
        $matched = array_intersect_key($destinations, array_flip($services));
        $program = $matched ? reset($matched) : null;
        $read = static function (string $name, int $length) use ($fields, $program): string {
            $value = $fields[$name] ?? '';
            return $program && is_string($value) ? mb_substr(trim(preg_replace('/[[:cntrl:]]+/', ' ', $value)), 0, $length) : '';
        };
        $organization = $read('organization_name', 180);
        $budget = $read('budget_range', 80);
        $eventType = $read('project_event_type', 100);
        $notes = [];
        foreach (['Organization' => $organization, 'Estimated budget' => $budget, 'Event type' => $eventType] as $label => $value) {
            if ($value !== '') $notes[] = $label . ': ' . $value;
        }
        return ['program' => $program, 'organization_name' => $organization, 'budget_range' => $budget,
            'event_type' => $eventType, 'notes' => implode("\n", $notes)];
    }
}
