<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/** The header bell. Every query is limited to the signed-in user's own rows. */
class AppNotificationController extends Controller
{
    use ApiResponse;

    private function mine(Request $r)
    {
        return AppNotification::where('user_id', $r->user()->id);
    }

    public function index(Request $r)
    {
        $q = $this->mine($r)->orderByDesc('created_at')->orderByDesc('id');
        if ($r->boolean('unread')) $q->whereNull('read_at');

        $page = $q->paginate(min((int) $r->query('per_page', 20), 50));
        $items = collect($page->items())->map(fn ($n) => [
            'id' => $n->id, 'type' => $n->type, 'severity' => $n->severity,
            'title' => $n->title, 'body' => $n->body,
            'link_section' => $n->link_section, 'link_id' => $n->link_id,
            'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
        ])->all();

        return $this->paginatedResponse($items, $page, 'Notifications retrieved');
    }

    public function unreadCount(Request $r)
    {
        return $this->successResponse(['count' => $this->mine($r)->whereNull('read_at')->count()]);
    }

    public function markRead(Request $r, int $id)
    {
        $n = $this->mine($r)->findOrFail($id);   // someone else's id → 404
        if (!$n->read_at) $n->update(['read_at' => now()]);
        return $this->successResponse(null, 'Marked as read');
    }

    public function markAllRead(Request $r)
    {
        $this->mine($r)->whereNull('read_at')->update(['read_at' => now()]);
        return $this->successResponse(null, 'All marked as read');
    }
}
