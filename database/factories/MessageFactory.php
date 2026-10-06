<?php

namespace Database\Factories;

use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'template_id' => Template::factory(),
            'to' => '39'.fake()->numerify('#########'),
            'body' => 'Ciao Mario, la tua quota scade il 31/12/2026.',
            'status' => MessageStatus::Queued,
        ];
    }
}
