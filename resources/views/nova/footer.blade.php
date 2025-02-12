<div class="text-center text-sm py-3 flex items-center justify-center space-x-3">
    <span class="font-semibold">{{ config('app.name') }}</span>
    <span class="text-gray-500">v{{ config('app.version') }}</span>
    <span class="bg-gray-100 text-gray-700 px-2 py-1 rounded">{{ strtoupper(config('app.env')) }}</span>
    <span class="text-gray-300">&bull;</span>
    <a href="https://nova.laravel.com"
        class="text-primary hover:text-primary-dark transition-colors duration-150 no-underline">Laravel Nova
        v{{ \Laravel\Nova\Nova::version() }}</a>
    <span class="text-gray-300">&bull;</span>
    <a href="https://laravel.com/"
        class="text-primary hover:text-primary-dark transition-colors duration-150 no-underline">Laravel
        v{{ app()->version() }}</a>
    <span class="text-gray-300">&bull;</span>
    <a href="https://php.net/"
        class="text-primary hover:text-primary-dark transition-colors duration-150 no-underline">PHP
        v{{ phpversion() }}</a>
    <span class="text-gray-300">&bull;</span>
    <span>&copy; {{ date('Y') }}</span>
    <a class="font-bold text-green-600 hover:text-green-700 transition-colors duration-150" target="_blank"
        rel="noopener" href="https://webmapp.it/">WEBMAPP</a>
</div>
