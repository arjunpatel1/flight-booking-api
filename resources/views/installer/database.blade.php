@extends('installer.layout')

@section('content')
    <div class="database-container">
        <!-- Title -->
        <h2 class="title">Database Setup</h2>
        <p class="subtitle">Configure your database connection below:</p>

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
        @if (session('db_error'))
            <div class="alert alert-danger">{{ session('db_error') }}</div>
        @endif

        <!-- Form -->
        <form method="POST" action="{{ route('installer.database') }}" id="dbForm">
            @csrf

            <!-- DB Connection -->
            <div class="form-row">
                <div class="form-group">
                    <label for="db_connection">
                        Database Connection <span class="required">*</span>
                    </label>
                    <select name="db_connection" id="db_connection" class="form-control" required>
                        @foreach($connections as $key => $connection)
                            <option value="{{ $key }}" {{ old('db_connection', config('database.default', 'mysql')) === $key ? 'selected' : '' }}>
                                {{ $connection }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Database Host <span class="required">*</span></label>
                    <input type="text" name="db_host" value="{{ old('db_host', config('database.connections.'.config('database.default').'.host', '127.0.0.1')) }}" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Database Port <span class="required">*</span></label>
                    <input type="text" name="db_port" value="{{ old('db_port', config('database.connections.'.config('database.default').'.port', '3306')) }}" required>
                </div>
                <div class="form-group">
                    <label>Database Name <span class="required">*</span></label>
                    <input type="text" name="db_name" value="{{ old('db_name', config('database.connections.'.config('database.default').'.database')) }}" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Database User <span class="required">*</span></label>
                    <input type="text" name="db_user" value="{{ old('db_user', config('database.connections.'.config('database.default').'.username')) }}" required>
                </div>
                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_pass" value="{{ old('db_pass', config('database.connections.'.config('database.default').'.password')) }}">
                </div>
            </div>

            <!-- Options -->
            <div class="checkbox-group">
                <input type="checkbox" name="with_demo" value="1" id="with_demo"
                    {{ old('with_demo') ? 'checked' : '' }}>
                <label for="with_demo">Install with demo data</label>
            </div>

            <!-- Navigation -->
            <div class="nav-buttons">
                <a href="{{ route('installer.requirements') }}" id="backBtn" class="btn-secondary">← Back</a>
                <button type="submit" id="submitBtn" class="btn-primary">Save & Continue →</button>
            </div>
        </form>
    </div>

    <!-- Script to disable submit button after click -->
    @push('scripts')
        <script>
            document.getElementById('dbForm').addEventListener('submit', function () {
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
