<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Carbon\Carbon;

class AuditLog extends Model
{
    // ── Config ────────────────────────────────────────────────────────────────

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id', 'user_role', 'user_name', 'user_email',
        'action', 'module', 'subject_id', 'subject_type', 'subject_label',
        'old_values', 'new_values', 'description',
        'ip_address', 'user_agent', 'session_id',
        'severity', 'is_flagged', 'flag_reason',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'is_flagged' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Audit logs are immutable — never allow mass-update
    public static bool $allowUpdates = false;

    // Philippine Timezone
    const TIMEZONE = 'Asia/Manila';

    // Constants for actions
    const ACTION_LOGIN            = 'login';
    const ACTION_LOGOUT           = 'logout';
    const ACTION_FAILED_LOGIN     = 'failed_login';
    const ACTION_ACCOUNT_LOCKED   = 'account_locked';
    const ACTION_CREATED          = 'created';
    const ACTION_UPDATED          = 'updated';
    const ACTION_DELETED          = 'deleted';
    const ACTION_RESTORED         = 'restored';
    const ACTION_VERIFIED         = 'verified';
    const ACTION_REJECTED         = 'rejected';
    const ACTION_EXPORTED         = 'exported';
    const ACTION_PASSWORD_CHANGED = 'password_changed';
    const ACTION_STATUS_CHANGED   = 'status_changed';
    const ACTION_SUSPENDED        = 'suspended';
    const ACTION_VIEWED           = 'viewed';

    // Constants for modules
    const MODULE_AUTH        = 'auth';
    const MODULE_ALUMNI      = 'alumni';
    const MODULE_ORGANIZER   = 'organizer';
    const MODULE_EVENT       = 'event';
    const MODULE_JOB_POSTING = 'job_posting';
    const MODULE_USER        = 'user';
    const MODULE_SYSTEM      = 'system';

    // Constants for severity
    const SEV_INFO     = 'info';
    const SEV_WARNING  = 'warning';
    const SEV_CRITICAL = 'critical';

    // ── Relationship ──────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeByModule(Builder $q, ?string $module): Builder
    {
        return $module ? $q->where('module', $module) : $q;
    }

    public function scopeByAction(Builder $q, ?string $action): Builder
    {
        return $action ? $q->where('action', $action) : $q;
    }

    public function scopeBySeverity(Builder $q, ?string $severity): Builder
    {
        return $severity ? $q->where('severity', $severity) : $q;
    }

    public function scopeByRole(Builder $q, ?string $role): Builder
    {
        return $role ? $q->where('user_role', $role) : $q;
    }

    public function scopeFlagged(Builder $q): Builder
    {
        return $q->where('is_flagged', true);
    }

    /**
     * Scope for "today" based on Philippine Time (Asia/Manila).
     */
    public function scopeToday(Builder $q): Builder
    {
        $nowPH = Carbon::now(self::TIMEZONE);
        return $q->whereDate('created_at', $nowPH->toDateString());
    }

    public function scopeDateRange(Builder $q, ?string $from, ?string $to): Builder
    {
        if ($from) $q->whereDate('created_at', '>=', $from);
        if ($to)   $q->whereDate('created_at', '<=', $to);
        return $q;
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (!$term) return $q;

        return $q->where(function ($sub) use ($term) {
            $sub->where('description',    'like', "%{$term}%")
                ->orWhere('user_name',    'like', "%{$term}%")
                ->orWhere('user_email',   'like', "%{$term}%")
                ->orWhere('subject_label','like', "%{$term}%")
                ->orWhere('ip_address',   'like', "%{$term}%");
        });
    }

    // ── Core Logger ───────────────────────────────────────────────────────────

    /**
     * Universal log method — call this from anywhere.
     *
     * Usage:
     *   AuditLog::log('created', 'alumni', 'Alumni John Doe was registered.', [
     *       'subject_id'    => $alumni->id,
     *       'subject_label' => $alumni->name,
     *       'new_values'    => $alumni->toArray(),
     *   ]);
     */
    public static function log(
        string $action,
        string $module,
        string $description,
        array  $options = []
    ): self {
        $user     = $options['user'] ?? Auth::user();
        $severity = $options['severity'] ?? self::resolveSeverity($action, $module);
        $flagged  = $options['is_flagged'] ?? self::shouldFlag($action, $severity);

        return self::create([
            'user_id'       => $options['user_id']    ?? ($user?->id),
            'user_role'     => $options['user_role']  ?? ($user?->role ?? 'system'),
            'user_name'     => $options['user_name']  ?? ($user ? self::resolveUserName($user) : 'System'),
            'user_email'    => $options['user_email'] ?? ($user?->email),
            'action'        => $action,
            'module'        => $module,
            'subject_id'    => $options['subject_id']    ?? null,
            'subject_type'  => $options['subject_type']  ?? null,
            'subject_label' => $options['subject_label'] ?? null,
            'old_values'    => $options['old_values']    ?? null,
            'new_values'    => $options['new_values']    ?? null,
            'description'   => $description,
            'ip_address'    => Request::ip(),
            'user_agent'    => self::ua(),
            'session_id'    => session()->getId(),
            'severity'      => $severity,
            'is_flagged'    => $flagged,
            'flag_reason'   => $options['flag_reason'] ?? ($flagged ? self::getFlagReason($action, $severity) : null),
        ]);
    }

