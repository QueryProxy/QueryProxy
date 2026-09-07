<?php

use App\Enums\QueryRequestStatus;
use Illuminate\Support\Facades\Blade;

dataset('ui components', [
    'action' => ['<x-ui.action tone="danger">Delete</x-ui.action>', 'text-danger/80'],
    'alert' => ['<x-ui.alert tone="ok" icon="check">Saved</x-ui.alert>', 'border-ok-line'],
    'avatar' => ['<x-ui.avatar name="Mehmet Şafak" />', 'MŞ'],
    'badge' => ['<x-ui.badge tone="pending">pending</x-ui.badge>', 'bg-pending-bg'],
    'btn' => ['<x-ui.btn variant="primary" icon="check">Approve</x-ui.btn>', 'bg-accent'],
    'btn as link' => ['<x-ui.btn href="/studio">Studio</x-ui.btn>', '<a href="/studio"'],
    'checkbox' => ['<x-ui.checkbox />', 'accent-accent'],
    'chip' => ['<x-ui.chip active>All</x-ui.chip>', 'border-accent'],
    'code' => ['<x-ui.code>SELECT 1;</x-ui.code>', 'font-mono'],
    'dropdown' => ['<x-ui.dropdown><x-slot:trigger>Menu</x-slot:trigger> Item</x-ui.dropdown>', 'x-data'],
    'dropdown item' => ['<x-ui.dropdown-item href="/profile">Profile</x-ui.dropdown-item>', 'hover:bg-raised'],
    'empty cell' => ['<x-ui.empty :colspan="4">No rows.</x-ui.empty>', 'colspan="4"'],
    'empty block' => ['<x-ui.empty>No rows.</x-ui.empty>', 'text-mute-4'],
    'field' => ['<x-ui.field label="Name" error="Required"><x-ui.input /></x-ui.field>', 'text-danger'],
    'icon' => ['<x-ui.icon name="database" />', '<svg'],
    'input' => ['<x-ui.input placeholder="Search" />', 'focus:border-accent'],
    'nav item' => ['<x-ui.nav-item href="/dashboard" icon="dashboard" :active="true" count="4">Dashboard</x-ui.nav-item>', 'aria-current="page"'],
    'page header' => ['<x-ui.page-header title="Audit Log" subtitle="Every event." />', 'font-display'],
    'panel' => ['<x-ui.panel title="Results" padded>Body</x-ui.panel>', 'bg-header'],
    'select' => ['<x-ui.select><option>a</option></x-ui.select>', '<select'],
    'stat' => ['<x-ui.stat label="Pending" value="4" tone="pending" sub="oldest 2 h" />', 'bg-pending'],
    'table' => ['<x-ui.table><x-slot:head><tr><x-ui.th>Req</x-ui.th></tr></x-slot:head> <x-ui.tr><x-ui.td>#1</x-ui.td></x-ui.tr></x-ui.table>', '<tbody>'],
    'textarea' => ['<x-ui.textarea>SELECT 1;</x-ui.textarea>', '<textarea'],
    'theme toggle' => ['<x-ui.theme-toggle />', 'qpTheme.toggle()'],
    'app logo' => ['<x-app-logo />', 'text-accent'],
    'theme script' => ['<x-theme-script />', 'qp-theme'],
]);

it('renders every ui component', function (string $template, string $expected) {
    expect(Blade::render($template))->toContain($expected);
})->with('ui components');

it('maps every query request status to a badge tone', function () {
    foreach (QueryRequestStatus::cases() as $status) {
        expect(Blade::render('<x-ui.badge :tone="$tone">x</x-ui.badge>', ['tone' => $status->tone()]))
            ->toContain('rounded-badge');
    }
});
