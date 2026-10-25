<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkflowNotificationController extends Controller
{
    private function own(Request $request)
    {
        return DB::table('hrm_notifications')->where('user_id', $request->user()->id)->where('in_app', true)
            ->whereIn('company_id', DB::table('company_user_roles')->where('user_id', $request->user()->id)->select('company_id'));
    }

    public function index(Request $request): JsonResponse
    {
        if (!config('hrm.workflow_notifications_enabled', false)) {
            return response()->json(['data' => [], 'meta' => ['enabled' => false, 'unread' => 0, 'last_page' => 1]]);
        }
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $base = $this->own($request);
        $unread = (clone $base)->whereNull('read_at')->count();
        $page = $base->orderByDesc('created_at')->orderBy('id')->paginate(25,
            ['id', 'event', 'created_at', 'read_at', 'correlation_id']);
        return response()->json(['data' => $page->items(), 'meta' => ['enabled' => true,
            'unread' => $unread, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        abort_unless(config('hrm.workflow_notifications_enabled', false), 404);
        $query = $this->own($request)->where('id', $notification);
        abort_unless((clone $query)->exists(), 404);
        $query->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['id' => $notification, 'read' => true]]);
    }
}
