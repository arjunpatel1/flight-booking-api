@extends('installer.layout', ['currentStep' => 'finish'])

@section('content')
    <div class="finish-container">
        <!-- Success Icon -->
        <div class="success-icon">✔</div>

        <!-- Title -->
        <h2 class="title">Installation Complete 🎉</h2>
        <p class="subtitle">
            NexDine Restaurant POS has been installed successfully.<br>
            You can now log in with your admin account and start using the system.
        </p>

        <!-- Actions -->
        <div class="finish-actions">
            <a href="{{ $loginUrl }}" class="btn-primary">Open Admin Login →</a>
        </div>
    </div>
@endsection
