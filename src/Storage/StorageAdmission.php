<?php

namespace Sendtrap\Core\Storage;

/**
 * The admission decision carried by a StorageReservation.
 */
enum StorageAdmission: string
{
    /** Bytes fit and (when the implementation tracks operations) are reserved. */
    case Allowed = 'allowed';

    /** The bytes would push the workspace over its storage limit. */
    case Blocked = 'blocked';

    /** The workspace has no storage limit; nothing was reserved or counted. */
    case Unlimited = 'unlimited';

    /**
     * Admission is temporarily paused (reconciliation barrier or
     * initialization in progress). Requeue the work with a short delay —
     * never drop it, never treat it as blocked.
     */
    case Retry = 'retry';
}
