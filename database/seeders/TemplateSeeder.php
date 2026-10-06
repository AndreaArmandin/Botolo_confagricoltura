<?php

namespace Database\Seeders;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Template;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Prepara una bozza. L'approvazione deve arrivare da Meta.
     */
    public function run(): void
    {
        Template::firstOrCreate(
            ['name' => 'promemoria_scadenza_quota', 'language' => 'it'],
            [
                'category' => TemplateCategory::Utility,
                'body' => 'Ciao {{1}}, ti ricordiamo che la tua quota associativa Confagricoltura scade il {{2}}. Per il rinnovo puoi contattare la tua sede provinciale.',
                'body_examples' => [1 => 'Mario', 2 => '31/12/2026'],
                'status' => TemplateStatus::Draft,
            ],
        );
    }
}
