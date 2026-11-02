<?php

namespace App\Http\Controllers;

use App\Models\PolicyVersion;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PolicyController extends Controller
{
    private const TYPES = [
        'privacy-policy' => 'privacy',
        'terms' => 'terms',
        'data-retention' => 'retention',
    ];

    public function show(string $slug): View
    {
        abort_unless(isset(self::TYPES[$slug]), 404);

        $policy = PolicyVersion::current(self::TYPES[$slug]);
        abort_if($policy === null, 404);

        return view('policies.show', [
            'policy' => $policy,
            // Policy text is authored by staff; still strip raw HTML and unsafe links.
            'html' => Str::markdown($policy->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }
}
