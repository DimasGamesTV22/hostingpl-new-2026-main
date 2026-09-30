<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function __invoke(): View
    {
        return view('public.news', [
            'announcements' => Announcement::published()->paginate(15),
        ]);
    }

    public function show(Announcement $announcement): View
    {
        abort_unless($announcement->is_published, 404);

        return view('public.news-show', [
            'announcement' => $announcement,
        ]);
    }
}
