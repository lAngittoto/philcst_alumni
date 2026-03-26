<?php

namespace App\Http\Controllers;

use App\Models\OrganizerEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class OrganizerEventController extends Controller
{
    public function getEvent(int $id): OrganizerEvent
    {
        $org = Auth::user()?->organizer;
        // Use withTrashed so organizer can still load their soft-deleted events
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

        return OrganizerEvent::create([
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
    }

    public function updateEvent(int $id, array $data, ?UploadedFile $photo = null): OrganizerEvent
    {
        $event = $this->getEvent($id);
        $user  = Auth::user();

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
        return $event->fresh();
    }

    /**
     * Organizer soft-delete: sets status = ORGANIZER_DELETED first,
     * then soft-deletes so admin can see it via withTrashed().
     */
    public function deleteEvent(int $id): void
    {
        $event = $this->getEvent($id);
        $user  = Auth::user();

        // Mark status BEFORE soft-deleting so admin query can filter by status
        $event->update([
            'status'          => 'ORGANIZER_DELETED',
            'deleted_by'      => $user?->name,
            'deleted_by_role' => $user?->role ?? 'organizer',
        ]);

        // Delete uploaded photo if not the default
        if ($event->photo && $event->photo !== OrganizerEvent::DEFAULT_PHOTO) {
            Storage::disk('public')->delete($event->photo);
            $event->update(['photo' => null]);
        }

        $event->delete(); // SoftDeletes — sets deleted_at
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