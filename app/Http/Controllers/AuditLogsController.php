<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogsController extends Controller
{
    // ── Middleware ─────────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!Auth::check() || Auth::user()->role !== 'admin') {
                abort(403, 'Unauthorized');
            }
            return $next($request);
        });
    }

    // ── Export CSV ────────────────────────────────────────────────────────────

    /**
     * Stream a CSV export of filtered audit logs.
     * Route: GET /admin/audit-logs/export
     */
    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'module'    => 'nullable|string|max:50',
            'action'    => 'nullable|string|max:50',
            'severity'  => 'nullable|in:info,warning,critical',
            'role'      => 'nullable|string|max:20',
            'flagged'   => 'nullable|boolean',
            'search'    => 'nullable|string|max:100',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        // Log that an export was performed
        AuditLog::log('exported', 'system',
            'Admin ' . Auth::user()->name . ' exported audit logs.',
            ['severity' => 'info']
        );

        $filename = 'audit_logs_' . now()->format('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fputs($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, [
                'ID', 'Date & Time', 'Action', 'Module',
                'User', 'Email', 'Role',
                'Subject', 'Description',
                'IP Address', 'Severity', 'Flagged', 'Flag Reason',
            ]);

            // Stream in chunks to handle large datasets without memory issues
            $this->buildQuery($request)
                ->orderByDesc('id')
                ->chunk(500, function ($logs) use ($handle) {
                    foreach ($logs as $log) {
                        fputcsv($handle, [
                            $log->id,
                            $log->created_at->format('Y-m-d H:i:s'),
                            $log->action_label,
                            $log->module_label,
                            $log->user_name ?? 'System',
                            $log->user_email ?? '—',
                            strtoupper($log->user_role ?? '—'),
                            $log->subject_label ?? '—',
                            $log->description,
                            $log->ip_address ?? '—',
                            strtoupper($log->severity),
                            $log->is_flagged ? 'Yes' : 'No',
                            $log->flag_reason ?? '—',
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ── Flag / Unflag ─────────────────────────────────────────────────────────

    /**
     * Toggle the is_flagged status of an audit log entry.
     * Route: PATCH /admin/audit-logs/{log}/flag
     */
    public function toggleFlag(Request $request, AuditLog $log)
    {
        $wasFlagged = $log->is_flagged;

        $log->update([
            'is_flagged'  => ! $wasFlagged,
            'flag_reason' => $wasFlagged ? null : ($request->input('reason', 'Manually flagged by admin')),
        ]);

        AuditLog::log(
            'updated',
            'system',
            Auth::user()->name . ($wasFlagged ? ' unflagged' : ' flagged') . " audit log #{$log->id}.",
            ['severity' => 'info']
        );

        return response()->json([
            'success'    => true,
            'is_flagged' => $log->fresh()->is_flagged,
            'message'    => $wasFlagged ? 'Log unflagged.' : 'Log flagged.',
        ]);
    }

    // ── Stats JSON ────────────────────────────────────────────────────────────

    /**
     * Return live stats for dashboard widgets.
     * Route: GET /admin/audit-logs/stats
     */
    public function stats(): \Illuminate\Http\JsonResponse
    {
        return response()->json(AuditLog::stats());
    }

    // ── Detail JSON ───────────────────────────────────────────────────────────

    /**
     * Return full detail for a specific log entry (for modal display).
     * Route: GET /admin/audit-logs/{log}
     */
    public function show(AuditLog $log): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'id'            => $log->id,
            'action'        => $log->action,
            'action_label'  => $log->action_label,
            'module'        => $log->module,
            'module_label'  => $log->module_label,
            'user_id'       => $log->user_id,
            'user_name'     => $log->user_name,
            'user_email'    => $log->user_email,
            'user_role'     => $log->user_role,
            'subject_id'    => $log->subject_id,
            'subject_type'  => $log->subject_type,
            'subject_label' => $log->subject_label,
            'old_values'    => $log->old_values,
            'new_values'    => $log->new_values,
            'description'   => $log->description,
            'ip_address'    => $log->ip_address,
            'user_agent'    => $log->user_agent,
            'session_id'    => $log->session_id,
            'severity'      => $log->severity,
            'is_flagged'    => $log->is_flagged,
            'flag_reason'   => $log->flag_reason,
            'created_at'    => $log->created_at->format('F j, Y h:i:s A'),
        ]);
    }

    // ── Query Builder ─────────────────────────────────────────────────────────

    private function buildQuery(Request $request)
    {
        return AuditLog::query()
            ->byModule($request->module)
            ->byAction($request->action)
            ->bySeverity($request->severity)
            ->byRole($request->role)
            ->dateRange($request->date_from, $request->date_to)
            ->search($request->search)
            ->when($request->filled('flagged'), fn ($q) => $q->where('is_flagged', (bool)$request->flagged));
    }
}