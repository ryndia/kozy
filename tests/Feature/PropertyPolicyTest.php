<?php

namespace Tests\Feature;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_property_falls_back_to_the_house_defaults(): void
    {
        $property = Property::factory()->create([
            'terms_and_conditions' => null,
            'cancellation_policy' => null,
            'cancellation_fee_percent' => null,
        ]);

        $this->assertSame(config('kozy.policies.terms'), $property->terms());
        $this->assertSame(config('kozy.policies.cancellation'), $property->cancellationPolicy());
        $this->assertSame(
            (float) config('kozy.policies.cancellation_fee_percent'),
            $property->cancellationFeePercent(),
        );
    }

    public function test_a_property_keeps_its_own_policies(): void
    {
        $property = Property::factory()->create([
            'terms_and_conditions' => 'No shoes indoors.',
            'cancellation_policy' => 'Free until the day before.',
            'cancellation_fee_percent' => 40,
        ]);

        $this->assertSame('No shoes indoors.', $property->terms());
        $this->assertSame('Free until the day before.', $property->cancellationPolicy());
        $this->assertSame(40.0, $property->cancellationFeePercent());
    }

    public function test_blank_policy_text_falls_back_rather_than_showing_nothing(): void
    {
        $property = Property::factory()->create([
            'terms_and_conditions' => '   ',
            'cancellation_policy' => '',
        ]);

        $this->assertSame(config('kozy.policies.terms'), $property->terms());
        $this->assertSame(config('kozy.policies.cancellation'), $property->cancellationPolicy());
    }

    public function test_the_public_api_publishes_the_policies_and_the_vat_split(): void
    {
        $property = Property::factory()->create([
            'status' => 'active',
            'currency' => 'MUR',
            'price_per_night' => 4500,
            'cancellation_fee_percent' => 30,
        ]);

        $this->getJson("/api/properties/{$property->slug}")
            ->assertOk()
            ->assertJsonPath('data.cancellation_fee_percent', 30)
            ->assertJsonPath('data.vat.total', 4500)
            // 4,500 inclusive of 15% = 3,913.04 net + 586.96 tax.
            ->assertJsonPath('data.vat.net', 3913.04)
            ->assertJsonPath('data.vat.vat', 586.96)
            ->assertJsonStructure([
                'data' => ['terms_and_conditions', 'cancellation_policy'],
            ]);
    }
}
