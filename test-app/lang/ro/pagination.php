<?php

// @formatter:off
// phpcs:disable
// phpcs:ignoreFile
// phpstan-ignore-file
// psalm-disable-file

return [
    // 'active' => false, ])->push([ 'url' => $this->nextPageUrl(), 'label' => function_exists('__') ? __(KEY) : 'Next', 'page' => $this->hasMorePages() ? $this->currentPage() + 1 : null, 'active' => false, ]);
    // vendor/laravel/framework/src/Illuminate/Pagination/LengthAwarePaginator.php:135
    'next' => 'Următorul &raquo;',
    // }); })->prepend([ 'url' => $this->previousPageUrl(), 'label' => function_exists('__') ? __(KEY) : 'Previous', 'page' => $this->currentPage() > 1 ? $this->currentPage() - 1 : null, 'active' => false, ])->push([
    // vendor/laravel/framework/src/Illuminate/Pagination/LengthAwarePaginator.php:130
    'previous' => '&laquo; Anterior',
];
