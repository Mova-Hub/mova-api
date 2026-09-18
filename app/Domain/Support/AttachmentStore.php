<?php

namespace App\Domain\Support;

use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Puts support attachments somewhere they cannot be read by guessing a URL.
 *
 * One place, used by both the client and the back-office controllers, because
 * the interesting decisions here are all security decisions and two copies of
 * them is one copy that falls behind.
 */
class AttachmentStore
{
    /** Enough for a few screenshots, few enough that one request stays bounded. */
    public const MAX_FILES = 4;

    /** Kilobytes. A modern phone photo is 3 to 5 MB before any compression. */
    public const MAX_KB = 8192;

    /**
     * Images only, named by MIME type rather than by extension.
     *
     * **Not the `image` validation rule.** That rule is implemented with
     * `getimagesize()`, which does not understand HEIC or HEIF, so a photo
     * straight off an iPhone would be refused with a message about the file not
     * being an image. The type is read from the file's CONTENT here, not from
     * the name or the Content-Type header the caller sent, both of which are
     * caller-supplied text.
     *
     * Images and nothing else, deliberately. A support ticket is not a document
     * drop, and accepting arbitrary files means accepting whatever a phone will
     * open when somebody taps it.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return [
            'file',
            'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
            'max:' . self::MAX_KB,
        ];
    }

    /**
     * Stores the uploads and attaches them to a message.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, SupportAttachment>
     */
    public function attach(SupportMessage $message, array $files): array
    {
        $saved = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            /*
             * Everything is read off the upload BEFORE it is stored.
             *
             * `storeAs()` MOVES the temporary file, so `getSize()`,
             * `getMimeType()` and `guessExtension()` all return nonsense, or
             * throw, once it has run. Reading them afterwards would have written
             * a row claiming every attachment was zero bytes.
             */
            $originalName = mb_substr((string) $file->getClientOriginalName(), 0, 255);
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $sizeBytes = (int) $file->getSize();

            /*
             * The NAME is generated here, never derived from the upload.
             *
             * `$file->store()` would do this too, but spelling it out is the
             * point: the client's filename never reaches the filesystem, so
             * neither a `../` nor a second extension can. The extension comes
             * from the guessed MIME type, which the validator has already
             * restricted to four image types.
             */
            $extension = $file->guessExtension() ?: 'bin';
            $name = Str::uuid() . '.' . $extension;

            /*
             * `local`, not `public`.
             *
             * `public` is a symlinked, web-reachable directory with a guessable
             * path and no authentication in front of it. That is fine for the
             * bus documents and provider logos already stored there. It is not
             * fine for a support screenshot, which routinely carries an identity
             * card, a bank SMS or a photo of a ticket with a name on it.
             */
            $path = $file->storeAs(
                'support/' . $message->support_ticket_id,
                $name,
                'local',
            );

            $saved[] = SupportAttachment::create([
                'support_message_id' => $message->id,
                'disk' => 'local',
                'path' => $path,
                // Kept for the download filename only, see the model.
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
            ]);
        }

        return $saved;
    }
}
