<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\View\View;

class PageController extends Controller
{
    public function show(Page $page): View
    {
        abort_unless($page->is_published, 404);

        return view('public.page', [
            'page' => $page,
        ]);
    }
}
