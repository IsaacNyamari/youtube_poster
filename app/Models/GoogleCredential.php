<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's own Google Cloud OAuth client. The secret is encrypted at rest and never sent to the browser.
 */
#[Fillable(['user_id', 'client_id', 'client_secret', 'project_id'])]
#[Hidden(['client_secret'])]
class GoogleCredential extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
        ];
    }

    public function maskedSecret(): string
    {
        return str_repeat('•', 12).substr((string) $this->client_secret, -4);
    }
}
