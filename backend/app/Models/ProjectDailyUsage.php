<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDailyUsage extends Model
{
    protected $table = 'project_daily_usage';

    protected $fillable = ['project_id', 'usage_date', 'message_count'];

    protected function casts(): array
    {
        return ['usage_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
