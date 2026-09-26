<?php

namespace App\View\Components;

use Illuminate\View\Component;

class ImportAnalysisPreview extends Component
{
    public function __construct(
        public array $analysis
    ) {}

    public function render()
    {
        return view('components.import-analysis-preview');
    }
}
