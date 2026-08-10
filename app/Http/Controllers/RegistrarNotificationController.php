<?php
namespace App\Http\Controllers;
use App\Models\RegistrarNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
class RegistrarNotificationController extends Controller
{
    /**
     * GET /registrar/notifications
     * Returns all notifications for the registrar, newest first.
     */
    public function index()
    {
        $items = RegistrarNotification::orderByDesc('created_at')->get();
        return response()->json($items);
    }

    /**
     * POST /registrar/notifications
     *
     * Daily deduplication keyed on title + dedup_key.
     * When a duplicate is found today, increment count + refresh BOTH
     * created_at and updated_at so the panel always shows the latest time.
     *
     * alumni_ids carries the IDs of the alumni this notification is
     * about, so the Alumni Records page can jump to the right page and
     * highlight the matching row(s) when the notif is clicked. On dedup
     * (e.g. multiple imports same day), the new IDs are MERGED into the
     * existing set rather than replacing it.
     *
     * `message` is REBUILT from the merged alumni_ids count rather than
     * overwritten with only the newest occurrence's text — otherwise a
     * grouped notif shows "×2" (or "×6" on bulk import) but the message
     * body still only names the single most-recent alumni, which is
     * confusing and drops the others entirely.
     *
     * ⚠️ Wrapped in a DB transaction with a row lock (lockForUpdate) on
     * the dedup lookup. Two "New Alumni Registered" events firing close
     * together (e.g. staff registering two alumni back-to-back within
     * the same second) could otherwise both read "no existing row yet"
     * at the same time and each INSERT their own row instead of one
     * merging into the other — producing two separate DB rows with the
     * same title/dedup_key/day that the frontend then has to reconcile
     * itself, instead of one properly-merged row from the backend.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'icon'         => ['nullable', 'string', 'max:64'],
            'title'        => ['required', 'string', 'max:255'],
            'message'      => ['required', 'string'],
            'link_route'   => ['nullable', 'string', 'max:255'],
            'link_label'   => ['nullable', 'string', 'max:64'],
            'dedup_key'    => ['nullable', 'string', 'max:64'],
            'alumni_ids'   => ['nullable', 'array'],
            'alumni_ids.*' => ['integer'],
            // Name of the alumni in THIS specific occurrence (the one
            // that triggered this store() call). Used to rebuild the
            // grouped message text. Falls back to parsing `message` if
            // the caller doesn't send it, so older call sites don't break.
            'alumni_name'  => ['nullable', 'string', 'max:255'],
        ]);

        $today    = Carbon::today();
        $dedupKey = $data['dedup_key'] ?? substr($data['message'], 0, 40);
        $newIds   = array_values(array_unique(array_map('intval', $data['alumni_ids'] ?? [])));

        return DB::transaction(function () use ($data, $today, $dedupKey, $newIds) {
            $existing = RegistrarNotification::where('title', $data['title'])
                ->where('dedup_key', $dedupKey)
                ->whereDate('created_at', $today)
                ->latest('updated_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $now = now();

                $existingIds = (array) ($existing->alumni_ids ?? []);
                $mergedIds   = array_values(array_unique(array_merge($existingIds, $newIds)));
                $mergedCount = $existing->count + 1;

                // Bulk Import Complete already carries its own total count in
                // the message text (e.g. "6 alumni record(s) imported..."),
                // built client-side per batch — it isn't a "one alumni per
                // call" case like New Alumni Registered, so instead of trying
                // to extract a name from it, just add this batch's imported
                // total to the running total across all imports today.
                if ($dedupKey === 'imported') {
                    $newMessage = $this->buildImportedMessage($existing->message, $data['message']);
                } else {
                    $newMessage = $this->buildGroupedMessage(
                        $data['message'],
                        $data['alumni_name'] ?? null,
                        $mergedCount,
                        $mergedIds,
                        $existing
                    );
                }

                // ✅ Refresh BOTH created_at and updated_at so the displayed
                //    timestamp always reflects the most recent update.
                $existing->timestamps = false; // disable auto-touch so we set manually
                $existing->update([
                    'message'    => $newMessage,
                    'read'       => false,
                    'count'      => $mergedCount,
                    'icon'       => $data['icon']       ?? $existing->icon,
                    'link_route' => $data['link_route'] ?? $existing->link_route,
                    'link_label' => $data['link_label'] ?? $existing->link_label,
                    'alumni_ids' => $mergedIds,
                    'created_at' => $now, // ✅ this is what the JS panel reads for display
                    'updated_at' => $now,
                ]);

                return response()->json($existing->fresh(), 200);
            }

            // First occurrence for this title + status today → new row.
            $notification = RegistrarNotification::create([
                'icon'       => $data['icon']       ?? 'bell',
                'title'      => $data['title'],
                'message'    => $data['message'],
                'link_route' => $data['link_route'] ?? null,
                'link_label' => $data['link_label'] ?? null,
                'alumni_ids' => $newIds,
                'read'       => false,
                'count'      => 1,
                'dedup_key'  => $dedupKey,
            ]);

            return response()->json($notification, 201);
        });
    }

    /**
     * Builds the message text for a grouped/deduped notification.
     *
     * - 1st occurrence: message is used as-is (handled by the caller,
     *   this only runs from the 2nd occurrence onward).
     * - 2nd+ occurrence: shows every distinct name we actually have, e.g.
     *   "Fernandos Sal Junios Sr. and Loki M. Asgard XIX have been
     *   registered..." when the merged alumni_ids count is small enough
     *   to name everyone, falling back to "...and N others" once there
     *   are more registrants than we have names tracked for, and to the
     *   generic count-only wording if no names came through at all.
     *
     * We now track names on the row itself (via a lightweight in-message
     * parse of every prior occurrence, not just the latest) so a 3rd,
     * 4th, etc. registration keeps ALL earlier names instead of losing
     * them each time this method only saw the newest occurrence's name.
     */
    protected function buildGroupedMessage(
        string $rawMessage,
        ?string $alumniName,
        int $count,
        array $mergedIds,
        RegistrarNotification $existing
    ): string {
        $newName = $alumniName ?: $this->extractNameFromMessage($rawMessage);

        // Recover the names already represented in the existing message so
        // repeated registrations accumulate names instead of overwriting.
        $priorNames = $this->extractAllNamesFromMessage($existing->message);

        $names = $priorNames;
        if ($newName && !in_array($newName, $names, true)) {
            $names[] = $newName;
        }

        if (empty($names)) {
            return $count . ' new alumni registered today.';
        }

        if (count($names) >= $count) {
            $last  = array_pop($names);
            $label = empty($names) ? $last : (implode(', ', $names) . ' and ' . $last);
            return "{$label} have been registered and are now verified.";
        }

        $othersCount = $count - count($names);
        $othersLabel = $othersCount === 1 ? '1 other' : "{$othersCount} others";

        return implode(', ', $names) . " and {$othersLabel} have been registered today.";
    }

