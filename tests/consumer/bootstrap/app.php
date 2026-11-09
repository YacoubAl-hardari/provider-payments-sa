<?php
return Illuminate\Foundation\Application::configure(basePath:dirname(__DIR__))->withRouting(commands:__DIR__.'/../routes/console.php')->withMiddleware(function($middleware){})->withExceptions(function($exceptions){})->create();
