<?php

declare(strict_types = 1);

use App\Http\Controllers\Helpers\MenuHelper;

$menuArray = MenuHelper::generateNestedMenuArray(); ?>
<nav class="navbar navbar-expand-md navbar-light bg-white shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="{{ url('/') }}">
            {{ config('app.name', 'Laravel') }} - Dynamic Navbar Example
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                @foreach ($menuArray as $name => $route)
                <li class="nav-item">
                    <a class="nav-link" href="{{ $route }}">{{ $name }}</a>
                    @if (isset($menuArray['children']) && count($menu['children']) > 0)
                    <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
                        @foreach ($menuArray['children'] as $child)
                        <li><a class="dropdown-item" href="{{ $child['url'] }}">{{ $child['name'] }}</a></li>
                        @endforeach
                    </ul>
                    @endif
                </li>
                @endforeach
            </ul>
        </div>
    </div>
</nav>