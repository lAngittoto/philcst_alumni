<?php

namespace App\Http\Controllers;

use App\Models\OrganizerEvent;
use App\Models\DirectorNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

class OrganizerEventController extends Controller
{
    public function getEvent(int $id): OrganizerEvent
    {
        $org = Auth::user()?->organizer;
        return OrganizerEvent::withTrashed()
            ->where('id', $id)
            ->where('organizer_id', $org?->id)
            ->firstOrFail();
    }

    public function getEventAny(int $id): OrganizerEvent
    {
        return OrganizerEvent::withTrashed()->findOrFail($id);
    }

    public function createEvent(array $data, ?UploadedFile $photo = null): OrganizerEvent
    {
        $org = Auth::user()?->organizer;

        $event = OrganizerEvent::create([
            'organizer_id'        => $org->id,
            'title'               => $data['title'],
            'description'         => $data['description'] ?? null,
            'photo'               => $this->storePhoto($photo),
            'event_date'          => $this->parseDateTime($data['event_date']),
            'event_end_date'      => $data['event_end_date'] ?? null,
            'venue'               => $data['venue'],
            'venue_address'       => $data['venue_address'] ?? null,
            'target_participants' => $data['target_participants'] ?? null,
            'contact_person'      => $data['contact_person'] ?? null,
            'contact_email'       => $data['contact_email'] ?? null,
            'contact_phone'       => $data['contact_phone'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'status'              => 'PENDING',
            'updated_by'          => null,
            'updated_by_role'     => null,
        ]);

        // ── Notify director: new event submitted for review ──
        $this->notifyDirector(
            icon:     'calendar-days',
            title:    'New Event for Review',
            message:  ($org->name ?? 'A coordinator') . ' submitted a new event: "' . $data['title'] . '" — awaiting your approval.',
            dedupKey: 'event-submitted::' . $event->id,
        );

        return $event;
    }

    public function updateEvent(int $id, array $data, ?UploadedFile $photo = null): OrganizerEvent
    {
        $event = $this->getEvent($id);
        $user  = Auth::user();
        $org   = $user?->organizer;

        $isResubmit = isset($data['status']) && $data['status'] === 'PENDING'
            && $event->status === 'REJECTED';

        $newStatus = $event->status === 'APPROVED' ? 'PENDING' : $event->status;

        $updateData = [
            'title'               => $data['title'],
            'description'         => $data['description'] ?? null,
            'event_date'          => $this->parseDateTime($data['event_date']),
            'event_end_date'      => $data['event_end_date'] ?? null,
            'venue'               => $data['venue'],
            'venue_address'       => $data['venue_address'] ?? null,
            'target_participants' => $data['target_participants'] ?? null,
            'contact_person'      => $data['contact_person'] ?? null,
            'contact_email'       => $data['contact_email'] ?? null,
            'contact_phone'       => $data['contact_phone'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'status'              => $newStatus,
            'reviewed_by'         => null,
            'reviewed_at'         => null,
            'review_remarks'      => null,
            'updated_by'          => $user?->name,
            'updated_by_role'     => $user?->role ?? 'organizer',
        ];

        if ($photo) {
            if ($event->photo && $event->photo !== OrganizerEvent::DEFAULT_PHOTO) {
                Storage::disk('public')->delete($event->photo);
            }
            $updateData['photo'] = $this->storePhoto($photo);
        }

        $event->update($updateData);

        // ── Notify director: resubmitted rejected event ──
        if ($isResubmit) {
            $this->notifyDirector(
                icon:     'calendar-days',
                title:    'Event Resubmitted for Review',
                message:  ($org->name ?? 'A coordinator') . ' resubmitted "' . $data['title'] . '" after rejection — awaiting your approval.',
                dedupKey: 'event-resubmitted::' . $event->id . '::' . floor(time() / 300),
            );
        }

        return $event->fresh();
    }

    public function deleteEvent(int $id): void
    {
        $event = $this->getEvent($id);
        $user  = Auth::user();

        $event->update([
            'status'          => 'ORGANIZER_DELETED',
            'deleted_by'      => $user?->name,
            'deleted_by_role' => $user?->role ?? 'organizer',
        ]);

        if ($event->photo && $event->photo !== OrganizerEvent::DEFAULT_PHOTO) {
            Storage::disk('public')->delete($event->photo);
            $event->update(['photo' => null]);
        }

        $event->delete();
    }

    public function approveEvent(int $id, ?string $remarks = null): OrganizerEvent
    {
        $event = $this->getEventAny($id);
        $user  = Auth::user();

        $event->update([
            'status'          => 'APPROVED',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'review_remarks'  => $remarks,
            'updated_by'      => $user?->name,
            'updated_by_role' => 'admin',
        ]);

        return $event->fresh();
    }

    public function rejectEvent(int $id, ?string $remarks = null): OrganizerEvent
    {
        $event = $this->getEventAny($id);
        $user  = Auth::user();

        $event->update([
            'status'          => 'REJECTED',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'review_remarks'  => $remarks,
            'updated_by'      => $user?->name,
            'updated_by_role' => 'admin',
        ]);

        return $event->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Insert a notification row into director_notifications for ALL
    // directors. Uses dedup_key so rapid duplicate submits merge.
    // NOTE: status column in director table is nullable — no status filter.
    // ─────────────────────────────────────────────────────────────────────
    private function notifyDirector(
        string $icon,
        string $title,
        string $message,
        string $dedupKey,
    ): void {
        try {
            // ── Removed ->where('status', 'ACTIVE') because the director
            //    table's status column is nullable (defaults to NULL),
            //    causing zero results and silently dropping all notifications.
            $directorIds = DB::table('director')
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($directorIds as $directorId) {
                DirectorNotification::createOrIncrement(
                    (int) $directorId,
                    [
                        'icon'       => $icon,
                        'title'      => $title,
                        'message'    => $message,
                        'link_route' => 'director.event/management',
                        'link_label' => 'View Events',
                        'dedup_key'  => $dedupKey,
                    ]
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('notifyDirector (event) failed: ' . $e->getMessage());
        }
    }

    private function storePhoto(?UploadedFile $photo): ?string
    {
        if (!$photo) return null;
        return $photo->store('event', 'public');
    }

    private function parseDateTime(string $datetime): string
    {
        try {
            return \Carbon\Carbon::parse($datetime)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return now()->format('Y-m-d H:i:s');
        }
    }
}