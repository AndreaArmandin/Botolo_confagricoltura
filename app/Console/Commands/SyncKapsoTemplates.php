<?php

namespace App\Console\Commands;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Integrations\Kapso\Kapso;
use App\Integrations\Kapso\KapsoException;
use App\Models\Template;
use Illuminate\Console\Command;

class SyncKapsoTemplates extends Command
{
    protected $signature = 'kapso:sync-templates';

    protected $description = 'Read the templates from Kapso and refresh the local copy';

    public function handle(Kapso $kapso): int
    {
        try {
            $remote = $kapso->templates();
        } catch (KapsoException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (blank($remote)) {
            $this->warn('Nessun template trovato su Kapso.');

            return self::SUCCESS;
        }

        foreach ($remote as $row) {
            $templateData = [
                'body' => $this->bodyOf($row),
                'category' => $this->categoryOf($row),
                'status' => TemplateStatus::fromMeta($row['status'] ?? null),
                'external_id' => $row['id'] ?? null,
            ];
            $bodyExamples = $this->bodyExamplesOf($row);

            if ($bodyExamples !== null) {
                $templateData['body_examples'] = $bodyExamples;
            }

            Template::withoutEvents(function () use ($row, $templateData): void {
                Template::updateOrCreate(
                    [
                        'name' => $row['name'] ?? '',
                        'language' => $row['language'] ?? 'it',
                    ],
                    $templateData,
                );
            });
        }

        $this->table(
            ['nome', 'lingua', 'stato', 'campi'],
            Template::orderBy('name')->get()->map(fn (Template $t): array => [
                $t->name,
                $t->language,
                $t->status->value,
                count($t->placeholders()),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * Meta spezza il template in componenti (header, body, footer).
     * Il testo che ci interessa sta solo nel body.
     *
     * @param  array<string, mixed>  $row
     */
    protected function bodyOf(array $row): string
    {
        foreach ($row['components'] ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function categoryOf(array $row): TemplateCategory
    {
        return TemplateCategory::tryFrom(strtolower((string) ($row['category'] ?? '')))
            ?? TemplateCategory::Utility;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>|null
     */
    protected function bodyExamplesOf(array $row): ?array
    {
        foreach ($row['components'] ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                if (! isset($component['example']['body_text'][0])) {
                    return null;
                }

                $examples = [];

                foreach ($component['example']['body_text'][0] ?? [] as $position => $value) {
                    $examples[$position + 1] = (string) $value;
                }

                return $examples;
            }
        }

        return null;
    }
}
