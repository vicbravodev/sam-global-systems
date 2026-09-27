<?php

namespace App\Domains\Context\Enums;

/**
 * Result of fetching one provider media item into storage. `Failed` covers
 * both policy rejections and transport errors: the retrieval is still
 * available at the provider, so a poll must not close the request on it.
 */
enum MediaDownloadOutcome: string
{
    case Stored = 'stored';
    case AlreadyExists = 'already_exists';
    case Failed = 'failed';
}
