<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexDine Installer</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('logo.svg') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/installer.css') }}">
</head>
<body>
<div class="wizard-container">

    <!-- Progress Steps -->
    <div class="wizard-steps">
        @php
            $steps = [
                'installer.welcome' => ['icon' => 'fas fa-flag', 'label' => 'Welcome'],
                'installer.requirements' => ['icon' => 'fas fa-server', 'label' => 'Requirements'],
                'installer.permissions' => ['icon' => 'fas fa-lock', 'label' => 'Permissions'],
                'installer.database' => ['icon' => 'fas fa-database', 'label' => 'Database'],
                'installer.admin' => ['icon' => 'fas fa-user-shield', 'label' => 'Admin'],
                'installer.finish' => ['icon' => 'fas fa-check-circle', 'label' => 'Finish'],
            ];
            $current = array_search(request()->route()->getName(), array_keys($steps));
        @endphp

        @foreach ($steps as $route => $step)
            @php
                $index = $loop->index;
                $status = $index < $current ? 'completed' : ($index == $current ? 'active' : '');
            @endphp
            <div class="step {{ $status }}">
                <div class="circle"><i class="{{ $step['icon'] }}"></i></div>
                <span>{{ $step['label'] }}</span>
            </div>
        @endforeach
    </div>

    <!-- Page Content -->
    <div class="wizard-card">
        @yield('content')
    </div>
    @stack('scripts')
</div>
</body>
</html>
