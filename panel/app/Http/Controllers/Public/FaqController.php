<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\View\View;

class FaqController extends Controller
{
    public function __invoke(): View
    {
        return view('public.faq', [
            'faqs' => Faq::published()->get()->groupBy('category'),
        ]);
    }
}
