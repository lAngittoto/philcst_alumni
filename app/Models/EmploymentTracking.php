<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * EmploymentTracking
 *
 * Tracks every alumni's employment status and career details.
 * Soft-deleted to preserve audit trails.
 * Career path is stored as a JSON array for efficient multi-select storage
 * without needing a pivot table.
 *
 * @property int         $id
 * @property int         $alumni_id
 * @property string      $employment_status   employed|self_employed|unemployed
 * @property string|null $company_name
 * @property string|null $job_title
 * @property string|null $employment_type     full_time|part_time|contractual|project_based|internship
 * @property string|null $work_location       local|abroad
 * @property string|null $date_hired
 * @property array|null  $career_path         JSON array of selected career paths
 * @property string|null $education_status    none|pursuing_masteral|pursuing_doctorate
 * @property string|null $course_relevance    yes|no|partially
 * @property string|null $unemployment_status seeking_employment|not_looking
 */
class EmploymentTracking extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'employment_trackings';

    /**
     * Mass-assignable columns.
     * Explicit whitelist — never use guarded: [] on sensitive models.
     */
    protected $fillable = [
        'alumni_id',
        'employment_status',
        'company_name',
        'job_title',
        'employment_type',
        'work_location',
        'date_hired',
        'career_path',
        'education_status',
        'course_relevance',
        'unemployment_status',
    ];

    /**
     * Attribute casting.
     * career_path is stored as JSON but returned as a PHP array automatically.
     */
    protected $casts = [
        'career_path' => 'array',
        'date_hired'  => 'date:Y-m-d',
    ];

    // ── Allowed enum values (used in validation + UI) ─────────────────────────

    public const EMPLOYMENT_STATUSES = [
        'employed'      => 'Employed',
        'self_employed' => 'Self-Employed',
        'unemployed'    => 'Unemployed',
    ];

    public const EMPLOYMENT_TYPES = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];

    public const WORK_LOCATIONS = [
        'local'  => 'Local',
        'abroad' => 'Abroad (OFW)',
    ];

    public const CAREER_PATHS = [
        'ofw'                  => '🌍 OFW',
        'freelancer'           => '💻 Freelancer',
        'entrepreneur'         => '💼 Entrepreneur',
        'career_shifter'       => '🔄 Career Shifter',
        'industry_professional'=> '🎓 Industry Professional',
    ];

    public const EDUCATION_STATUSES = [
        'none'               => 'None',
        'pursuing_masteral'  => 'Pursuing Masteral',
        'pursuing_doctorate' => 'Pursuing Doctorate',
    ];

    public const COURSE_RELEVANCES = [
        'yes'       => 'Yes',
        'no'        => 'No',
        'partially' => 'Partially',
    ];

    public const UNEMPLOYMENT_STATUSES = [
        'seeking_employment' => 'Seeking Employment',
        'not_looking'        => 'Currently Not Looking',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The alumni this record belongs to.
     */
    public function alumni(): BelongsTo
    {
        return $this->belongsTo(Alumni::class);
    }

    // ── Computed helpers (avoids repeated logic in controllers/views) ──────────

    /**
     * Returns true if the alumni has active employment.
     */
    public function isEmployed(): bool
    {
        return in_array($this->employment_status, ['employed', 'self_employed'], true);
    }

    /**
     * Returns true if the alumni is an OFW.
     */
    public function isOfw(): bool
    {
        return $this->work_location === 'abroad'
            || (is_array($this->career_path) && in_array('ofw', $this->career_path, true));
    }

    /**
     * Returns the human-readable employment status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::EMPLOYMENT_STATUSES[$this->employment_status] ?? $this->employment_status;
    }

    /**
     * Returns career path labels as an array.
     */
    public function getCareerPathLabelsAttribute(): array
    {
        if (empty($this->career_path)) {
            return [];
        }
        return array_map(
            fn($key) => self::CAREER_PATHS[$key] ?? $key,
            $this->career_path
        );
    }

    // ── Scopes (reusable query building blocks for dashboard/reports) ─────────

    public function scopeEmployed($query)
    {
        return $query->whereIn('employment_status', ['employed', 'self_employed']);
    }

    public function scopeUnemployed($query)
    {
        return $query->where('employment_status', 'unemployed');
    }

    public function scopeOfw($query)
    {
        return $query->where('work_location', 'abroad');
    }

    public function scopeLocal($query)
    {
        return $query->where('work_location', 'local');
    }

    public function scopeByBatch($query, string $batch)
    {
        return $query->whereHas('alumni', fn($q) => $q->where('batch', $batch));
    }

    public function scopeByCourse($query, string $courseCode)
    {
        return $query->whereHas('alumni', fn($q) => $q->where('course_code', $courseCode));
    }
}