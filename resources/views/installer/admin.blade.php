@extends('installer.layout')

@section('content')
    <div class="admin-container">

        <h2 class="title">Create Admin Account</h2>
        <p class="subtitle">Set up the first super admin for NexDine Restaurant POS.</p>

        <!-- Error Messages -->
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('installer.admin') }}" id="adminForm">
            @csrf

            <div class="form-row">
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required>
                </div>

                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" name="email" value="{{ old('email') }}" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <input type="password" name="password" required>
                </div>

                <div class="form-group">
                    <label>Confirm Password <span class="required">*</span></label>
                    <input type="password" name="password_confirmation" required>
                </div>
            </div>

            <!-- Navigation -->
            <div class="nav-buttons">
                <a href="{{ route('installer.database') }}" id="backBtn" class="btn-secondary">← Back</a>
                <button type="submit" id="submitBtn" class="btn-primary">Save & Continue →</button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            document.getElementById('adminForm').addEventListener('submit', function () {
                let submitBtn = document.getElementById('submitBtn');
                let backBtn = document.getElementById('backBtn');

                // Disable both
                submitBtn.disabled = true;
                backBtn.classList.add('disabled');
                backBtn.style.pointerEvents = 'none';
                backBtn.style.opacity = '0.6';

                // Update button text
                submitBtn.innerText = "Please wait...";
            });
        </script>
    @endpush
@endsection