    // ── Shortcut Loggers ──────────────────────────────────────────────────────

    /**
     * Log a successful or failed login.
     */
    public static function logLogin(
        array  $userInfo,
        bool   $success,
        string $reason = ''
    ): self {
        $action   = $success ? self::ACTION_LOGIN : self::ACTION_FAILED_LOGIN;
        $severity = $success ? self::SEV_INFO     : self::SEV_WARNING;
        $flagged  = ! $success;
        $name     = $userInfo['name'] ?? 'Unknown';

        return self::create([
            'user_id'      => $userInfo['id']    ?? null,
            'user_role'    => $userInfo['role']  ?? 'unknown',
            'user_name'    => $name,
            'user_email'   => $userInfo['email'] ?? null,
            'action'       => $action,
            'module'       => self::MODULE_AUTH,
            'subject_id'   => $userInfo['id']    ?? null,
            'subject_type' => 'App\\Models\\User',
            'subject_label'=> $name,
            'description'  => $success
                ? "User '{$name}' logged in successfully."
                : "Failed login attempt for '{$name}'" . ($reason ? ": {$reason}" : '.'),
            'ip_address'   => Request::ip(),
            'user_agent'   => self::ua(),
            'session_id'   => session()->getId(),
            'severity'     => $severity,
            'is_flagged'   => $flagged,
            'flag_reason'  => $flagged ? 'Failed authentication attempt' : null,
        ]);
    }

    /**
     * Log a logout event.
     */
    public static function logLogout(array $userInfo): self
    {
        return self::create([
            'user_id'    => $userInfo['id'],
            'user_role'  => $userInfo['role'],
            'user_name'  => $userInfo['name'],
            'user_email' => $userInfo['email'],
            'action'     => self::ACTION_LOGOUT,
            'module'     => self::MODULE_AUTH,
            'description'=> "User '{$userInfo['name']}' logged out.",
            'ip_address' => Request::ip(),
            'user_agent' => self::ua(),
            'session_id' => session()->getId(),
            'severity'   => self::SEV_INFO,
        ]);
    }

    /**
     * Log an account lock event.
     */
    public static function logAccountLocked(string $identifier, int $attempts, ?int $userId = null): self
    {
        return self::create([
            'user_id'      => $userId,
            'user_role'    => 'system',
            'user_name'    => 'System',
            'action'       => self::ACTION_ACCOUNT_LOCKED,
            'module'       => self::MODULE_AUTH,
            'subject_label'=> $identifier,
            'description'  => "Account '{$identifier}' locked for 10 minutes after {$attempts} consecutive failed login attempts.",
            'ip_address'   => Request::ip(),
            'user_agent'   => self::ua(),
            'session_id'   => session()->getId(),
            'severity'     => self::SEV_CRITICAL,
            'is_flagged'   => true,
            'flag_reason'  => "Account locked after {$attempts} failed attempts — possible brute force",
        ]);
    }