    /**
     * Combines two "N alumni record(s) imported successfully via
     * CSV/Excel." messages into one running total, so multiple bulk
     * imports on the same day report a correct combined count instead
     * of the dedup overwriting it with just the latest batch's number.
     */
    protected function buildImportedMessage(string $existingMessage, string $newMessage): string
    {
        $existingCount = $this->extractLeadingNumber($existingMessage) ?? 0;
        $newCount      = $this->extractLeadingNumber($newMessage) ?? 0;
        $total         = $existingCount + $newCount;

        return "{$total} alumni record(s) imported successfully via CSV/Excel.";
    }

    protected function extractLeadingNumber(string $message): ?int
    {
        if (preg_match('/^(\d+)\s/', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Best-effort extraction of an alumni's display name from a message
     * like "Fernandos Sal Junios Sr. (ID: 43646565) has been registered
     * and is now verified." Returns null if the pattern isn't found.
     */
    protected function extractNameFromMessage(string $message): ?string
    {
        if (preg_match('/^(.+?)\s*\(ID:\s*\d+\)/', $message, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Pulls every name already present in a previously-grouped message,
     * covering all three shapes buildGroupedMessage() can produce:
     *   - "Name (ID: 123) has been registered..."               (1st occurrence, raw)
     *   - "A, B and C have been registered..."                  (all names known)
     *   - "A, B and N others have been registered..."           (partial names)
     * Falls back to an empty list if none of these patterns match, so a
     * message we don't recognize just doesn't contribute extra names
     * rather than corrupting the list.
     */
    protected function extractAllNamesFromMessage(string $message): array
    {
        $single = $this->extractNameFromMessage($message);
        if ($single !== null) {
            return [$single];
        }

        if (!preg_match('/^(.*?)\s+have been registered/', $message, $m)) {
            return [];
        }

        $namesPart = trim($m[1]);

        // Strip a trailing "and N other(s)" segment — that's a count, not a name.
        $namesPart = preg_replace('/\s+and\s+\d+\s+others?$/i', '', $namesPart);

        // Remaining pattern is "A, B and C" or a single "A".
        $namesPart = preg_replace('/\s+and\s+(?=[^,]+$)/', ', ', $namesPart);

        if ($namesPart === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $namesPart))));
    }

    /**
     * PATCH /registrar/notifications/{notification}/read
     */
    public function markRead(RegistrarNotification $notification)
    {
        $notification->update(['read' => true]);
        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /registrar/notifications/read-all
     */
    public function markAllRead()
    {
        RegistrarNotification::where('read', false)->update(['read' => true]);
        return response()->json(['ok' => true]);
    }
}