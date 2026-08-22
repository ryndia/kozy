<?php

namespace App\Helpers;

class PropertyFeatureHelper
{
    /**
     * Get all available property features with their emoji icons
     */
    public static function getAvailableFeatures(): array
    {
        return [
            'WiFi' => '📶',
            'Pool' => '🏊',
            'Kitchen' => '🍳',
            'Air Conditioning' => '❄️',
            'Parking' => '🚗',
            'Garden' => '🌳',
            'Balcony' => '🏠',
            'TV' => '📺',
            'Washer' => '🧺',
            'Dryer' => '🧺',
            'Heating' => '🔥',
            'Gym' => '💪',
            'Pets Allowed' => '🐾',
            'No Smoking' => '🚭',
            'Elevator' => '🛗',
            'Desk' => '💼',
            'Bed' => '🛏️',
            'Bathroom' => '🚿',
            'Toilet' => '🚽',
            'Shower' => '🚿',
            'Hair Dryer' => '💇',
            'Iron' => '👔',
            'Hot Tub' => '♨️',
            'Sauna' => '♨️',
            'Terrace' => '🌂',
            'Patio' => '🌂',
            'Security' => '🔒',
            'Doorman' => '🚪',
            'Concierge' => '🔔',
            'Maid Service' => '🧹',
            'Breakfast' => '🍳',
            'Lunch' => '🍽️',
            'Dinner' => '🍽️',
            'Laundry' => '🧺',
            'Iron Board' => '👔',
            'Baby Crib' => '👶',
            'Essentials' => '🎒',
            'Towels' => '🧻',
            'Soap' => '🧼',
            'Shampoo' => '💆',
            'Internet' => '📶',
        ];
    }

    /**
     * Group features into categories for display in the UI.
     */
    public static function getCategorizedFeatures(): array
    {
        return [
            'Essentials' => [
                'WiFi',
                'Internet',
                'Kitchen',
                'Air Conditioning',
                'Heating',
                'Bathroom',
                'Toilet',
                'Shower',
                'Essentials',
            ],
            'Comfort' => [
                'Bed',
                'Towels',
                'Soap',
                'Shampoo',
                'Hair Dryer',
                'Iron',
                'TV',
                'Desk',
                'Laundry',
                'Dryer',
                'Washer',
            ],
            'Outside & Leisure' => [
                'Pool',
                'Garden',
                'Balcony',
                'Terrace',
                'Patio',
                'Hot Tub',
                'Sauna',
                'Gym',
                'Parking',
            ],
            'Family & Safety' => [
                'Pets Allowed',
                'No Smoking',
                'Security',
                'Doorman',
                'Concierge',
                'Elevator',
                'Baby Crib',
                'Maid Service',
            ],
            'Dining' => [
                'Breakfast',
                'Lunch',
                'Dinner',
                'Maid Service',
            ],
        ];
    }

    /**
     * Get emoji for a specific feature
     */
    public static function getFeatureEmoji(string $feature): string
    {
        $features = self::getAvailableFeatures();

        // Try direct match
        if (isset($features[$feature])) {
            return $features[$feature];
        }

        // Try case-insensitive match
        foreach ($features as $featureName => $emoji) {
            if (strtolower($featureName) === strtolower($feature)) {
                return $emoji;
            }
        }

        // Try partial match
        $lowerFeature = strtolower($feature);
        foreach ($features as $featureName => $emoji) {
            if (str_contains(strtolower($featureName), $lowerFeature) || str_contains($lowerFeature, strtolower($featureName))) {
                return $emoji;
            }
        }

        return '🏷️'; // Default emoji
    }

    /**
     * Format features array with emojis
     */
    public static function formatFeaturesWithEmojis(array $features): string
    {
        if (empty($features)) {
            return '—';
        }

        return implode(' ', array_map(function ($feature) {
            return self::getFeatureEmoji($feature);
        }, $features));
    }

    /**
     * Get feature options for select fields
     */
    public static function getFeatureOptions(): array
    {
        $features = self::getAvailableFeatures();
        $options = [];

        foreach ($features as $feature => $emoji) {
            $options[$feature] = $emoji.' '.$feature;
        }

        return $options;
    }

    public static function getFeatureGroupsForDisplay(): array
    {
        $groups = [];

        foreach (self::getCategorizedFeatures() as $category => $features) {
            $groups[$category] = array_values(array_filter(array_map(function ($feature) {
                if (! isset(self::getAvailableFeatures()[$feature])) {
                    return null;
                }

                return [
                    'name' => $feature,
                    'emoji' => self::getFeatureEmoji($feature),
                ];
            }, $features)));
        }

        return $groups;
    }
}