    /**
     * Log a model change (created/updated/deleted).
     */
    public static function logModel(
        string $action,
        string $module,
        Model  $subject,
        string $label,
        array  $oldValues = [],
        array  $newValues = []
    ): self {
        $actor     = Auth::user();
        $actorName = $actor ? self::resolveUserName($actor) : 'System';

        return self::log($action, $module,
            ucfirst($action) . " '{$label}' by {$actorName}.",
            [
                'subject_id'    => $subject->getKey(),
                'subject_type'  => get_class($subject),
                'subject_label' => $label,
                'old_values'    => $oldValues ?: null,
                'new_values'    => $newValues ?: null,
            ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function resolveUserName($user): string
    {
        if ($user->role === 'organizer') {
            return optional($user->organizer)->name ?? $user->name;
        }
        if ($user->role === 'alumni') {
            return optional($user->alumni)->name ?? $user->name;
        }
        return $user->name;
    }

    private static function resolveSeverity(string $action, string $module): string
    {
        $criticalActions = [
            self::ACTION_ACCOUNT_LOCKED,
            self::ACTION_DELETED,
            self::ACTION_SUSPENDED,
            self::ACTION_PASSWORD_CHANGED,
        ];
        $warningActions = [
            self::ACTION_FAILED_LOGIN,
            self::ACTION_REJECTED,
            self::ACTION_RESTORED,
            self::ACTION_STATUS_CHANGED,
        ];

        if (in_array($action, $criticalActions)) return self::SEV_CRITICAL;
        if (in_array($action, $warningActions))  return self::SEV_WARNING;
        return self::SEV_INFO;
    }

    private static function shouldFlag(string $action, string $severity): bool
    {
        $flagActions = [
            self::ACTION_ACCOUNT_LOCKED,
            self::ACTION_FAILED_LOGIN,
            self::ACTION_DELETED,
            self::ACTION_SUSPENDED,
        ];
        return $severity === self::SEV_CRITICAL || in_array($action, $flagActions);
    }

    private static function getFlagReason(string $action, string $severity): ?string
    {
        return match ($action) {
            self::ACTION_ACCOUNT_LOCKED   => 'Account locked — possible brute force',
            self::ACTION_FAILED_LOGIN     => 'Failed authentication attempt',
            self::ACTION_DELETED          => 'Record deleted — requires audit review',
            self::ACTION_SUSPENDED        => 'Account suspension performed',
            self::ACTION_PASSWORD_CHANGED => 'Password changed — security event',
            default => $severity === self::SEV_CRITICAL ? 'Critical system action' : null,
        };
    }

    private static function ua(): string
    {
        return substr(Request::userAgent() ?? 'Unknown', 0, 512);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getSeverityBadgeAttribute(): string
    {
        return match ($this->severity) {
            'critical' => 'bg-red-100 text-red-700 border-red-200',
            'warning'  => 'bg-yellow-100 text-yellow-700 border-yellow-200',
            default    => 'bg-green-100 text-green-700 border-green-200',
        };
    }

    public function getActionIconAttribute(): string
    {
        return match ($this->action) {
            'login'            => 'fa-right-to-bracket',
            'logout'           => 'fa-right-from-bracket',
            'failed_login'     => 'fa-triangle-exclamation',
            'account_locked'   => 'fa-lock',
            'created'          => 'fa-plus',
            'updated'          => 'fa-pen',
            'deleted'          => 'fa-trash',
            'restored'         => 'fa-rotate-left',
            'verified'         => 'fa-check-circle',
            'rejected'         => 'fa-xmark-circle',
            'exported'         => 'fa-file-export',
            'password_changed' => 'fa-key',
            'status_changed'   => 'fa-toggle-on',
            'suspended'        => 'fa-ban',
            default            => 'fa-circle-info',
        };
    }

    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'login'            => 'Login',
            'logout'           => 'Logout',
            'failed_login'     => 'Failed Login',
            'account_locked'   => 'Account Locked',
            'created'          => 'Created',
            'updated'          => 'Updated',
            'deleted'          => 'Deleted',
            'restored'         => 'Restored',
            'verified'         => 'Verified',
            'rejected'         => 'Rejected',
            'exported'         => 'Exported',
            'password_changed' => 'Password Changed',
            'status_changed'   => 'Status Changed',
            'suspended'        => 'Suspended',
            default            => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }

    public function getModuleLabelAttribute(): string
    {
        return match ($this->module) {
            'auth'        => 'Authentication',
            'alumni'      => 'Alumni',
            'organizer'   => 'Organizer',
            'event'       => 'Events',
            'job_posting' => 'Job Postings',
            'user'        => 'Users',
            'system'      => 'System',
            default       => ucfirst($this->module),
        };
    }

    /**
     * Return the created_at timestamp in Philippine Time (Asia/Manila).
     */
    public function getCreatedAtPhAttribute(): Carbon
    {
        return $this->created_at->setTimezone(self::TIMEZONE);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Get dashboard stats — always fresh, no caching to prevent stale totals.
     */
    public static function stats(): array
    {
        $base = static::query();

        return [
            'total'       => (clone $base)->count(),
            'today'       => (clone $base)->today()->count(),
            'flagged'     => (clone $base)->flagged()->count(),
            'critical'    => (clone $base)->bySeverity('critical')->count(),
            'failed_auth' => (clone $base)->byAction('failed_login')->count(),
            'locked'      => (clone $base)->byAction('account_locked')->count(),
        ];
    }
}