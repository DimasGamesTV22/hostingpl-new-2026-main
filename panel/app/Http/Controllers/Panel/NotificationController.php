<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\PanelNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('panel.notifications', [
            'notifications' => $request->user()->notifications()
                ->orderByDesc('created_at')
                ->paginate(40),
            'unread' => $request->user()->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, PanelNotification $notification): RedirectResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->forceFill(['read_at' => now()])->save();

        return $notification->link
            ? redirect()->to($notification->link)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('success', __('notifications.read_all'));
    }
}
