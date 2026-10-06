<?php

namespace Database\Factories;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => str_replace('-', '_', fake()->unique()->slug(2)),
            'language' => 'it',
            'category' => TemplateCategory::Utility,
            'body' => 'Ciao {{1}}, la tua quota scade il {{2}}.',
            'body_examples' => [1 => 'Mario', 2 => '31/12/2026'],
            'status' => TemplateStatus::Draft,
            'external_id' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => TemplateStatus::Approved]);
    }
}
