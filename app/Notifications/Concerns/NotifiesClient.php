<?php

namespace App\Notifications\Concerns;

use App\Channels\ExpoChannel;
use App\Channels\FcmChannel;

/**
 * Which channels a client notification actually uses.
 *
 * One copy, because the hand written version was already wrong. Every
 * notification repeated the same block, and `ReservationStatusUpdated` repeated
 * the FCM half of it TWICE, so every reservation push was delivered to the
 * handset twice: two banners, two sounds, for one event. It is the sort of
 * defect nobody files a ticket about and everybody notices.
 *
 * The rules, in order:
 *
 *  - **`database` always.** It is what backs the in-app notification list, and
 *    it works for a client who has never opened the app on this device.
 *  - **`mail` when there is an address.** Not every client has one; Mova signs
 *    people up by phone.
 *  - **Push only when there is a token to push to.** Adding the channel with an
 *    empty token list makes the channel do the work of discovering it has
 *    nothing to send, once per notification, and logs a failure for a device
 *    that simply does not exist.
 *
 * Works for `Client` and for `User` alike: both expose `email` and both now
 * carry FCM tokens, so a coordinator and a passenger are notified the same way.
 */
trait NotifiesClient
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'routeNotificationForFcm')
            && ! empty($notifiable->routeNotificationForFcm())) {
            $channels[] = FcmChannel::class;
        }

        if (method_exists($notifiable, 'routeNotificationForExpo')
            && ! empty($notifiable->routeNotificationForExpo())) {
            $channels[] = ExpoChannel::class;
        }

        return $channels;
    }
}
