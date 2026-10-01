<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\AddIncidentComment;
use App\Domains\Incidents\Enums\CommentVisibility;
use App\Domains\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\StoreIncidentCommentRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class IncidentCommentController extends Controller
{
    public function store(
        StoreIncidentCommentRequest $request,
        Team $current_team,
        Incident $incident,
        AddIncidentComment $addComment,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->authorize('comment', $incident);

        $visibility = $request->validated('visibility') !== null
            ? CommentVisibility::from($request->validated('visibility'))
            : CommentVisibility::TenantVisible;

        $comment = $addComment->execute(
            incident: $incident,
            user: $user,
            comment: $request->validated('comment'),
            visibility: $visibility,
        );

        return response()->json(['data' => $comment], 201);
    }
}
