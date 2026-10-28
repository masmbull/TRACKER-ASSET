@props(['title' => '', 'subtitle' => '', 'icon' => null])
@php
    $routeName = request()->route()?->getName() ?? '';
    $routeIcons = [
        'dashboard' => 'dashboard', 'assets.index' => 'inventory', 'assets.qr.sheet' => 'assets.qr.sheet',
        'assets.create' => 'plus', 'assets.edit' => 'inventory', 'assets.transfer' => 'transfer',
        'assets.repair.create' => 'repair', 'assets.audit' => 'activity', 'assets.audit.device' => 'laptop-pulse',
        'agent.fleet' => 'agent.fleet', 'agent.commands' => 'activity', 'agent.index' => 'download',
        'agent.releases' => 'cloud-download', 'agent.fingerprint' => 'inventory', 'agent.promote' => 'plus',
        'documents.index' => 'documents.index', 'documents.create' => 'plus', 'documents.generate' => 'documents.index',
        'templates.index' => 'templates.index', 'templates.create' => 'plus', 'templates.show' => 'templates.index',
        'transfers.index' => 'transfers.index', 'reports.index' => 'reports.index', 'activity.index' => 'activity.index',
        'services.index' => 'services.index', 'users.index' => 'users', 'users.create' => 'plus', 'users.edit' => 'users',
        'users.access' => 'access', 'master.index' => 'master', 'master.create' => 'master', 'master.edit' => 'master',
        'barcode.form' => 'barcode.form', 'ike-bast.inspection.index' => 'ike-bast.inspection.index',
        'ike-bast.inspection.create' => 'ike-bast.inspection.index', 'ike-bast.inspection.show' => 'ike-bast.inspection.index',
        'assets.import' => 'assets.import',
    ];
    $pageIcon = $icon ?? ($routeIcons[$routeName] ?? 'dashboard');
@endphp
<div class="app-page-header animate-fade-up mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex min-w-0 items-center gap-3">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-brand-100 bg-brand-50 text-brand-600 shadow-sm dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-300"><x-icon :name="$pageIcon" class="h-5 w-5" /></span>
        <div class="min-w-0">
        <h1 class="text-title-xs font-bold tracking-tight text-gray-800 dark:text-white">{{ $title }}</h1>
        @if($subtitle)<p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>@endif
        </div>
    </div>
    @if(isset($actions))<div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end xl:px-0">{{ $actions }}</div>@endif
</div>
