<?php

namespace App\Http\Controllers\Api\V2\Support;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SupportAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Serving support attachments without making them public.
 *
 * The same split as `InvoiceController`, and for the same reason:
 *
 *  - the two `link` methods are token-authenticated and scoped, and mint a
 *    SIGNED, short-lived URL;
 *  - `download` carries no token at all, because a browser or an `<Image>` tag
 *    opened from the app sends no Authorization header. The signature is what
 *    authorises it.
 *
 * The alternative, the `public` disk, is what the four existing uploads in this
 * codebase use. It is a guessable unauthenticated path, which is acceptable for
 * a bus insurance certificate and not acceptable for a photo a customer sent to
 * prove they were charged twice.
 *
 * **A minted link is bearer access for its lifetime.** Anybody the holder
 * forwards it to can open the file until it expires. That is inherent to signed
 * URLs and is why the window is thirty minutes rather than a day.
 */
class SupportAttachmentController extends Controller
{
    /** Long enough to open and save, short enough that a forwarded link dies. */
    private const LINK_TTL_MINUTES = 30;

    /**
     * Mints a link for the client who owns the ticket.
     *
     * Scoped through message -> ticket -> client. `findOrFail($id)` on the
     * attachment alone would hand any signed-in client every screenshot every
     * other customer has ever sent us, by counting upwards.
     */
    public function link(Request $request, string $id)
    {
        /** @var Client $client */
        $client = $request->user();

        $attachment = SupportAttachment::whereHas(
            'message.ticket',
            fn ($q) => $q->where('client_id', $client->id),
        )->findOrFail($id);

        return $this->respondWithLink($attachment);
    }

    /**
     * Mints a link for staff.
     *
     * No scope beyond the `staff` gate on the route: answering a ticket means
     * seeing what was attached to it, and support is not partitioned by agent.
     * The gate is doing the work here, which is why this method is separate from
     * the one above rather than the same method branching on the caller's model.
     */
    public function staffLink(Request $request, string $id)
    {
        return $this->respondWithLink(SupportAttachment::findOrFail($id));
    }

    /**
     * Streams the file.
     *
     * Reachable without a token, with a valid unexpired signature. The route is
     * declared outside every auth group for that reason, see routes/api.php.
     */
    public function download(Request $request, string $attachment)
    {
        $attachment = SupportAttachment::findOrFail($attachment);

        // A row whose file is gone is a 404, not a 500. It happens when a disk
        // is restored from a backup older than the database.
        if (! $attachment->exists()) {
            abort(404, 'Cette pièce jointe n’est plus disponible.');
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->downloadName(),
            // The stored type, not one guessed on the way out. The upload
            // validator already restricted it to four image types.
            ['Content-Type' => $attachment->mime_type],
        );
    }

    private function respondWithLink(SupportAttachment $attachment)
    {
        return response()->json([
            'status' => true,
            'data' => [
                'url' => URL::temporarySignedRoute(
                    'support.attachment',
                    now()->addMinutes(self::LINK_TTL_MINUTES),
                    ['attachment' => $attachment->id],
                ),
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'expires_in' => self::LINK_TTL_MINUTES * 60,
            ],
        ]);
    }
}
