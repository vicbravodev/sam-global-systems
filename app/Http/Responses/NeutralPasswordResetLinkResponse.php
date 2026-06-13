<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared neutral response for the "send password reset link" flow.
 *
 * To avoid user enumeration (E4), the same neutral message is returned whether
 * or not the email belongs to an existing account. This class is bound to both
 * the successful and the failed Fortify response contracts so the outcome is
 * indistinguishable from the client's perspective.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    public function toResponse($request): Response
    {
        $message = trans('passwords.neutral');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
