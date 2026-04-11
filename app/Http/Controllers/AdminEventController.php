<?php

namespace App\Http\Controllers;

use App\Models\AdminEvent;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class AdminEventController extends Controller
{
    
    public function getEvent(int $id): AdminEvent
    {
        return AdminEvent::withTrashed()->findOrFail($id);
    }

    public function getColleges(): array
    {
        return Course::whereNotNull('college')
            ->where('college', '!=', '')
            ->distinct()
            ->orderBy('college')
            ->pluck('college')
            ->toArray();
    }

    public function getEventYears(): array
    {
        return AdminEvent::withTrashed()
            ->selectRaw('YEAR(event_date) as year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();
    }

    public function createEvent(array $data, ?UploadedFile $photo = null): AdminEvent
    {
        return AdminEvent::create([
            'organizer_id'        => null,
            'title'               => $data['title'],
            'description'         => $data['description'] ?? null,
            'photo'               => $this->storePhoto($photo),
            'event_date'          => $this->parseDateTime($data['event_date']),
            'event_end_date'      => isset($data['event_end_date']) && $data['event_end_date']
                                        ? $this->parseDateTime($data['event_end_date'])
                                        : null,
            'venue'               => $data['venue'],
            'venue_address'       => $data['venue_address'] ?? null,
            'target_participants' => $data['target_participants'] ?? null,
            'contact_person'      => $data['contact_person'] ?? null,
            'contact_email'       => $data['contact_email'] ?? null,
            'contact_phone'       => $data['contact_phone'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'status'              => 'APPROVED',
            'reviewed_by'         => Auth::id(),
            'reviewed_at'         => now(),
            'review_remarks'      => 'Posted directly by admin.',
            'updated_by'          => null,
            'updated_by_role'     => null,
        ]);
    }

    public function updateEvent(int $id, array $data, ?UploadedFile $photo = null): AdminEvent
    {
        $event = $this->getEvent($id);
        $user  = Auth::user();

        $updateData = [
            'title'               => $data['title'],
            'description'         => $data['description'] ?? null,
            'event_date'          => $this->parseDateTime($data['event_date']),
            'event_end_date'      => isset($data['event_end_date']) && $data['event_end_date']
                                        ? $this->parseDateTime($data['event_end_date'])
                                        : null,
            'venue'               => $data['venue'],
            'venue_address'       => $data['venue_address'] ?? null,
            'target_participants' => $data['target_participants'] ?? null,
            'contact_person'      => $data['contact_person'] ?? null,
            'contact_email'       => $data['contact_email'] ?? null,
            'contact_phone'       => $data['contact_phone'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'updated_by'          => $user?->name,
            'updated_by_role'     => 'admin',
        ];

        if ($photo) {
            if ($event->photo && $event->photo !== AdminEvent::DEFAULT_PHOTO) {
                Storage::disk('public')->delete($event->photo);
            }
            $updateData['photo'] = $this->storePhoto($photo);
        }

        $event->update($updateData);
        return $event->fresh();
    }

    public function approveEvent(int $id, ?string $remarks = null): AdminEvent
    {
        $event = $this->getEvent($id);

        // If restoring a soft-deleted organizer event, un-delete it
        if ($event->trashed()) {
            $event->restore();
        }

        $event->update([
            'status'          => 'APPROVED',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'review_remarks'  => $remarks,
            'updated_by'      => Auth::user()?->name,
            'updated_by_role' => 'admin',
            'deleted_by'      => null,
            'deleted_by_role' => null,
        ]);

        return $event->fresh();
    }

    public function rejectEvent(int $id, ?string $remarks = null): AdminEvent
    {
        $event = $this->getEvent($id);

        $event->update([
            'status'          => 'REJECTED',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'review_remarks'  => $remarks,
            'updated_by'      => Auth::user()?->name,
            'updated_by_role' => 'admin',
        ]);

        return $event->fresh();
    }

    /**
     * Admin hard-delete: permanently removes the record.
     */
    public function deleteEvent(int $id): void
    {
        $event = $this->getEvent($id);
        $user  = Auth::user();

        $event->update([
            'deleted_by'      => $user?->name,
            'deleted_by_role' => 'admin',
        ]);

        if ($event->photo && $event->photo !== AdminEvent::DEFAULT_PHOTO) {
            Storage::disk('public')->delete($event->photo);
        }

        // forceDelete so it's gone permanently (admin action)
        $event->forceDelete();
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