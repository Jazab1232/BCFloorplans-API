<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Traits\BelongsToOrganization;

class EmailTemplate extends Model
{
    use BelongsToOrganization;

    public $orgScopeIncludesGlobal = true;
    protected $fillable = [
        'uuid',
        'organization_id',
        'title',
        'content',
        'tags',
        'type',
        'event_type',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Auto-generate UUID
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Replace placeholders in the content with dynamic data.
     *
     * @param array $data
     * @return string
     */
    public function parseContent(array $data): string
    {
        $content = $this->content;
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }
        return $content;
    }
}
